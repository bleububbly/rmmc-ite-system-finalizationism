<?php
require __DIR__ . '/app/services.php';
$u=require_login();$staff=in_array($u['role'],['Admin','Officer'],true);$isAdmin=$u['role']==='Admin';
if(request_post()) {
    check_post();if(!$staff) fail('Staff access required.',403);
    $action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);
    if($action==='finalize'){
        try{$number=finalize_event($id,(int)$u['id']);flash("Attendance finalized. $number new checkpoint fine(s) assessed (₱20 per missed required checkpoint).");}catch(Throwable $e){friendly_error($e,'Unable to finalize event: '.$e->getMessage());}
        go('events.php');
    }
    if($action==='set_status'){
        $target=(string)($_POST['status']??'');
        $event=one('SELECT id,status FROM events WHERE id=?',[$id]);
        if(!$event) fail('Event not found.',404);
        $steps=['draft'=>['published','ongoing'],'published'=>['ongoing','completed'],'ongoing'=>['completed'],'completed'=>[],'cancelled'=>[]];
        if(!in_array($target,$steps[$event['status']]??[],true)) {flash('Invalid status transition. Use Draft → Published → Ongoing → Completed.','error');go('events.php');}
        if($target==='completed' && (int)(one('SELECT (ends_at<=NOW()) AS ended FROM events WHERE id=?',[$id])['ended']??0)!==1) {
            flash('Wait until the scheduled event end time before marking it Completed.','error');
            go('events.php');
        }
        try {
            db()->beginTransaction();
            run_sql('UPDATE events SET status=? WHERE id=?',[$target,$id]);
            sync_participants($id);
            audit('event_status_changed','events',$id,['from'=>$event['status'],'to'=>$target]);
            db()->commit();
            if ($target === 'completed') {
                // Automatically assess missed required checkpoints when an event is completed.
                // Re-running Events -> Finalize is safe because each fine has a unique checkpoint.
                try {
                    $added = finalize_event($id, (int)$u['id']);
                    flash('Event completed. '.$added.' new ₱20 checkpoint fine(s) assessed.');
                } catch (Throwable $fineError) {
                    error_log('Checkpoint assessment pending for event '.$id.': '.$fineError->getMessage());
                    flash('Event marked Completed, but fine assessment still needs attention. Click Finalize after all checkpoint windows have ended.', 'warning');
                }
            } else {
                flash('Event status changed to '.ucfirst($target).'.');
            }
        } catch(Throwable $e) {if(db()->inTransaction())db()->rollBack();friendly_error($e,'Could not update event status.');}
        go('events.php');
    }
    if($action==='cancel'){
        $existing=one('SELECT id,status FROM events WHERE id=?',[$id]);if(!$existing) fail('Event not found.',404);
        if(one('SELECT f.id FROM fines f JOIN event_participants ep ON ep.id=f.event_participant_id WHERE ep.event_id=? LIMIT 1',[$id])){flash('This event already has fines. Do not cancel without reconciliation.','error');go('events.php');}
        run_sql("UPDATE events SET status='cancelled' WHERE id=?",[$id]);audit('event_cancelled','events',$id);flash('Event cancelled; history retained.');go('events.php');
    }
    if($action==='delete') {
        if (!$isAdmin) fail('Only Admin can permanently delete an event.',403);
        try {
            db()->beginTransaction();
            $event = one('SELECT id,title,status FROM events WHERE id=? FOR UPDATE',[$id]);
            if (!$event) throw new RuntimeException('Event not found.');
            $typedTitle = trim((string)($_POST['confirm_title']??''));
            if ($typedTitle === '' || !hash_equals((string)$event['title'],$typedTitle)) {
                throw new RuntimeException('Event title confirmation does not match.');
            }

            // Never destroy existing financial or attendance history.
            $hasFines = one('SELECT f.id FROM fines f JOIN event_participants ep ON ep.id=f.event_participant_id WHERE ep.event_id=? LIMIT 1',[$id]);
            $hasAttendance = one('SELECT a.id FROM attendance a JOIN event_participants ep ON ep.id=a.event_participant_id WHERE ep.event_id=? LIMIT 1',[$id]);
            $hasScans = one('SELECT ac.id FROM attendance_scans ac JOIN event_participants ep ON ep.id=ac.event_participant_id WHERE ep.event_id=? LIMIT 1',[$id]);
            $hasResolved = one("SELECT ep.id FROM event_participants ep WHERE ep.event_id=? AND ep.attendance_status <> 'pending' LIMIT 1",[$id]);
            if ($hasFines || $hasAttendance || $hasScans || $hasResolved) {
                throw new RuntimeException('This event has recorded attendance or fines. Its history must be preserved; use Cancel instead.');
            }
            // Registered-but-unscanned participants are not historical attendance entries.
            run_sql('DELETE FROM event_attendance_slots WHERE event_id=?',[$id]);
            run_sql('DELETE FROM event_participants WHERE event_id=?',[$id]);
            run_sql('DELETE FROM events WHERE id=?',[$id]);
            audit('event_permanently_deleted','events',$id,['title'=>$event['title'],'previous_status'=>$event['status']]);
            db()->commit();
            flash('Event permanently deleted. No attendance or fine history was removed.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            friendly_error($e,'Unable to delete event: '.$e->getMessage());
        }
        go('events.php');
    }
    if($action==='save') {
        $title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));$venue=trim((string)($_POST['venue']??''));
        $sem=(int)($_POST['semester_id']??0);$starts=sql_datetime((string)($_POST['starts_at']??''));$ends=sql_datetime((string)($_POST['ends_at']??''));
        $status=(string)($_POST['status']??'draft');$fine=20.00;
        $errors=[];
        if($title==='' || strlen($title)>200)$errors[]='Title is required (200 characters maximum).';
        if(!good_datetime($starts)||!good_datetime($ends)||$ends<=$starts)$errors[]='Provide valid start and end dates in chronological order.';
        if(!in_array($status,['draft','published','ongoing','completed','cancelled'],true))$errors[]='Invalid event status.';
        if(!one('SELECT id FROM semesters WHERE id=?',[$sem]))$errors[]='Select a valid semester.';
        $slots=[];
        foreach(SLOTS as $slot=>$label) {
            if(!isset($_POST['enabled'][$slot]))continue;
            $opens=sql_datetime((string)($_POST['opens'][$slot]??''));$closes=sql_datetime((string)($_POST['closes'][$slot]??''));
            // Helpful two-checkpoint defaults if the user only sets event start/end.
            if(good_datetime($starts)&&good_datetime($ends)) {
                $startTime=strtotime($starts);$endTime=strtotime($ends);
                if($slot==='morning_in') {if(!good_datetime($opens))$opens=$starts;if(!good_datetime($closes))$closes=date('Y-m-d H:i:s',min($startTime+3600,$endTime));}
                if($slot==='afternoon_out') {if(!good_datetime($opens))$opens=date('Y-m-d H:i:s',max($startTime,$endTime-3600));if(!good_datetime($closes))$closes=$ends;}
            }
            if(!good_datetime($opens)||!good_datetime($closes)||$closes<=$opens) $errors[]="$label needs a valid opening and closing time.";
            else $slots[$slot]=[$opens,$closes];
        }
        if(!$slots)$errors[]='Enable at least one QR attendance checkpoint.';
        if($errors){flash(implode(' ',$errors),'error');go('events.php'.($id?'?edit='.$id:'?new=1'));}
        try{
            db()->beginTransaction();
            if($id){
                if(one('SELECT f.id FROM fines f JOIN event_participants ep ON ep.id=f.event_participant_id WHERE ep.event_id=? LIMIT 1',[$id]))throw new RuntimeException('This event already has assessed fines; edit it only after records have been formally reconciled.');
                $old=one('SELECT id FROM events WHERE id=? FOR UPDATE',[$id]);if(!$old)throw new RuntimeException('Event no longer exists.');
                $scanned=(int)(one('SELECT COUNT(*) n FROM attendance_scans a JOIN event_participants ep ON ep.id=a.event_participant_id WHERE ep.event_id=?',[$id])['n']??0);
                if($scanned)throw new RuntimeException('This event already has scans; its schedule cannot be edited. Create a new event for a different schedule.');
                run_sql('UPDATE events SET semester_id=?,title=?,description=?,venue=?,starts_at=?,ends_at=?,fine_amount=?,status=? WHERE id=?',[$sem,$title,$description?:null,$venue?:null,$starts,$ends,$fine,$status,$id]);
                run_sql('DELETE FROM event_attendance_slots WHERE event_id=?',[$id]);
            }else{
                run_sql('INSERT INTO events(semester_id,title,description,venue,starts_at,ends_at,fine_amount,status,created_by,require_time_out) VALUES(?,?,?,?,?,?,?,?,?,1)',[$sem,$title,$description?:null,$venue?:null,$starts,$ends,$fine,$status,$u['id']]);
                $id=(int)db()->lastInsertId();
            }
            foreach($slots as $slot=>$range)run_sql('INSERT INTO event_attendance_slots(event_id,slot,opens_at,closes_at,required) VALUES(?,?,?,?,1)',[$id,$slot,$range[0],$range[1]]);
            sync_participants($id);
            audit('event_saved','events',$id,['title'=>$title,'status'=>$status]);
            db()->commit();flash('Event and attendance checkpoints saved.');go('events.php');
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();friendly_error($e,'Unable to save event: '.$e->getMessage());go('events.php'.($id?'?edit='.$id:'?new=1'));}
    }
    fail('Unsupported event action.');
}
$semesters=all('SELECT id,academic_year,semester_number,status FROM semesters ORDER BY start_date DESC');
$editId=(int)($_GET['edit']??0);$edit=($staff&&$editId)?one('SELECT * FROM events WHERE id=?',[$editId]):null;
$slotSaved=$edit?all('SELECT * FROM event_attendance_slots WHERE event_id=?',[$editId]):[];$slotMap=[];foreach($slotSaved as $r)$slotMap[$r['slot']]=$r;
$eventList=$staff?all('SELECT e.*,s.academic_year,s.semester_number,(SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id=e.id) participants FROM events e JOIN semesters s ON s.id=e.semester_id ORDER BY e.starts_at DESC LIMIT 100'):all("SELECT e.*,s.academic_year,s.semester_number,(SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id=e.id) participants FROM events e JOIN semesters s ON s.id=e.semester_id WHERE e.status IN ('published','ongoing','completed') ORDER BY e.starts_at DESC LIMIT 100");
page_header('Events',$u);title_block('Events Management',$staff?'Create, publish, update and finalize student events.':'View events, schedules and your required attendance.',$staff?'<a class="btn btn-primary" href="'.h(app_path('events.php?new=1')).'">+ Create Event</a>':null);
if($staff && (isset($_GET['new']) || $edit)):
?><section class="panel"><h2><?= $edit?'Update Event':'Create Event' ?></h2><?php if(!$semesters):?><div class="alert alert-warning">Create an academic semester first. <?php if($isAdmin):?><a href="<?=h(app_path('semesters.php'))?>">Manage semesters</a><?php else: ?>Ask an Admin to configure a semester.<?php endif;?></div><?php endif;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($edit['id']??0)?>"><div class="form-grid wide"><label>Event Title<input name="title" maxlength="200" value="<?=h($edit['title']??'')?>" required></label><label>Semester<select name="semester_id" required><?php foreach($semesters as $s):?><option value="<?=h($s['id'])?>" <?= (int)$s['id']===(int)($edit['semester_id']??current_semester()['id']??0)?'selected':''?>><?=h($s['academic_year'].' · Semester '.$s['semester_number'].' · '.$s['status'])?></option><?php endforeach;?></select></label><label>Venue<input name="venue" maxlength="200" value="<?=h($edit['venue']??'')?>"></label><label>Start<input type="datetime-local" name="starts_at" value="<?=h(datetime_input($edit['starts_at']??null))?>" required></label><label>End<input type="datetime-local" name="ends_at" value="<?=h(datetime_input($edit['ends_at']??null))?>" required></label><label>Status<select name="status"><?php foreach(['draft','published','ongoing','completed','cancelled'] as $v):?><option value="<?=$v?>" <?=$v===($edit['status']??'draft')?'selected':''?>><?=ucfirst($v)?></option><?php endforeach;?></select></label></div><label style="margin-top:13px">Description<textarea name="description" rows="2" maxlength="3000"><?=h($edit['description']??'')?></textarea></label><h3 style="margin-top:23px">QR Checkpoint Schedule</h3><p class="hint">Enable the checkpoints your event requires. Students must scan each enabled slot once during its assigned window. Time settings use Philippine local time.</p><div class="slot-grid"><?php foreach(SLOTS as $slot=>$label): $saved=$slotMap[$slot]??null;?><div class="slot-card"><label class="checkline"><input type="checkbox" name="enabled[<?=h($slot)?>]" value="1" <?=($saved || (!$edit && in_array($slot,['morning_in','afternoon_out'],true)))?'checked':''?>><strong><?=h($label)?></strong></label><label>Opens<input type="datetime-local" name="opens[<?=h($slot)?>]" value="<?=h(datetime_input($saved['opens_at']??null))?>"></label><label>Closes<input type="datetime-local" name="closes[<?=h($slot)?>]" value="<?=h(datetime_input($saved['closes_at']??null))?>"></label></div><?php endforeach;?></div><p class="hint">For a 7:30 AM–5 PM event, for example, set Morning In 7:30–9:00, Morning Out 11:00–12:00, Afternoon In 12:30–2:00, Afternoon Out 4:00–5:00.</p><div class="row" style="margin-top:17px"><button class="btn btn-primary" <?=!$semesters?'disabled':''?>>Save Event</button><a class="btn btn-ghost" href="<?=h(app_path('events.php'))?>">Cancel</a></div></form></section>
<?php endif; ?>
<section class="panel"><h2><?= $staff?'All Events':'Department Events' ?></h2><div class="table-scroll"><table class="table"><thead><tr><th>Title</th><th>Date & Time</th><th>Venue</th><th>Semester</th><th>Participants</th><th>Status</th><?php if($staff):?><th>Actions</th><?php endif;?></tr></thead><tbody><?php foreach($eventList as $e):?><tr><td><strong><?=h($e['title'])?></strong><div class="meta-small"><?=h((strlen($e['description']??'')>75?substr($e['description'],0,72).'...':($e['description']??'')))?></div></td><td><?=h(substr($e['starts_at'],0,16))?></td><td><?=h($e['venue']??'—')?></td><td><?=h($e['academic_year'].' · '.$e['semester_number'])?></td><td><?=h($e['participants'])?></td><td><?=status_badge($e['status'])?></td><?php if($staff):?><td class="actions"><a class="btn btn-ghost btn-sm" href="<?=h(app_path('attendance.php?event_id='.$e['id']))?>">Attendance</a><?php if(in_array($e['status'],['draft','published','ongoing'],true)):?><form method="post" class="row"><?=csrf_field()?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?=h($e['id'])?>"><select name="status" style="width:130px;margin:0"><?php $transitions=['draft'=>['published','ongoing'],'published'=>['ongoing','completed'],'ongoing'=>['completed']];foreach($transitions[$e['status']]??[] as $v):?><option value="<?=$v?>"><?=ucfirst($v)?></option><?php endforeach;?></select><button class="btn btn-primary btn-sm" data-confirm="Apply this event status?">Set Status</button></form><?php endif;?><a class="btn btn-soft btn-sm" href="<?=h(app_path('events.php?edit='.$e['id']))?>">Edit</a><?php if($e['status']==='completed'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="finalize"><input type="hidden" name="id" value="<?=h($e['id'])?>"><button class="btn btn-primary btn-sm" data-confirm="Assess ₱20 per missing required In / Out checkpoint? This will not change historical fines.">Finalize</button></form><?php endif;?><?php if(!in_array($e['status'],['cancelled','completed'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?=h($e['id'])?>"><button class="btn btn-ghost btn-sm" data-confirm="Cancel this event?">Cancel</button></form><?php endif;?><?php if($isAdmin):?><form method="post" class="row" onsubmit="return confirm('Permanently delete this event? Events with attendance or fines cannot be deleted.');"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=h($e['id'])?>"><input type="hidden" name="confirm_title" value="<?=h($e['title'])?>"><button type="submit" class="btn btn-danger btn-sm" title="Delete permanently (only if no attendance or fine history)">Delete</button></form><?php endif;?></td><?php endif;?></tr><?php endforeach;?></tbody></table></div><?php if(!$eventList):?><p class="empty">No events yet. Staff can create an event once a semester has been configured.</p><?php endif;?></section><?php page_footer();
