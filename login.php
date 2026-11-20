<?php
require __DIR__ . '/app/core.php';
if (me()) go('dashboard.php');
$error = '';
if (request_post()) {
    check_post();
    $login = trim((string)($_POST['login_id'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $record = one('SELECT id, password_hash, is_active, must_change_password FROM users WHERE login_id = ?', [$login]);
    if ($record && (int)$record['is_active'] === 1 && password_verify($pass, $record['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$record['id'];
        $_SESSION['last_seen'] = time();
        run_sql('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int)$record['id']]);
        go((int)$record['must_change_password'] ? 'change-password.php' : 'dashboard.php');
    }
    $error = 'Invalid login ID or password.';
    usleep(200000);
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in | RMMC ITE</title><link rel="stylesheet" href="<?=h(app_path('assets/css/app.css'))?>"><link rel="stylesheet" href="<?=h(app_path('assets/css/login-ite.css'))?>"><!-- RMMC CRIMSON LOGIN THEME -->
<link rel="stylesheet" href="<?=h(app_path('assets/css/login-crimson.css?v=1'))?>">
<link rel="stylesheet" href="<?=h(app_path('assets/css/login-welcome-restore.css?v=1'))?>">
</head><body class="login-page"><div class="login-left"><img class="ite-login-logo-top" src="<?=h(app_path('assets/images/ite-logo.png'))?>" alt="Institute of Technology Education logo"><div class="login-intro"><span class="eyebrow">WELCOME TO RMMC ITE</span><h1>One place for every<br>ITE event.</h1><p>Track participation, scan secure QR codes, manage attendance, payments, and semester clearance.</p><div class="login-chips"><span>Attendance</span><span>Events</span><span>Clearance</span></div></div><div class="login-copy">Institute of Technology Education · RMMC</div></div><div class="login-right"><div class="login-panel"><img class="ite-login-logo-card" src="<?=h(app_path('assets/images/ite-logo.png'))?>" alt="RMMC ITE logo"><h2>Welcome back</h2><p>Sign in to your account to continue.</p>
<?php if ($error): ?><div class="alert alert-error"><?=h($error)?></div><?php endif; ?><?php if($f=take_flash()):?><div class="alert alert-<?=h($f[0])?>"><?=h($f[1])?></div><?php endif; ?>
<form method="post" class="form-stack"><?=csrf_field()?><label>Student ID / Login ID<input name="login_id" required autofocus autocomplete="username" maxlength="100" value="<?=h($_POST['login_id']??'')?>" placeholder="Enter your ID"></label><label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></label><button class="btn btn-primary full">Sign in</button></form><p class="help">Use the account credentials provided by your system administrator.</p>
<details class="ite-forgot-box"><summary>Forgot your password?</summary>
<p>Contact the RMMC ITE system Administrator and provide your Student Number or Officer username. The Admin can reset your password from <strong>Settings → Find User &amp; Reset Password</strong>. You must change the temporary password when you sign in.</p>
</details></div></div><script src="<?=h(app_path('assets/js/login-crimson.js?v=1'))?>" defer></script>
</body></html>
