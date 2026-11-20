<?php
require __DIR__ . '/app/services.php';
require_once __DIR__ . '/app/student_qr_payload.php';
$u=require_login();$role=$u['role'];

/** ITE-branded dashboard heading, shared by Admin, Officer, and Student. */
function ite_dashboard_heading(string $heading, string $subtitle, ?string $actionHtml = null): void {
    echo '<section class="ite-dashboard-hero" aria-label="ITE dashboard">';
    echo '<img class="ite-dashboard-hero-logo" src="' . h(app_path('assets/images/ite-logo.png')) . '" alt="Institute of Technology Education logo" width="88" height="88">';
    echo '<div class="ite-dashboard-hero-copy"><p class="ite-dashboard-hero-eyebrow">RMMC · Institute of Technology Education</p><h1>' . h($heading) . '</h1><p>' . h($subtitle) . '</p></div>';
    if ($actionHtml !== null) {
        echo '<div class="ite-dashboard-hero-actions">' . $actionHtml . '</div>';
    }
    echo '</section>';
}

page_header('Dashboard',$u);
if ($role === 'Student'):
    $st = mine();
    if (!$st) {
        title_block('Student profile missing', 'Ask an administrator to complete your student record.');
        page_footer();
        exit;
    }

    $studentId = (int)$st['id'];
    $studentHistory = attendance_for_student($studentId);
    $latestHistory = array_slice($studentHistory, 0, 8);
    $fineRows = get_fines($studentId);
    $balance = outstanding($studentId);
    $unpaidRecords = count(array_filter($fineRows, static function (array $fine): bool {
        return $fine['status'] !== 'waived'
            && ((float)$fine['amount'] - (float)$fine['paid']) > 0.001;
    }));
    $semester = current_semester();
    $clearance = $semester
        ? one('SELECT status FROM clearances WHERE student_id=? AND semester_id=?', [$studentId, (int)$semester['id']])
        : null;

    // Use the same six-field student QR on the Dashboard and My QR Code.
    $studentQrPayload = student_qr_text($st);

    // Match the requested first-name / middle-initial / last-name layout.
    $middle = trim((string)($st['middle_name'] ?? ''));
    $middleInitial = $middle === '' ? '' : strtoupper(substr($middle, 0, 1)) . '.';
    $fullName = trim($st['first_name'] . ' ' . $middleInitial . ' ' . $st['last_name']);
    $initials = strtoupper(substr($st['first_name'], 0, 1) . substr($st['last_name'], 0, 1));
    $year = (int)$st['year_level'];
    $ordinal = ($year % 100 >= 11 && $year % 100 <= 13) ? 'th'
        : (match ($year % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' });
    $yearLabel = $year . $ordinal . ' Year';
    $program = (string)$st['program'];
    $programNames = [
        'BSIT' => 'BS Information Technology',
        'BSCS' => 'BS Computer Science',
        'BSIS' => 'BS Information Systems',
        'BLIS' => 'BL Information Science',
    ];
    $programFull = $programNames[strtoupper($program)] ?? $program;

    // Query only the participants displayed on this student's dashboard.
    $checkpointTimes = [];
    $participantIds = array_values(array_filter(array_map(
        'intval', array_column($latestHistory, 'participant_id')
    )));
    if ($participantIds) {
        $marks = implode(',', array_fill(0, count($participantIds), '?'));
        $scanRows = all(
            'SELECT sc.event_participant_id, sc.slot, sc.scanned_at '
            . 'FROM attendance_scans sc '
            . 'JOIN event_participants ep ON ep.id=sc.event_participant_id '
            . 'WHERE ep.student_id=? AND sc.event_participant_id IN (' . $marks . ')',
            array_merge([$studentId], $participantIds)
        );
        foreach ($scanRows as $scan) {
            $checkpointTimes[(int)$scan['event_participant_id']][(string)$scan['slot']] =
                date('h:i A', strtotime((string)$scan['scanned_at']));
        }
    }
?>
<div class="student-dashboard">
    <div class="student-dashboard-title">
        <div>
            <h1>Student profile</h1>
            <p>My dashboard / <?=h($fullName)?></p>
        </div>
        <a class="btn btn-primary student-dashboard-main-action" href="<?=h(app_path('settings.php'))?>">My settings</a>
    </div>

    <div class="student-dashboard-top">
        <section class="student-dashboard-card student-profile" aria-label="My student details">
            <div class="student-profile-overview">
                <span class="student-profile-avatar" aria-hidden="true"><?=h($initials)?></span>
                <div>
                    <h2><?=h($fullName)?></h2>
                    <p><?=h($st['student_number'])?> · <?=h($program)?> · <?=h($yearLabel)?></p>
                    <span class="student-profile-state">Active</span>
                </div>
            </div>
            <dl class="student-profile-details">
                <div><dt>Full name</dt><dd><?=h($fullName)?></dd></div>
                <div><dt>Student ID</dt><dd><?=h($st['student_number'])?></dd></div>
                <div><dt>Course</dt><dd><?=h($programFull)?></dd></div>
                <div><dt>Year level</dt><dd><?=h($yearLabel)?></dd></div>
                <?php if (trim((string)($st['section'] ?? '')) !== ''): ?>
                <div><dt>Section</dt><dd><?=h($st['section'])?></dd></div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="student-dashboard-card student-dashboard-qr" aria-label="My attendance QR code">
            <h2>Student QR code</h2>
            <p class="student-dashboard-desc">Scan for event attendance</p>
            <div class="student-qr-layout">
                <div class="student-qr-frame"><div id="student-dashboard-qr" role="img" aria-label="Student attendance QR code"></div></div>
                <div class="student-qr-description">
                    <h3>Student QR</h3>
                    <p>Assigned to <?=h($st['student_number'])?></p>
                    <p class="student-qr-note">Readable student details QR · do not share publicly</p>
                    <button type="button" class="btn btn-ghost student-qr-print" onclick="window.print()">Print student ID</button>
                    <a href="<?=h(app_path('qr.php'))?>" class="student-qr-view-link">View full QR and identification</a>
                </div>
            </div>
        </section>
    </div>

    <div class="student-dashboard-bottom">
        <section class="student-dashboard-card student-dashboard-attendance" aria-label="Recent student attendance">
            <div class="student-dashboard-section-heading">
                <div><h2>Recent attendance history</h2><p>Morning / afternoon checkpoints by event</p></div>
                <a href="<?=h(app_path('attendance.php'))?>">View all</a>
            </div>
            <?php if ($latestHistory): ?>
            <div class="student-dashboard-table-scroll">
                <table class="student-dashboard-table">
                    <thead><tr>
                        <th>Event</th><th>Morning In</th><th>Morning Out</th>
                        <th>Afternoon In</th><th>Afternoon Out</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($latestHistory as $attendance):
                        $pid = (int)$attendance['participant_id'];
                        $times = $checkpointTimes[$pid] ?? [];
                        $absent = $attendance['attendance_status'] === 'absent';
                    ?>
                        <tr>
                            <td class="student-event-name"><strong><?=h($attendance['title'])?></strong><small><?=h(date('M j, Y', strtotime($attendance['starts_at'])))?></small></td>
                            <?php foreach (['morning_in','morning_out','afternoon_in','afternoon_out'] as $slot): ?>
                            <td><?=h($times[$slot] ?? ($absent ? 'Absent' : '—'))?></td>
                            <?php endforeach; ?>
                            <td><?=status_badge((string)$attendance['attendance_status'])?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="student-dashboard-empty">No event attendance has been recorded yet.</p>
            <?php endif; ?>
            <p class="student-attendance-note">Only recorded checkpoints display a time. Missing entries are not automatically marked present.</p>
        </section>

        <section class="student-dashboard-card student-dashboard-finance" aria-label="My fines and semester clearance">
            <h2>Fines &amp; clearance</h2>
            <div class="student-finance-main">
                <p>Outstanding balance</p>
                <strong><?=pesos($balance)?></strong>
                <div class="student-clearance-badge">
                    <?php if ($semester): ?><?=status_badge((string)($clearance['status'] ?? 'pending'))?><?php else: ?><span class="badge">No semester</span><?php endif; ?>
                </div>
                <p class="student-finance-count">
                    <?=h($unpaidRecords)?> unpaid fine record<?= $unpaidRecords === 1 ? '' : 's' ?>
                </p>
                <?php if ($semester): ?>
                <p class="student-finance-semester"><?=h($semester['academic_year'])?> · Semester <?=h($semester['semester_number'])?></p>
                <?php endif; ?>
            </div>
            <div class="student-finance-actions">
                <a href="<?=h(app_path('fines.php'))?>" class="btn btn-primary">View payment history</a>
                <a href="<?=h(app_path('clearance.php'))?>" class="btn btn-ghost">Check clearance requirements</a>
            </div>
        </section>
    </div>
</div>
<script src="<?=h(app_path('assets/js/qrcode.min.js'))?>"></script>
<script>
(function () {
    const holder = document.getElementById('student-dashboard-qr');
    if (!holder) return;
    if (typeof QRCode !== 'function') {
        holder.textContent = 'QR generator unavailable. Open My QR Code from the menu.';
        return;
    }
    new QRCode(holder, {
        text: <?=json_encode($studentQrPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
        width: 210,
        height: 210,
        correctLevel: QRCode.CorrectLevel.M
    });
}());
</script>
<?php else:

$studentCount=(int)(one('SELECT COUNT(*) n FROM students s JOIN users u ON u.id=s.user_id WHERE u.is_active=1')['n']??0);
$eventCount=(int)(one("SELECT COUNT(*) n FROM events WHERE status IN ('published','ongoing')")['n']??0);
$scanCount=(int)(one('SELECT COUNT(*) n FROM attendance_scans WHERE DATE(scanned_at)=CURDATE()')['n']??0);
$unpaid=(float)(one("SELECT COALESCE(SUM(CASE WHEN f.status='waived' THEN 0 ELSE GREATEST(0,f.amount-COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.fine_id=f.id AND p.status='verified'),0)) END),0) balance FROM fines f")['balance']??0);
$title='Good day, '.$u['login_id'].'!';ite_dashboard_heading($title,'Here is your latest department attendance and event overview.','<a class="btn btn-primary" href="'.h(app_path('events.php?new=1')).'">+ Create Event</a>');
?>
<?php if ($role === 'Officer'):
    $officerProfile = one('SELECT first_name,middle_name,last_name,organization_name,position_title FROM staff_profiles WHERE user_id=?', [$u['id']]);
    $officerName = trim(($officerProfile['first_name'] ?? '') . ' ' . ($officerProfile['middle_name'] ?? '') . ' ' . ($officerProfile['last_name'] ?? ''));
?>
<section class="id-card" style="margin-bottom:22px">
    <div class="id-avatar"><?= h(strtoupper(substr($officerName ?: $u['login_id'], 0, 1))) ?></div>
    <div>
        <h2><?= h($officerName ?: $u['login_id']) ?></h2>
        <p>Officer · <?= h(($officerProfile['position_title'] ?? '') ?: 'Position not set') ?></p>
        <div class="id-meta"><span><?= h(($officerProfile['organization_name'] ?? '') ?: 'Organization not set') ?></span></div>
    </div>
    <a class="btn btn-primary" style="margin-left:auto" href="<?= h(app_path('qr.php')) ?>">My QR Code</a>
</section>
<?php endif; ?>
<div class="cards"><div class="card"><div class="kicker">Enrolled Students</div><div class="metric"><?=number_format($studentCount)?></div><div class="footnote">Active student accounts</div></div><div class="card"><div class="kicker">Active / Upcoming Events</div><div class="metric"><?=number_format($eventCount)?></div><div class="footnote">Published and ongoing</div></div><div class="card"><div class="kicker">QR Scans Today</div><div class="metric"><?=number_format($scanCount)?></div><div class="footnote">All attendance checkpoints</div></div><div class="card"><div class="kicker">Outstanding Fines</div><div class="metric accent"><?=pesos($unpaid)?></div><div class="footnote">Verified payments deducted</div></div></div>
<div class="grid-2"><section class="panel"><h2>Enrollment by program and year</h2><?php $pop=all('SELECT program,year_level,COUNT(*) n FROM students s JOIN users u ON u.id=s.user_id WHERE u.is_active=1 GROUP BY program,year_level ORDER BY program,year_level'); $max=max(array_map(fn($r)=>(int)$r['n'],$pop)?:[1]);foreach($pop as $r):?><a href="<?=h(app_path('students.php?program='.urlencode($r['program']).'&year='.urlencode($r['year_level'])))?>" class="bar-row"><span><?=h($r['program'])?> · Year <?=h($r['year_level'])?></span><div class="bar-track"><div class="bar-fill" style="width:<?=round((int)$r['n']/$max*100)?>%"></div></div><strong><?=h($r['n'])?></strong></a><?php endforeach; ?><?php if(!$pop):?><p class="empty">No enrolled students yet.</p><?php endif;?><a href="<?=h(app_path('students.php'))?>" class="btn btn-ghost btn-sm">View student directory</a></section><section class="panel"><div class="row between"><h2>Recent Events</h2><a href="<?=h(app_path('events.php'))?>">View all</a></div><div class="table-scroll"><table class="table"><thead><tr><th>Event</th><th>Schedule</th><th>Status</th></tr></thead><tbody><?php foreach(all('SELECT title,starts_at,status FROM events ORDER BY starts_at DESC LIMIT 8') as $e):?><tr><td><?=h($e['title'])?></td><td><?=h(substr($e['starts_at'],0,16))?></td><td><?=status_badge($e['status'])?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
<section class="panel"><h2>Quick actions</h2><div class="actions"><a class="btn btn-soft" href="<?=h(app_path('scan.php'))?>">Open QR Scanner</a><a class="btn btn-soft" href="<?=h(app_path('attendance.php'))?>">View Attendance</a><a class="btn btn-soft" href="<?=h(app_path('fines.php'))?>">Manage Payments</a><a class="btn btn-soft" href="<?=h(app_path('clearance.php'))?>">Process Clearance</a></div></section>
<?php endif;page_footer();
