<?php
require __DIR__ . '/app/services.php';$u=require_login();$staff=in_array($u['role'],['Admin','Officer'],true);
$semesters=all('SELECT * FROM semesters ORDER BY start_date DESC');$active=current_semester();$semesterId=(int)($_GET['semester_id']??($active['id']??0));$selected=$semesterId?one('SELECT * FROM semesters WHERE id=?',[$semesterId]):null;
$studentSearch = trim((string)($_GET['q'] ?? ''));
$studentSearch = substr($studentSearch, 0, 100);
if(request_post()){
    check_post();if(!$staff)fail('Staff access required.',403);
    $studentId=(int)($_POST['student_id']??0);$sem=(int)($_POST['semester_id']??0);$action=(string)($_POST['action']??'');$note=trim((string)($_POST['remarks']??''));
    if(!one('SELECT id FROM students WHERE id=?',[$studentId]) || !one('SELECT id FROM semesters WHERE id=?',[$sem]))fail('Student or semester not found.');
    if(!in_array($action,['clear','revoke'],true))fail('Invalid clearance action.');
    try{
        db()->beginTransaction();
        if($action==='clear'){
            $issues=clearance_blockers($studentId,$sem);
            if($issues)throw new RuntimeException(implode(' ',$issues));
            run_sql("INSERT INTO clearances(student_id,semester_id,status,cleared_by,cleared_at,remarks) VALUES(?,?,'cleared',?,NOW(),?) ON DUPLICATE KEY UPDATE status='cleared',cleared_by=VALUES(cleared_by),cleared_at=VALUES(cleared_at),remarks=VALUES(remarks)",[$studentId,$sem,$u['id'],$note?:null]);
        }else{
            if($note==='')throw new RuntimeException('A reason is required to revoke clearance.');
            run_sql("INSERT INTO clearances(student_id,semester_id,status,remarks) VALUES(?,?,'revoked',?) ON DUPLICATE KEY UPDATE status='revoked',cleared_by=NULL,cleared_at=NULL,remarks=VALUES(remarks)",[$studentId,$sem,$note]);
        }
        audit('clearance_'.$action,'clearances',$studentId,['semester_id'=>$sem,'remarks'=>$note]);db()->commit();flash($action==='clear'?'Student cleared for the semester.':'Clearance revoked.');go('clearance.php?semester_id='.$sem);
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();friendly_error($e,'Clearance update blocked: '.$e->getMessage());go('clearance.php?semester_id='.$sem);}
}
page_header('Semester Clearance',$u);title_block($staff?'Semester Clearance Management':'My Clearance',$staff?'Verify attendance and settled fines before approving student clearances.':'Check your semester clearance and outstanding requirements.');
if(!$selected):?><div class="alert alert-warning">No semester configured. An Admin must create and activate a semester before clearance can be processed.</div><?php else: ?>
<section class="panel"><form method="get" class="row"><?php if($staff && $studentSearch!==''):?><input type="hidden" name="q" value="<?=h($studentSearch)?>"><?php endif;?><label style="min-width:220px">Semester<select name="semester_id" onchange="this.form.submit()"><?php foreach($semesters as $s):?><option value="<?=h($s['id'])?>" <?=$semesterId===(int)$s['id']?'selected':''?>><?=h($s['academic_year'].' · Semester '.$s['semester_number'])?></option><?php endforeach;?></select></label><button class="btn btn-ghost">Load</button></form></section>
<?php if(!$staff):$st=mine();$studentId=(int)($st['id']??0);$record=$studentId?one('SELECT * FROM clearances WHERE student_id=? AND semester_id=?',[$studentId,$semesterId]):null;$issues=$studentId?clearance_blockers($studentId,$semesterId):['Student profile missing']; ?>
<div class="cards"><div class="card"><div class="kicker">Academic Year</div><div class="metric" style="font-size:20px"><?=h($selected['academic_year'])?></div></div><div class="card"><div class="kicker">Clearance Status</div><div style="margin-top:12px"><?=status_badge($record['status']??'pending')?></div></div><div class="card"><div class="kicker">Outstanding Fines</div><div class="metric accent"><?=pesos($studentId?outstanding($studentId,$semesterId):0)?></div></div><div class="card"><div class="kicker">Remaining Requirements</div><div class="metric"><?=count($issues)?></div></div></div>
<section class="panel"><h2>Clearance Requirements</h2><?php if(!$issues):?><div class="alert">Attendance and payments currently meet automated clearance requirements. Officer approval is still required.</div><?php else: foreach($issues as $issue):?><p class="alert alert-warning"><?=h($issue)?></p><?php endforeach;endif;?><p>Current status: <?=status_badge($record['status']??'pending')?></p><?php if($record && $record['remarks']):?><p class="hint">Officer notes: <?=h($record['remarks'])?></p><?php endif;?></section>
<?php else: ?>
<section class="panel"><h2>Student Clearance List</h2>
<form method="get" class="row" style="margin-bottom:18px;align-items:flex-end">
<input type="hidden" name="semester_id" value="<?=h($semesterId)?>">
<label style="min-width:260px;flex:1;max-width:490px">Search Student ID or Last Name
<input type="search" name="q" value="<?=h($studentSearch)?>" maxlength="100" placeholder="e.g., 2411600165 or Begonte" autocomplete="off"></label>
<button class="btn btn-primary" type="submit">Search</button>
<?php if($studentSearch!==''):?><a class="btn btn-ghost" href="<?=h(app_path('clearance.php?semester_id='.$semesterId))?>">Show All</a><?php endif;?>
</form>
<?php
$clearanceSql='SELECT s.id,s.student_number,s.first_name,s.last_name,s.program,s.year_level,c.status,c.remarks
    FROM students s JOIN users u ON u.id=s.user_id
    LEFT JOIN clearances c ON c.student_id=s.id AND c.semester_id=? WHERE u.is_active=1';
