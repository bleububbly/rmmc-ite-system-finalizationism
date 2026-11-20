<?php
require __DIR__ . '/app/core.php';
$u = require_login(false); $error='';
if(request_post()) {
    check_post();
    $old=(string)($_POST['current']??'');$new=(string)($_POST['new']??'');$confirm=(string)($_POST['confirm']??'');
    $row=one('SELECT password_hash FROM users WHERE id=?',[$u['id']]);
    if (!$row || !password_verify($old,$row['password_hash'])) $error='Current password is incorrect.';
    elseif (strlen($new)<10 || strlen($new)>72) $error='Use a password between 10 and 72 characters.';
    elseif ($new!==$confirm) $error='New passwords do not match.';
    elseif (password_verify($new,$row['password_hash'])) $error='Choose a different password.';
    else { run_sql('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$u['id']]);audit('password_change','users',(int)$u['id']);session_regenerate_id(true);flash('Password updated successfully.');go('dashboard.php'); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Change password | RMMC ITE</title><link rel="stylesheet" href="<?=h(app_path('assets/css/app.css'))?>"></head><body class="login-page" style="display:flex;align-items:center;justify-content:center"><section class="login-panel" style="max-width:460px;margin:24px"><span class="login-mini-logo">R</span><h2><?= (int)$u['must_change_password'] ? 'Set your new password' : 'Update password' ?></h2><p>Secure your <?=h($u['role'])?> account before proceeding.</p><?php if($error): ?><div class="alert alert-error"><?=h($error)?></div><?php endif; ?><form method="post" class="form-stack"><?=csrf_field()?><label>Current password<input type="password" name="current" required autocomplete="current-password"></label><label>New password (at least 10 characters)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label><button class="btn btn-primary full">Update password</button></form><form method="post" action="<?=h(app_path('logout.php'))?>" style="margin-top:16px"><?=csrf_field()?><button class="text-button">Sign out</button></form></section></body></html>
