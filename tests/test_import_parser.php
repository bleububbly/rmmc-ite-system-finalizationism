<?php
declare(strict_types=1);
require __DIR__ . '/../app/attendance_import_lib.php';
$csv = __DIR__ . '/../assets/templates/attendance-import-sample.csv';
$rows = rmmc_import_csv($csv);
assert(count($rows) === 2);
assert($rows[0]['student_number'] === '2026000001');
assert($rows[0]['first_name'] === 'Juan');
assert(rmmc_import_year('3rd Year') === 3);
assert(rmmc_import_middle('Santos') === 's');
$qr = rmmc_import_csv(__DIR__ . '/../assets/templates/attendance-scanner-qr-text-sample.csv');
assert(count($qr) === 2);
assert(rmmc_import_student_fields($qr[0])['student_number'] === '2026000001');
assert(rmmc_import_student_fields($qr[1])['program'] === 'BSCS');
assert(rmmc_import_timestamp(['scanned_at'=>'2026-11-20 08:05:00'], ['opens_at'=>'2026-11-20 08:00:00','closes_at'=>'2026-11-20 09:00:00'], '2026-11-20 08:50:00')['time'] === '2026-11-20 08:05:00');
assert(rmmc_import_status_allowed(['status'=>'Absent']) === false);
assert(rmmc_import_status_allowed(['status'=>'Scanned']) === true);
echo "PASS: CSV header mapping, QR payload, checkpoint timestamp, status filtering.\n";
