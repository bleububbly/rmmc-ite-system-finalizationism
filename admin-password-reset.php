<?php
/** Admin-only password reset: never reveals existing passwords. */
require __DIR__ . '/app/core.php';
$admin = require_role('Admin');
$search = trim((string)($_GET['q'] ?? ''));
$search = substr($search, 0, 100);

if (request_post()) {
    check_post();
    if ((string)($_POST['action'] ?? '') !== 'reset') fail('Unsupported action.');
    $targetId=(int)($_POST['target_id']??0);
    $mode=(string)($_POST['reset_mode']??'default');
    $custom=(string)($_POST['custom_password']??'');
    $adminPassword=(string)($_POST['admin_password']??'');
    $returnSearch=trim((string)($_POST['return_q']??''));
    $returnSearch=substr($returnSearch,0,100);
    $returnUrl='admin-password-reset.php'.($returnSearch!==''?'?q='.rawurlencode($returnSearch):'');

    // Recheck administrator's own password before performing a sensitive reset.
    $adminRecord=one('SELECT password_hash FROM users WHERE id=?',[(int)$admin['id']]);
    if (!$adminRecord || !password_verify($adminPassword,$adminRecord['password_hash'])) {
        flash('Your Admin password was incorrect. No account was changed.','error');go($returnUrl);
    }
    if ($targetId <= 0) {flash('Choose a valid user.','error');go($returnUrl);}
    try {
        db()->beginTransaction();
        $target=one("SELECT u.id,u.login_id,u.is_active,r.name AS role
            FROM users u JOIN roles r ON r.id=u.role_id
            WHERE u.id=? FOR UPDATE",[$targetId]);
        if (!$target || !in_array($target['role'],['Student','Officer'],true)) {
            throw new RuntimeException('Only Student and Officer accounts can be reset here.');
        }
        if ((int)$target['is_active']!==1) throw new RuntimeException('Account is inactive. Ask Admin to reactivate it first.');
        if (!in_array($mode,['default','custom'],true)) throw new RuntimeException('Invalid password reset method.');
        $temporary = $mode==='default' ? ($target['role']==='Student' ? 'student123' : 'officer123') : $custom;
        if (strlen($temporary)<10 || strlen($temporary)>72) {
            throw new RuntimeException('A temporary password must be 10 to 72 characters.');
        }
        run_sql('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?',[
            password_hash($temporary,PASSWORD_DEFAULT),$targetId
        ]);
        // Do not log the actual password; record only who/when/which account.
        audit('admin_reset_password','users',$targetId,[
            'target_login_id'=>$target['login_id'],
            'target_role'=>$target['role'],
            'mode'=>$mode,
            'must_change_password'=>1
        ]);
        db()->commit();
        flash('Password reset for '. $target['login_id'] .'. Give the temporary password privately. A password change is required at next login.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Admin reset failed: '.$e->getMessage());
        flash('Unable to reset password: '.$e->getMessage(),'error');
    }
    go($returnUrl);
}

$results=[];
if($search!=='') {
    $like='%'.$search.'%';
    $results=all("SELECT u.id,u.login_id,u.is_active,r.name AS role,
        s.student_number,s.last_name AS student_last,s.first_name AS student_first,
        sp.last_name AS officer_last,sp.first_name AS officer_first
        FROM users u JOIN roles r ON r.id=u.role_id
        LEFT JOIN students s ON s.user_id=u.id
        LEFT JOIN staff_profiles sp ON sp.user_id=u.id
        WHERE r.name IN ('Student','Officer')
        AND (u.login_id LIKE ? OR s.student_number LIKE ? OR s.last_name LIKE ? OR sp.last_name LIKE ?)
        ORDER BY u.login_id LIMIT 50",[$like,$like,$like,$like]);
}
page_header('Admin Password Reset',$admin);
title_block('Reset User Password','Admin-only account recovery. Search Student ID, username, or last name.');
?>
<section class="panel">
<h2>Find a Student or Officer</h2>
<form method="get" class="row" style="align-items:flex-end">
<label style="max-width:530px;flex:1;min-width:220px">Student ID / Username / Last Name
<input type="search" name="q" value="<?=h($search)?>" placeholder="Enter ID, login ID, or last name" required maxlength="100"></label>
<button type="submit" class="btn btn-primary">Search</button>
<a class="btn btn-ghost" href="<?=h(app_path('settings.php'))?>">Back to Settings</a>
</form>
<p class="hint">Only administrators can perform resets. Existing passwords cannot be viewed.</p>
</section>
<?php if($search!==''):?>
<section class="panel">
<h2>Search Results (<?=count($results)?>)</h2>
<?php if(!$results):?><p class="empty">No matching Student or Officer accounts.</p><?php endif;?>
<?php foreach($results as $row):
 $name=$row['role']==='Student' ? trim(($row['student_last']??'').', '.($row['student_first']??'')) : trim(($row['officer_last']??'').', '.($row['officer_first']??''));
 $defaultPass=$row['role']==='Student'?'student123':'officer123';
?>
<div class="panel" style="border:1px solid #e7e9ee;margin:12px 0;padding:17px">
<div class="row between"><div><strong><?=h($row['login_id'])?></strong> · <?=h($name)?> · <?=h($row['role'])?></div><?=status_badge((int)$row['is_active']===1?'active':'inactive')?></div>
<form method="post" class="form-grid" style="margin-top:16px" onsubmit="return confirm('Reset password for <?=h($row['login_id'])?>? This immediately replaces the current password.');">
<?=csrf_field()?><input type="hidden" name="action" value="reset"><input type="hidden" name="target_id" value="<?=h($row['id'])?>"><input type="hidden" name="return_q" value="<?=h($search)?>">
<label>Reset Method
<select name="reset_mode" class="reset-mode" data-target="custom-pass-<?=h($row['id'])?>">
<option value="default">Use role default (<?=h($defaultPass)?>)</option>
<option value="custom">Set a custom temporary password</option>
</select></label>
<label>Custom Temporary Password (if selected)
<input id="custom-pass-<?=h($row['id'])?>" name="custom_password" type="password" minlength="10" maxlength="72" autocomplete="new-password" placeholder="10–72 characters" disabled></label>
<label>Confirm Your Admin Password
<input name="admin_password" type="password" autocomplete="current-password" required placeholder="Your own Admin password"></label>
<div style="display:flex;align-items:flex-end"><button class="btn btn-primary" type="submit" <?=(int)$row['is_active']!==1?'disabled title="Inactive account"':''?>>Reset Password</button></div>
</form>
</div>
<?php endforeach;?>
</section>
<?php endif;?>
<section class="panel"><h2>Account Recovery Rules</h2><p>Student default: <strong>student123</strong>. Officer default: <strong>officer123</strong>. Users must change the temporary password on their next login. Only give passwords directly to the intended account holder.</p><p class="hint">Shared default passwords are easier to guess. For real deployment, prefer a unique custom temporary password and communicate it securely.</p></section>
<script>
document.querySelectorAll('.reset-mode').forEach(select=>select.addEventListener('change',()=>{
 const field=document.getElementById(select.dataset.target);
 field.disabled=select.value!=='custom';field.required=select.value==='custom';
 if(select.value!=='custom')field.value='';
}));
</script>
<?php page_footer();
