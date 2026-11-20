<?php
require __DIR__ . '/app/core.php';$u=require_login();
if(request_post()){
    check_post();$action=(string)($_POST['action']??'');
    if($action==='profile'){
        $email=trim((string)($_POST['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('Invalid email address.','error');go('settings.php');}
        if($u['role']==='Student'){
            run_sql('UPDATE students SET email=? WHERE user_id=?',[$email?:null,$u['id']]);
        }else {
            $first=trim((string)($_POST['first_name']??''));$last=trim((string)($_POST['last_name']??''));$middle=trim((string)($_POST['middle_name']??''));
            run_sql('INSERT INTO staff_profiles(user_id,first_name,middle_name,last_name,email) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),middle_name=VALUES(middle_name),last_name=VALUES(last_name),email=VALUES(email)',[$u['id'],$first,$middle?:null,$last,$email?:null]);
        }
        audit('profile_updated','users',(int)$u['id']);flash('Profile updated.');go('settings.php');
    }
    fail('Unsupported settings action.');
}
$profile=$u['role']==='Student'?student_for((int)$u['id']):one('SELECT * FROM staff_profiles WHERE user_id=?',[$u['id']]);
page_header('Settings',$u);title_block('Account Settings','Manage your profile and security preferences.');
?><div class="grid-2"><section class="panel"><h2>Profile Information</h2><form method="post" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="profile"><label>Login ID<input value="<?=h($u['login_id'])?>" readonly></label><label>User Role<input value="<?=h($u['role'])?>" readonly></label><?php if($u['role']!=='Student'):?><?php foreach(['first_name'=>'First Name','middle_name'=>'Middle Name','last_name'=>'Last Name'] as $key=>$label):?><label><?=h($label)?><input name="<?=h($key)?>" maxlength="100" value="<?=h($profile[$key]??'')  ?>"></label><?php endforeach; ?><?php if($u['role']==='Officer'):?>
<label>Organization Name (Admin-managed)<input value="<?=h(($profile['organization_name']??'') ?: 'Not set')?>" readonly></label>
<label>Position (Admin-managed)<input value="<?=h(($profile['position_title']??'') ?: 'Not set')?>" readonly></label>
<?php endif; ?>
<?php endif;?><label>Email Address<input type="email" maxlength="150" name="email" value="<?=h($profile['email']??'')  ?>"></label><button class="btn btn-primary">Save Changes</button></form></section><section class="panel"><h2>Account Security</h2><p class="hint">Protect your account with a private, unique password. Your password is stored as a secure hash, never as readable text.</p><a class="btn btn-primary" href="<?=h(app_path('change-password.php'))?>">Change Password</a><?php if($u['role']==='Admin'):?>
<div class="divider"></div>
<h2>Reset Student / Officer Password</h2>
<p class="hint">Find a user by Student ID, username, or last name. Reset to a role default (student123 / officer123) or set a temporary password. Your own Admin password is required for confirmation.</p>
<a class="btn btn-soft" href="<?=h(app_path('admin-password-reset.php'))?>">Find User & Reset Password</a>
<?php endif;?>
<div class="divider"></div><h2>Current Account</h2><p><?=status_badge($u['is_active']?'active':'inactive')?> <?=h($u['role'])?> access</p><p class="hint">If you need your QR code reset or encounter an account issue, contact the Administrator.</p></section></div><?php page_footer();
