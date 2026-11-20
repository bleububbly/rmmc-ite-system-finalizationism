<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
function expect(bool $ok, string $name): void{if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);} echo "PASS: $name\n";}
expect(good_datetime('2026-11-19 09:30:00'),'valid datetime');
expect(!good_datetime('2026-02-31 09:30:00'),'invalid calendar day');
expect(!good_datetime('broken'),'invalid date input');
expect(sql_datetime('2026-11-19T11:12')==='2026-11-19 11:12:00','HTML datetime-local conversion');
expect(h('<script>')==='&lt;script&gt;','XSS output escaping');
expect(strlen(csrf())===64 && csrf()===csrf(),'stable per-session CSRF token');
$_SERVER['SCRIPT_NAME']='/rmmc-ite-system/dashboard.php';
expect(app_path('events.php')==='/rmmc-ite-system/events.php','subfolder URL support');
$_SERVER['SCRIPT_NAME']='/dashboard.php';
expect(app_path('scan.php')==='/scan.php','VirtualHost root URL support');
echo "Pure PHP smoke tests complete. Database behavior requires XAMPP tests.\n";
