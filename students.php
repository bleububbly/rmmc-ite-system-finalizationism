<?php
require __DIR__ . '/app/services.php';
$u=require_role('Admin','Officer');$admin=$u['role']==='Admin';$editId=max(0,(int)($_GET['edit']??0));$editing=$editId?one('SELECT s.*,u.is_active FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?',[$editId]):null;
if(request_post()){
    check_post();if(!$admin) fail('Only Admin can change student records.',403);
    $action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);
    if($action==='rotate_qr') {
        $rec=one('SELECT id,student_number FROM students WHERE id=?',[$id]); if(!$rec)fail('Student not found',404);
        run_sql('UPDATE students SET qr_token=? WHERE id=?',[bin2hex(random_bytes(32)),$id]);audit('student_qr_rotated','students',$id);flash('Student QR token regenerated. The previously printed QR code no longer works.');go('student.php?id='.$id);
    }
    if($action==='toggle') {
        $rec=one('SELECT user_id FROM students WHERE id=?',[$id]);if(!$rec) fail('Student not found',404);
        $active=(int)(($_POST['active']??'0')==='1');run_sql('UPDATE users SET is_active=? WHERE id=?',[$active,$rec['user_id']]);audit('student_account_status','students',$id,['active'=>$active]);flash('Student account '.($active?'reactivated':'deactivated').'.');go('students.php');
    }
    if($action==='save'){
        $number=trim((string)($_POST['student_number']??''));$first=trim((string)($_POST['first_name']??''));$last=trim((string)($_POST['last_name']??''));$middle=trim((string)($_POST['middle_name']??''));$program=strtoupper(trim((string)($_POST['program']??'')));$year=(int)($_POST['year_level']??0);$section=trim((string)($_POST['section']??''));$email=trim((string)($_POST['email']??''));
        if(!preg_match('/^[A-Za-z0-9-]{4,30}$/',$number) || $first==='' || $last==='' || !in_array($program,['BSIT','BSCS','BSCE','ITE','OTHER'],true) || $year<1 || $year>5 || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) { flash('Check the student number, name, program, year, and email.', 'error');go('students.php'.($id?'?edit='.$id:'')); }
        try{
            db()->beginTransaction();
            if($id){
                $old=one('SELECT user_id FROM students WHERE id=? FOR UPDATE',[$id]);if(!$old) throw new RuntimeException('Student not found.');
                run_sql('UPDATE students SET student_number=?,first_name=?,last_name=?,middle_name=?,program=?,year_level=?,section=?,email=? WHERE id=?',[$number,$first,$last,$middle?:null,$program,$year,$section?:null,$email?:null,$id]);
                run_sql('UPDATE users SET login_id=? WHERE id=?',[$number,$old['user_id']]);
                audit('student_updated','students',$id);$message='Student profile saved.';
            }else{
                $role=one("SELECT id FROM roles WHERE name='Student'");if(!$role) throw new RuntimeException('Student role is missing from the database.');
                $temporary='student123'; // Shared temporary password for NEW student accounts only
                run_sql('INSERT INTO users(role_id,login_id,password_hash,must_change_password,is_active) VALUES(?,?,?,1,1)',[$role['id'],$number,password_hash($temporary,PASSWORD_DEFAULT)]);
                $uid=(int)db()->lastInsertId();
                run_sql('INSERT INTO students(user_id,student_number,first_name,last_name,middle_name,program,year_level,section,email,qr_token) VALUES(?,?,?,?,?,?,?,?,?,?)',[$uid,$number,$first,$last,$middle?:null,$program,$year,$section?:null,$email?:null,bin2hex(random_bytes(32))]);
                $id=(int)db()->lastInsertId();foreach(all("SELECT id FROM events WHERE status IN ('published','ongoing')") as $activeEvent)sync_participants((int)$activeEvent['id']);audit('student_created','students',$id);$message='Student created. Login ID: '.$number.' — one-time temporary password: '.$temporary.'. Share privately; this message disappears after leaving the page.';
            }
            db()->commit();flash($message);go('students.php');
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();friendly_error($e,'Unable to save student. Verify this student number is not already registered.');go('students.php'.($id?'?edit='.$id:''));}
    }
    fail('Invalid action.');
}
$search=trim((string)($_GET['search']??''));$program=trim((string)($_GET['program']??''));$year=(int)($_GET['year']??0);$page=max(1,(int)($_GET['page']??1));$where=[];$args=[];
if($search!==''){$where[]='(s.student_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)';$term='%'.$search.'%';array_push($args,$term,$term,$term);}
if($program!==''){$where[]='s.program=?';$args[]=$program;}
if($year>=1 && $year<=5){$where[]='s.year_level=?';$args[]=$year;}
$whereSql=$where?' WHERE '.implode(' AND ',$where):'';
$count=(int)(one('SELECT COUNT(*) n FROM students s JOIN users u ON u.id=s.user_id'.$whereSql,$args)['n']??0);
$offset=($page-1)*25;$students=all('SELECT s.*,u.is_active FROM students s JOIN users u ON u.id=s.user_id'.$whereSql.' ORDER BY s.last_name,s.first_name LIMIT 25 OFFSET '.$offset,$args);
$programs=all('SELECT DISTINCT program FROM students ORDER BY program');
page_header('Students',$u);title_block('Enrolled Students','View, search, and manage student information.',$admin?'<a class="btn btn-primary" href="'.h(app_path('students.php?new=1')).'">+ Add Student</a>':null);
if($admin && ((isset($_GET['new']) || $editing))): ?>
<section class="panel"><h2><?=$editing?'Edit Student Profile':'Create Student Account'?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><div class="form-grid wide">
<?php foreach(['student_number'=>'Student Number','first_name'=>'First Name','middle_name'=>'Middle Name','last_name'=>'Last Name','section'=>'Section','email'=>'Email'] as $key=>$label):?><label><?=h($label)?><input name="<?=h($key)?>" value="<?=h($editing[$key]??'')?>" <?=in_array($key,['student_number','first_name','last_name'],true)?'required':''?> maxlength="150"></label><?php endforeach; ?>
<label>Program<select name="program" required><?php foreach(['BSIT','BSCS','BSCE','ITE','OTHER'] as $p):?><option value="<?=h($p)?>" <?=$p===($editing['program']??'BSIT')?'selected':''?>><?=h($p)?></option><?php endforeach;?></select></label><label>Year Level<select name="year_level" required><?php for($y=1;$y<=5;$y++):?><option value="<?=$y?>" <?=$y===(int)($editing['year_level']??1)?'selected':''?>>Year <?=$y?></option><?php endfor;?></select></label></div><?php if (!$editing): ?><p class="hint">New student accounts start with the temporary password <strong>student123</strong> and must set a new password on first sign-in.</p><?php endif; ?><div class="row" style="margin-top:18px"><button class="btn btn-primary">Save Student</button><a class="btn btn-ghost" href="<?=h(app_path('students.php'))?>">Cancel</a></div></form></section>
<?php endif; ?>
<section class="panel"><form method="get" class="form-grid wide" style="align-items:end"><label>Name / Student ID<input name="search" placeholder="Search students" value="<?=h($search)?>"></label><label>Program<select name="program"><option value="">All programs</option><?php foreach($programs as $p):?><option value="<?=h($p['program'])?>" <?=$p['program']===$program?'selected':''?>><?=h($p['program'])?></option><?php endforeach;?></select></label><label>Year Level<select name="year"><option value="0">All years</option><?php for($y=1;$y<=5;$y++):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>>Year <?=$y?></option><?php endfor;?></select></label><div class="row"><button class="btn btn-primary">Apply</button><a class="btn btn-ghost" href="<?=h(app_path('students.php'))?>">Reset</a></div></form><div class="divider"></div><p class="hint">Showing <?=count($students)?> of <?=$count?> matching students.</p><div class="table-scroll"><table class="table"><thead><tr><th>Student ID</th><th>Full name</th><th>Program</th><th>Year</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($students as $s):?><tr><td><?=h($s['student_number'])?></td><td><?=h($s['last_name'].', '.$s['first_name'].' '.$s['middle_name'])?></td><td><?=h($s['program'])?></td><td>Year <?=h($s['year_level'])?></td><td><?=status_badge($s['is_active']?'active':'inactive')?></td><td class="actions"><a class="btn btn-ghost btn-sm" href="<?=h(app_path('student.php?id='.$s['id']))?>">View</a><a class="btn btn-primary btn-sm" href="<?=h(app_path('fines.php?student_id='.$s['id']))?>">Fines / Pay</a><?php if($admin):?><a class="btn btn-soft btn-sm" href="<?=h(app_path('students.php?edit='.$s['id']))?>">Edit</a><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=h($s['id'])?>"><input type="hidden" name="active" value="<?=$s['is_active']?'0':'1'?>"><button class="btn btn-ghost btn-sm" data-confirm="Change this student account status? Historical records will remain intact."><?=$s['is_active']?'Deactivate':'Reactivate'?></button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if(!$students):?><p class="empty">No students found.</p><?php endif;?><?php if($count>25):?><div class="row" style="margin-top:16px"><?php if($page>1):?><a class="btn btn-ghost" href="<?=h(app_path('students.php?'.http_build_query(['search'=>$search,'program'=>$program,'year'=>$year,'page'=>$page-1])))?>">Previous</a><?php endif;?><?php if($page*25<$count):?><a class="btn btn-ghost" href="<?=h(app_path('students.php?'.http_build_query(['search'=>$search,'program'=>$program,'year'=>$year,'page'=>$page+1])))?>">Next</a><?php endif;?></div><?php endif;?></section>
<?php page_footer();
