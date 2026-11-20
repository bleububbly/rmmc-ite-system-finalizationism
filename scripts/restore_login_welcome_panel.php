<?php
declare(strict_types=1);
/**
 * Restores the ORIGINAL welcome text/branding board on the left of RMMC ITE login.
 * Only modifies login.php; existing sign-in form, auth and PHP behavior stay untouched.
 * Run via CLI from the RMMC ITE project; safely repeatable.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command-line installer only.');
}
$root = dirname(__DIR__);
$loginFile = $root . '/login.php';
$cssFile = $root . '/assets/css/login-welcome-restore.css';
function restore_fail(string $message): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}
if (!is_file($loginFile)) restore_fail('login.php is missing. Extract ZIP to the RMMC ITE project root.');
if (!is_file($cssFile)) restore_fail('assets/css/login-welcome-restore.css is missing. Extract the entire ZIP first.');
$before = file_get_contents($loginFile);
if ($before === false) restore_fail('Cannot read login.php.');
if (!str_contains($before, 'name="login_id"') || !str_contains($before, 'name="password"')) {
    restore_fail('Unrecognized login.php form; no changes made.');
}
$start = strpos($before, '<div class="login-intro">');
$end   = $start === false ? false : strpos($before, '<div class="login-copy">', $start);
$left  = strpos($before, '<div class="login-left">');
$right = strpos($before, '<div class="login-right">');
if ($start === false || $end === false || $left === false || $right === false ||
    !($left < $start && $start < $end && $end < $right)) {
    restore_fail('Original login welcome layout was not found; no changes made.');
}
$intro = '<div class="login-intro">'
    . '<span class="eyebrow">WELCOME TO RMMC ITE</span>'
    . '<h1>One place for every<br>ITE event.</h1>'
    . '<p>Track participation, scan secure QR codes, manage attendance, payments, and semester clearance.</p>'
    . '<div class="login-chips"><span>Attendance</span><span>Events</span><span>Clearance</span></div>'
    . '</div>';
$updated = substr($before, 0, $start) . $intro . substr($before, $end);
// Append after existing theme stylesheets, so the welcome-panel override takes precedence.
$cssTag = <<<'HTML'
<link rel="stylesheet" href="<?=h(app_path('assets/css/login-welcome-restore.css?v=1'))?>">
HTML;
if (!str_contains($updated, 'assets/css/login-welcome-restore.css')) {
    if (substr_count($updated, '</head>') !== 1) restore_fail('Unexpected HTML head structure; no changes made.');
    $updated = str_replace('</head>', $cssTag . "\n</head>", $updated);
}
if ($updated === $before) {
    echo "SUCCESS: Original login welcome panel already restored. No changes needed.\n";
    exit(0);
}
// Backup outside htdocs to avoid exposing source through Apache.
$backupDir = dirname($root, 2) . '/rmmc-ite-theme-backups/welcome_panel_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
if (!@mkdir($backupDir, 0700, true)) restore_fail('Could not create backup folder; no changes made.');
if (!@copy($loginFile, $backupDir . '/login.php')) restore_fail('Could not back up login.php; no changes made.');
if (file_put_contents($loginFile, $updated, LOCK_EX) === false) {
    @copy($backupDir . '/login.php', $loginFile);
    restore_fail('Could not write login.php. Restored its backup.');
}
echo "SUCCESS: Original RMMC ITE welcome panel restored.\n";
echo "Backup: {$backupDir}/login.php\n";
echo "Only the login welcome content and its stylesheet link were updated.\n";
echo "No SQL changes. Accounts, authentication, dashboards and attendance unaffected.\n";
