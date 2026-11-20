<?php
require __DIR__ . '/app/core.php';$u=require_role('Admin');
if(request_post()){
    check_post();$action=(string)($_POST['action']??'');
    if($action==='create'){
        $year=trim((string)($_POST['academic_year']??''));$number=(int)($_POST['semester_number']??0);$start=(string)($_POST['start_date']??'');$end=(string)($_POST['end_date']??'');
        if(!preg_match('/^\d{4}-\d{4}$/',$year)||!in_array($number,[1,2,3],true)||!preg_match('/^\d{4}-\d\d-\d\d$/',$start)||!preg_match('/^\d{4}-\d\d-\d\d$/',$end)||$start>=$end){flash('Enter a valid academic year, semester, and date range.','error');go('semesters.php');}
        try{run_sql("INSERT INTO semesters(academic_year,semester_number,start_date,end_date,status) VALUES(?,?,?,?,'upcoming')",[$year,$number,$start,$end]);audit('semester_created','semesters',(int)db()->lastInsertId());flash('Semester created.');}catch(Throwable $e){friendly_error($e,'Unable to create semester. It may already exist.');}go('semesters.php');
    }
    if($action==='activate'){
        $id=(int)($_POST['id']??0);if(!one('SELECT id FROM semesters WHERE id=?',[$id]))fail('Unknown semester.');
        try{db()->beginTransaction();run_sql("UPDATE semesters SET status='completed' WHERE status='active' AND id<>?",[$id]);run_sql("UPDATE semesters SET status='active' WHERE id=?",[$id]);audit('semester_activated','semesters',$id);db()->commit();flash('Semester activated.');}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();friendly_error($e,'Could not activate semester.');}go('semesters.php');
    }
    fail('Unsupported action');
}
$semesters=all('SELECT * FROM semesters ORDER BY start_date DESC');page_header('Semesters',$u);title_block('Academic Semesters','Manage academic years and active clearance periods.');
?><div class="grid-2"><section class="panel"><h2>Add Semester</h2><form method="post" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="create"><label>Academic Year<input name="academic_year" placeholder="2026-2027" pattern="[0-9]{4}-[0-9]{4}" required></label><label>Semester<select name="semester_number"><option value="1">First Semester</option><option value="2">Second Semester</option><option value="3">Summer</option></select></label><label>Start date<input type="date" name="start_date" required></label><label>End date<input type="date" name="end_date" required></label><button class="btn btn-primary">Create Semester</button></form></section><section class="panel"><h2>Configured Semesters</h2><div class="table-scroll"><table class="table"><thead><tr><th>Academic Year</th><th>Term</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($semesters as $s):?><tr><td><?=h($s['academic_year'])?></td><td>Semester <?=h($s['semester_number'])?></td><td><?=status_badge($s['status'])?></td><td><?php if($s['status']!=='active'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?=h($s['id'])?>"><button class="btn btn-soft btn-sm" data-confirm="Activate this semester?">Activate</button></form><?php else: ?><span class="hint">Current</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if(!$semesters):?><p class="empty">Create a semester before managing events.</p><?php endif;?></section></div><?php page_footer();
