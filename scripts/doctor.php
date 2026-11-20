<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/app/core.php';
echo "RMMC ITE System Doctor\n";
echo 'PHP: '.PHP_VERSION."\n";
if (!extension_loaded('pdo_mysql')) { echo "FAIL: pdo_mysql extension missing. Enable it in XAMPP php.ini.\n"; exit(2); }
try {
    $tables=all("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()");
    $actual=array_column($tables,'table_name');
    $needed=['roles','users','students','semesters','events','event_participants','attendance','attendance_logs','fines','payments','payment_allocations','clearances','audit_logs','staff_profiles','event_attendance_slots','attendance_scans'];
    $missing=array_values(array_diff($needed,$actual));
    echo 'Database: '.DB_NAME."\n";
    if ($missing){echo 'MISSING TABLES: '.implode(', ',$missing)."\nImport base SQL, then database/002_ui_attendance_upgrade.sql.\n";exit(3);}
    echo "PASS: All 16 required tables are present.\n";
    foreach(['Admin','Officer','Student'] as $role){$n=(int)(one('SELECT COUNT(*) n FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name=?',[$role])['n']??0);echo "$role accounts: $n\n";}
    echo "PASS: Connection verified.\n";
}catch(Throwable $e){echo 'FAIL: '.$e->getMessage()."\n";exit(2);}
