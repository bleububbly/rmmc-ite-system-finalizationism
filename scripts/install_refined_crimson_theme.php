<?php
declare(strict_types=1);
/* Refined Crimson Theme: visual-only, safe to run repeatedly from PHP CLI. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command-line installer only.'); }
$root = dirname(__DIR__);
function stop_refined(string $message): never { fwrite(STDERR,"ERROR: $message\n"); exit(1); }
foreach ([
  'app/core.php','login.php','assets/css/login-crimson.css',
  'assets/css/dragon-theme.css','assets/images/dragon-scales.svg',
  'assets/js/login-crimson.js','assets/js/attendance-accordion.js'
] as $path) {
  if (!is_file($root.'/'.$path)) stop_refined("Missing $path. Extract ZIP contents into your project root first.");
}
$corePath = $root.'/app/core.php';
$loginPath = $root.'/login.php';
$core = file_get_contents($corePath);
$login = file_get_contents($loginPath);
if ($core === false || $login === false) stop_refined('Could not read the current PHP files.');
if (!str_contains($core,'function page_header(') || !str_contains($core,'function page_footer(')) stop_refined('Unexpected app/core.php: no changes made.');
if (!str_contains($login,'name="login_id"') || !str_contains($login,'name="password"')) stop_refined('Unexpected login.php: no changes made.');
$origCore = $core; $origLogin = $login; $changes = [];
// Refresh the stylesheet URL version (old browser CSS cache should not persist).
if (str_contains($core, 'assets/css/dragon-theme.css')) {
  $core = preg_replace('~dragon-theme\.css(?:\?v=\d+)?~','dragon-theme.css?v=2',$core);
} else {
  if (substr_count($core,'</head>') !== 1) stop_refined('Cannot insert stylesheet into core: no changes made.');
  $tag = <<<'HTML'
<link rel="stylesheet" href="<?=h(app_path('assets/css/dragon-theme.css?v=2'))?>">
HTML;
  $core = str_replace('</head>',$tag."\n</head>",$core);
  $changes[] = 'Deep-red sidebar and light dragon-scale dashboard stylesheet enabled';
}
if (!str_contains($core,'assets/js/attendance-accordion.js')) {
  if (substr_count($core,'</body>') !== 1) stop_refined('Cannot insert attendance script into core: no changes made.');
  $tag = <<<'HTML'
<script src="<?=h(app_path('assets/js/attendance-accordion.js?v=2'))?>" defer></script>
HTML;
  $core = str_replace('</body>',$tag."\n</body>",$core);
  $changes[] = 'Attendance collapsible panels enabled';
}
if (str_contains($login,'assets/css/login-crimson.css')) {
  $login = preg_replace('~login-crimson\.css(?:\?v=\d+)?~','login-crimson.css?v=2',$login);
} else {
  if (substr_count($login,'</head>') !== 1) stop_refined('Cannot insert stylesheet into login: no changes made.');
  $tag = <<<'HTML'
<link rel="stylesheet" href="<?=h(app_path('assets/css/login-crimson.css?v=2'))?>">
HTML;
  $login = str_replace('</head>',$tag."\n</head>",$login);
  $changes[] = 'Split-panel login stylesheet enabled';
}
if (!str_contains($login,'assets/js/login-crimson.js')) {
  if (substr_count($login,'</body>') !== 1) stop_refined('Cannot insert password toggle script: no changes made.');
  $tag = <<<'HTML'
<script src="<?=h(app_path('assets/js/login-crimson.js?v=2'))?>" defer></script>
HTML;
  $login = str_replace('</body>',$tag."\n</body>",$login);
  $changes[] = 'Login show/hide password button enabled';
}
// Previous visual patch changed the welcome board's original heading.
// Restore only the exact previous generated heading; leave custom headings alone.
if (str_contains($login,'<h1>Magandang RMMC!</h1>')) {
  $login = str_replace('<h1>Magandang RMMC!</h1>','<h1>One place for every<br>ITE event.</h1>',$login);
  $changes[] = 'Original login welcome board heading restored';
}
$logo=$root.'/assets/images/ite-logo.png';
if (!is_file($logo)) {
  $fallback=$root.'/resources/ite-logo-fallback.png';
  if (!is_file($fallback)) stop_refined('Missing ITE logo; add your existing assets/images/ite-logo.png.');
  if (!@copy($fallback,$logo)) stop_refined('Could not restore ITE logo.');
  $changes[] = 'Existing ITE logo restored';
}
if ($core !== $origCore || $login !== $origLogin) {
  // Back up PHP sources outside the served htdocs directory.
  $base=dirname($root,2).'/rmmc-ite-theme-backups/refined_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
  if (!@mkdir($base.'/app',0700,true)) stop_refined('Cannot create safe backup; no PHP source files changed.');
  if (!@copy($corePath,$base.'/app/core.php') || !@copy($loginPath,$base.'/login.php')) stop_refined('Backup failed; no PHP source files changed.');
  if ($core !== $origCore && file_put_contents($corePath,$core,LOCK_EX)===false) stop_refined('Cannot update app/core.php. Restore from: '.$base);
  if ($login !== $origLogin && file_put_contents($loginPath,$login,LOCK_EX)===false) stop_refined('Cannot update login.php. Restore from: '.$base);
  echo "PHP backups: $base\n";
}
echo "SUCCESS: Refined Crimson theme installed.\n";
foreach ($changes as $change) echo " - $change\n";
echo " - Restored original left login board\n - Right-side crimson login form\n - Sparse dragon-scale background\n - Deep-red sidebar on all logged-in pages\n";
echo "No SQL changes. No attendance, accounts, payments, or fines changed.\n";
