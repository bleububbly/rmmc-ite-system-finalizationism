<?php
declare(strict_types=1);

require __DIR__ . '/app/core.php';
require_once __DIR__ . '/app/student_qr_payload.php';

$user = require_login();
$role = (string) $user['role'];
$rawId = $_GET['id'] ?? null;

if ($rawId !== null && (!is_string($rawId) || !ctype_digit($rawId) || (int) $rawId < 1)) {
    fail('Invalid student ID.', 400);
}
$requestedStudentId = $rawId === null ? 0 : (int) $rawId;

/** Keep card names short and readable without altering database fields. */
function qr_card_name(string $first, string $middle, string $last, string $fallback): string
{
    $middle = trim($middle);
    $initial = $middle !== '' ? strtoupper(substr($middle, 0, 1)) . '.' : '';
    $name = trim(implode(' ', array_filter([trim($first), $initial, trim($last)], static fn($s) => $s !== '')));
    return $name !== '' ? $name : $fallback;
}

function qr_card_year(int $year): string
{
    $ending = ($year % 100 >= 11 && $year % 100 <= 13) ? 'th' : match ($year % 10) {
        1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
    };
    return $year > 0 ? $year . $ending . ' Year' : 'Year not set';
}

$student = null;
$staff = null;

if ($role === 'Student') {
    $student = student_for((int) $user['id']);
    if ($requestedStudentId && (!$student || $requestedStudentId !== (int) $student['id'])) {
        fail('You can only view your own QR code.', 403);
    }
} elseif (in_array($role, ['Admin', 'Officer'], true)) {
    if ($requestedStudentId) {
        // Keep the existing staff workflow: Admin/Officer may print a selected student's QR.
        $student = one('SELECT * FROM students WHERE id=?', [$requestedStudentId]);
        if (!$student) fail('Student not found.', 404);
    } else {
        $staff = one('SELECT * FROM staff_profiles WHERE user_id=?', [(int) $user['id']]);
        if (!$staff) {
            // Creates a profile for Admin as well as Officer. Existing accounts are preserved.
            run_sql('INSERT INTO staff_profiles (user_id, qr_token) VALUES (?, ?)', [
                (int) $user['id'], bin2hex(random_bytes(32))
            ]);
            $staff = one('SELECT * FROM staff_profiles WHERE user_id=?', [(int) $user['id']]);
        }
        if ($staff && empty($staff['qr_token'])) {
            // Do not replace existing valid QR tokens. Only initialize missing ones.
            run_sql("UPDATE staff_profiles SET qr_token=? WHERE user_id=? AND (qr_token IS NULL OR qr_token='')", [
                bin2hex(random_bytes(32)), (int) $user['id']
            ]);
            $staff = one('SELECT * FROM staff_profiles WHERE user_id=?', [(int) $user['id']]);
        }
    }
} else {
    fail('This account cannot access a QR identification card.', 403);
}

$cardReady = false;
$qrPayload = '';
$name = '';
$number = '';
$detailHeading = '';
$detailLine1 = '';
$detailLine2 = '';
$cardType = '';
$isStudentCard = (bool) $student;

if ($student) {
    $name = qr_card_name(
        (string) $student['first_name'], (string) ($student['middle_name'] ?? ''),
        (string) $student['last_name'], (string) $student['student_number']
    );
    $number = (string) $student['student_number'];
    $detailHeading = 'COURSE & YEAR';
    $detailLine1 = (string) $student['program'] . ' · ' . qr_card_year((int) $student['year_level']);
    $detailLine2 = !empty($student['section']) ? 'Section ' . (string) $student['section'] : '';
    $cardType = 'STUDENT ID';
    $qrPayload = student_qr_text($student);
    $cardReady = true;
} elseif ($staff) {
    $name = qr_card_name(
        (string) ($staff['first_name'] ?? ''), (string) ($staff['middle_name'] ?? ''),
        (string) ($staff['last_name'] ?? ''), (string) $user['login_id']
    );
    $number = (string) $user['login_id'];
    $organization = trim((string) ($staff['organization_name'] ?? ''));
    $position = trim((string) ($staff['position_title'] ?? ''));
    if ($role === 'Officer') {
        $detailHeading = 'ORGANIZATION & POSITION';
        $detailLine1 = $organization !== '' ? $organization : 'Organization not assigned';
        $detailLine2 = $position !== '' ? $position : 'Position not assigned';
        $cardType = 'OFFICER ID';
        $qrPayload = 'rmmc-officer:' . (string) $staff['qr_token'];
    } else {
        $detailHeading = 'ROLE & DEPARTMENT';
        $detailLine1 = 'Administrator';
        $detailLine2 = 'RMMC ITE Department';
        $cardType = 'ADMIN ID';
        $qrPayload = 'rmmc-admin:' . (string) $staff['qr_token'];
    }
    $cardReady = !empty($staff['qr_token']);
}