$clearanceParams=[$semesterId];
if($studentSearch!=='') {
    $clearanceSql.=' AND (s.student_number LIKE ? OR s.last_name LIKE ?)';
    $clearanceParams[]='%'.$studentSearch.'%';
    $clearanceParams[]='%'.$studentSearch.'%';
}
$clearanceSql.=' ORDER BY s.last_name,s.first_name LIMIT 400';
$students=all($clearanceSql,$clearanceParams);
?>
<p class="hint"><?=count($students)?> student(s) shown<?= $studentSearch!=='' ? ' for search: '.h($studentSearch) : '' ?>.</p>
<div class="table-scroll"><table class="table"><thead><tr><th>Student</th><th>Course / Year</th><th>Fine Balance</th><th>Requirements</th><th>Clearance</th><th>Action</th></tr></thead><tbody><?php foreach($students as $s):$issues=clearance_blockers((int)$s['id'],$semesterId);?><tr><td><a href="<?=h(app_path('student.php?id='.$s['id']))?>"><?=h($s['student_number'].' · '.$s['last_name'].', '.$s['first_name'])?></a></td><td><?=h($s['program'].' / Year '.$s['year_level'])?></td><td><?=pesos(outstanding((int)$s['id'],$semesterId))?></td><td><?= $issues ? '<span class="badge badge-blocked">'.count($issues).' blocker(s)</span>' : '<span class="badge badge-cleared">Eligible</span>' ?><div class="meta-small"><?=h(implode(' ',array_slice($issues,0,2)))?></div></td><td><?=status_badge($s['status']??'pending')?></td><td><?php if($s['status']!=='cleared'):?><form method="post" class="row"><?=csrf_field()?><input type="hidden" name="action" value="clear"><input type="hidden" name="student_id" value="<?=h($s['id'])?>"><input type="hidden" name="semester_id" value="<?=h($semesterId)?>"><button class="btn btn-primary btn-sm" <?=$issues?'disabled title="Resolve blockers before approving"':''?> data-confirm="Approve clearance for this student?">Clear</button></form><?php else: ?><form method="post" class="row"><?=csrf_field()?><input type="hidden" name="action" value="revoke"><input type="hidden" name="student_id" value="<?=h($s['id'])?>"><input type="hidden" name="semester_id" value="<?=h($semesterId)?>"><input name="remarks" required placeholder="Revocation reason" maxlength="500" style="min-width:125px;width:135px"><button class="btn btn-ghost btn-sm" data-confirm="Revoke this semester's clearance?">Revoke</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if(!$students):?><p class="empty"><?= $studentSearch!=='' ? 'No matching students. Try a different Student ID or last name.' : 'No students to process.' ?></p><?php endif;?></section>
<?php endif;endif;page_footer();
