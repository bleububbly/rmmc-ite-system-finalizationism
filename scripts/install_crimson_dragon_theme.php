<?php
declare(strict_types=1);
/* Run ONLY in PHP CLI; inject CSS/JS without replacing authentication or attendance logic. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command line installer only.');}
$root=dirname(__DIR__);
$coreFile=$root.'/app/core.php';$loginFile=$root.'/login.php';
function abort_theme(string $reason):never{fwrite(STDERR,"ERROR: $reason\n");exit(1);}
foreach(['app/core.php','login.php','assets/css/dragon-theme.css','assets/css/login-crimson.css','assets/js/attendance-accordion.js','assets/js/login-crimson.js','assets/images/dragon-scales.svg'] as $f)if(!is_file($root.'/'.$f))abort_theme("Missing $f. Extract the entire ZIP inside the project folder.");
$core=file_get_contents($coreFile);$login=file_get_contents($loginFile);
if($core===false||$login===false)abort_theme('Cannot read PHP files.');
if(!str_contains($core,'function page_header(')||!str_contains($core,'function page_footer('))abort_theme('Unrecognized app/core.php. No changes made.');
if(!str_contains($login,'name="login_id"')||!str_contains($login,'name="password"'))abort_theme('Unrecognized login.php. No changes made.');
$coreCSS=<<<'HTML'
<!-- RMMC CRIMSON DRAGON THEME -->
<link rel="stylesheet" href="<?=h(app_path('assets/css/dragon-theme.css?v=1'))?>">
HTML;
$coreJS=<<<'HTML'
<script src="<?=h(app_path('assets/js/attendance-accordion.js?v=1'))?>" defer></script>
HTML;
$loginCSS=<<<'HTML'
<!-- RMMC CRIMSON LOGIN THEME -->
<link rel="stylesheet" href="<?=h(app_path('assets/css/login-crimson.css?v=1'))?>">
HTML;
$loginJS=<<<'HTML'
<script src="<?=h(app_path('assets/js/login-crimson.js?v=1'))?>" defer></script>
HTML;
$changes=[];
if(!str_contains($core,'assets/css/dragon-theme.css')){if(substr_count($core,'</head>')!==1)abort_theme('Cannot safely insert shared stylesheet.');$core=str_replace('</head>',$coreCSS."\n</head>",$core);$changes[]='Core CSS linked';}
if(!str_contains($core,'assets/js/attendance-accordion.js')){if(substr_count($core,'</body>')!==1)abort_theme('Cannot safely insert shared script.');$core=str_replace('</body>',$coreJS."\n</body>",$core);$changes[]='Attendance accordion linked';}
if(!str_contains($login,'assets/css/login-crimson.css')){if(substr_count($login,'</head>')!==1)abort_theme('Cannot safely insert login stylesheet.');$login=str_replace('</head>',$loginCSS."\n</head>",$login);$changes[]='Login CSS linked';}
if(!str_contains($login,'assets/js/login-crimson.js')){if(substr_count($login,'</body>')!==1)abort_theme('Cannot safely insert login script.');$login=str_replace('</body>',$loginJS."\n</body>",$login);$changes[]='Login password toggle linked';}
if(str_contains($login,'One place for every<br>ITE event.')){$login=str_replace('One place for every<br>ITE event.','Magandang RMMC!',$login);$changes[]='Welcome heading refreshed';}
// Make a backup outside htdocs so no PHP source files can be downloaded from it.
$backup=dirname($root,2).'/rmmc-ite-theme-backups/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!mkdir($backup.'/app',0700,true))abort_theme('Cannot create backup outside htdocs; no source files changed.');
if(!copy($coreFile,$backup.'/app/core.php')||!copy($loginFile,$backup.'/login.php'))abort_theme('Backup failed.');
$logo=$root.'/assets/images/ite-logo.png';
if(!is_file($logo)){$fallback=$root.'/resources/ite-logo-fallback.png';if(!is_file($fallback)||!copy($fallback,$logo))abort_theme('Cannot install missing ITE logo.');$changes[]='Original ITE logo restored';}
if($core!==file_get_contents($coreFile)&&file_put_contents($coreFile,$core,LOCK_EX)===false)abort_theme('Core write failed. Recover from: '.$backup);
if($login!==file_get_contents($loginFile)&&file_put_contents($loginFile,$login,LOCK_EX)===false)abort_theme('Login write failed. Recover from: '.$backup);
echo "SUCCESS: Crimson Dragon visual theme installed.\nBackup: $backup\n";
foreach($changes as $c)echo " - $c\n";
if(!$changes)echo "Already installed; no changes necessary.\n";
echo "No SQL migration or attendance/payment record changes.\n";