$semester = one("SELECT academic_year FROM semesters WHERE status='active' ORDER BY id DESC LIMIT 1");
if (!$semester) {
    $semester = one('SELECT academic_year FROM semesters ORDER BY start_date DESC, id DESC LIMIT 1');
}
$academicYear = trim((string) ($semester['academic_year'] ?? ''));
$yearText = $academicYear !== '' ? 'Academic Year ' . $academicYear : 'RMMC / ITE DEPARTMENT';

$pageTitle = $requestedStudentId && $role !== 'Student' ? 'Student QR Card' : 'My QR Code';
page_header($pageTitle, $user);
?>
<link rel="stylesheet" href="<?= h(app_path('assets/css/qr-id-card.css')) ?>">
<div class="heading qr-id-heading no-print">
    <div>
        <h1><?= h($pageTitle) ?></h1>
        <p>Your digital RMMC ITE identification card</p>
    </div>
    <?php if ($requestedStudentId && $role !== 'Student'): ?>
        <a class="btn btn-ghost" href="<?= h(app_path('students.php')) ?>">Back to Students</a>
    <?php endif; ?>
</div>

<?php if (!$cardReady): ?>
<section class="panel qr-id-empty">
    <h2>QR identification unavailable</h2>
    <p class="hint">This account does not have a QR token yet. Contact the Admin if the problem continues.</p>
</section>
<?php else: ?>
<div class="qr-id-shell">
    <section class="qr-id-front" aria-label="<?= h($cardType) ?> for <?= h($name) ?>">
        <div class="qr-id-left">
            <div class="qr-id-brand">RMMC / ITE DEPARTMENT</div>
            <div class="qr-id-person">
                <h2><?= h($name) ?></h2>
                <p class="qr-id-number"><?= h($number) ?></p>
            </div>
            <div class="qr-id-information">
                <div class="qr-id-detail">
                    <span><?= h($detailHeading) ?></span>
                    <strong><?= h($detailLine1) ?></strong>
                    <?php if ($detailLine2 !== ''): ?><em><?= h($detailLine2) ?></em><?php endif; ?>
                </div>
                <div class="qr-id-year"><?= h($yearText) ?></div>
            </div>
        </div>
        <div class="qr-id-right">
            <div class="qr-id-paper"><div id="user-qr" class="qr-id-image" role="img" aria-label="<?= $isStudentCard ? 'Student details QR code' : 'Unique ' . h($cardType) . ' QR code' ?>"></div></div>
            <strong class="qr-id-qr-label">My QR</strong>
            <span class="qr-id-role-label"><?= h($cardType) ?></span>
        </div>
    </section>

    <div class="qr-id-actions no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()">Print ID Card</button>
        <?php if ($role === 'Student'): ?>
            <a class="btn btn-ghost" href="<?= h(app_path('dashboard.php')) ?>">Back to Dashboard</a>
        <?php elseif ($requestedStudentId): ?>
            <a class="btn btn-ghost" href="<?= h(app_path('student.php?id=' . $requestedStudentId)) ?>">Student Profile</a>
        <?php else: ?>
            <a class="btn btn-ghost" href="<?= h(app_path('settings.php')) ?>">My Settings</a>
        <?php endif; ?>
    </div>

    <div class="qr-id-help no-print">
        <?php if ($isStudentCard): ?>
            <strong>For event attendance:</strong> Present this QR to an authorized Officer or Admin.
            Any standard QR reader can display exactly six fields: last name, first name, middle initial, student number, course, and year level. QR text is not a password. Staff must check the student's identity because readable QR codes can be copied.
        <?php else: ?>
            <strong>Staff identification only:</strong> Your <?= h(strtolower($cardType)) ?> QR is different from a Student attendance QR and cannot check in a student.
            Keep your QR private.
        <?php endif; ?>
    </div>
</div>
<script src="<?= h(app_path('assets/js/qrcode.min.js')) ?>"></script>
<script>
(function() {
    'use strict';
    const container = document.getElementById('user-qr');
    if (!container) return;
    if (typeof QRCode === 'undefined') {
        container.textContent = 'QR library unavailable';
        return;
    }
    new QRCode(container, {
        text: <?= json_encode($qrPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        width: 200,
        height: 200,
        colorDark: '#202434',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
})();
</script>
<?php endif;
page_footer();
