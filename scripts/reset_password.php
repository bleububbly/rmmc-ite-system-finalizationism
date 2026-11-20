<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403);exit('CLI only'); }
require dirname(__DIR__) . '/app/core.php';
$login=trim((string)($argv[1]??''));
if($login===''){fwrite(STDERR,"Usage: php scripts/reset_password.php LOGIN_ID\n");exit(1);}
$row=one('SELECT id,login_id FROM users WHERE login_id=?',[$login]);
if(!$row){fwrite(STDERR,"Account not found.\n");exit(1);}
// Password is entered on the local terminal, not sent to any website.
fwrite(STDOUT,"Enter a NEW temporary password (10-72 characters): ");
$pass=rtrim((string)fgets(STDIN),"\r\n");
if(strlen($pass)<10||strlen($pass)>72){fwrite(STDERR,"Password must be 10-72 characters.\n");exit(1);}
run_sql('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$row['id']]);
echo "Password reset for the specified account. A change will be required after sign-in.\n";
