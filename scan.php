<?php
/**
 * RMMC ITE – Dual-mode attendance: QR camera/USB + supervised Student ID backup.
 * Compatible with the existing event_attendance_slots and attendance_scans tables.
 * Does not modify or delete historical attendance, fines, payments or clearances.
 */
declare(strict_types=1);
require __DIR__ . '/app/services.php';
require_once __DIR__ . '/app/student_qr_payload.php';
$staff = require_role('Admin', 'Officer');

if (request_post()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');

    try {
        if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh this page and try again.');
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $slot = (string) ($_POST['slot'] ?? '');
        $mode = (string) ($_POST['mode'] ?? '');
        $raw = trim((string) ($_POST['value'] ?? ''));
        $source = (string) ($_POST['source'] ?? '');

        if ($eventId < 1 || !array_key_exists($slot, SLOTS)) {
            throw new RuntimeException('Choose an ongoing event and a valid attendance checkpoint.');
        }
        if (!in_array($mode, ['qr', 'student_id'], true)) {
            throw new RuntimeException('Choose Camera QR or Student ID attendance.');
        }
        if ($raw === '' || strlen($raw) > 512) {
            throw new RuntimeException('Enter or scan a valid student credential.');
        }

        $manualReason = null;
        if ($mode === 'student_id') {
            $source = 'manual_id';
            $allowedReasons = ['Camera unavailable', 'QR unavailable', 'Accessibility backup', 'Other supervised check-in'];
            $manualReason = (string) ($_POST['reason'] ?? '');
            if (strlen($raw) > 30 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
                throw new RuntimeException('The Student ID is invalid.');
            }
            if (!in_array($manualReason, $allowedReasons, true) || (string) ($_POST['verified'] ?? '') !== '1') {
                throw new RuntimeException('Choose a backup reason and confirm the student identity before saving.');
            }
            $studentSql = 'SELECT s.id,s.student_number,s.first_name,s.last_name,s.program,s.year_level
                           FROM students s JOIN users u ON u.id=s.user_id
                           WHERE s.student_number=? AND u.is_active=1 LIMIT 1';
            $lookup = $raw;
        } else {
            if (!in_array($source, ['camera', 'qr_reader'], true)) {
                throw new RuntimeException('Invalid QR scan source.');
            }
            // Old 64-char QR tokens remain valid so existing printed cards still work.
            $token = strncasecmp($raw, 'rmmc:', 5) === 0 ? substr($raw, 5) : $raw;
            $readableQr = !preg_match('/\A[a-f0-9]{64}\z/iD', $token);
            if ($readableQr) {
                $number = student_qr_read_number($raw);
                if ($number === null) {
                    throw new RuntimeException('Invalid student QR format. It must contain the six required student fields.');
                }
                $studentSql = 'SELECT s.id,s.student_number,s.first_name,s.middle_name,s.last_name,s.program,s.year_level
                               FROM students s JOIN users u ON u.id=s.user_id
                               WHERE s.student_number=? AND u.is_active=1 LIMIT 1';
                $lookup = $number;
            } else {
                $studentSql = 'SELECT s.id,s.student_number,s.first_name,s.middle_name,s.last_name,s.program,s.year_level
                               FROM students s JOIN users u ON u.id=s.user_id
                               WHERE s.qr_token=? AND u.is_active=1 LIMIT 1';
                $lookup = $token;
            }
        }

        $event = one('SELECT id,status FROM events WHERE id=?', [$eventId]);
        if (!$event || $event['status'] !== 'ongoing') {
            throw new RuntimeException('This event is not ongoing. Ask the Officer to update its status.');
        }
        $window = one(
            'SELECT opens_at,closes_at FROM event_attendance_slots
             WHERE event_id=? AND slot=? AND required=1',
            [$eventId, $slot]
        );
        if (!$window) {
            throw new RuntimeException('This checkpoint has not been enabled for the selected event.');
        }
        $now = date('Y-m-d H:i:s');
        if ($now < $window['opens_at'] || $now > $window['closes_at']) {
            throw new RuntimeException('This checkpoint is closed. Valid window: '
                . substr($window['opens_at'], 0, 16) . ' to ' . substr($window['closes_at'], 0, 16) . '.');
        }

        $student = one($studentSql, [$lookup]);
        if (!$student) {
            throw new RuntimeException($mode === 'qr'
                ? 'QR code not recognized or student account inactive.'
                : 'Student ID not found or student account inactive.');
        }
        if ($mode === 'qr' && !empty($readableQr)
            && !hash_equals(student_qr_text($student), student_qr_normalize_scanned($raw))) {
            throw new RuntimeException('The QR details do not match the current student record. Refresh the student QR and try again.');
        }

        db()->beginTransaction();
        // Lock this student's event-participant row to avoid concurrent duplicate entry.
        $participant = one(
            'SELECT id,attendance_status FROM event_participants
             WHERE event_id=? AND student_id=? FOR UPDATE',
            [$eventId, $student['id']]
        );
        if (!$participant) {
            throw new RuntimeException('Student is not registered for this event. Check Events → Participants.');
        }
        if (in_array($participant['attendance_status'], ['absent', 'excused'], true)) {
            throw new RuntimeException('This attendance record is already finalized or excused. Use Attendance → Manual Correction.');
        }

        // UNIQUE(event_participant_id, slot) guarantees one recording per checkpoint.
        run_sql(
            'INSERT INTO attendance_scans(event_participant_id,slot,scanned_at,scanned_by)
             VALUES(?,?,NOW(),?)',
            [$participant['id'], $slot, $staff['id']]
        );

        $required = (int) (one(
            'SELECT COUNT(*) AS n FROM event_attendance_slots WHERE event_id=? AND required=1',
            [$eventId]
        )['n'] ?? 0);
        $completed = (int) (one(
            'SELECT COUNT(*) AS n FROM attendance_scans sc
             JOIN event_attendance_slots es ON es.event_id=? AND es.slot=sc.slot AND es.required=1
             WHERE sc.event_participant_id=?',
            [$eventId, $participant['id']]
        )['n'] ?? 0);

        // Preserve staff-approved late/present corrections, otherwise derive status from required slots.
        $oldStatus = (string) $participant['attendance_status'];
        $newStatus = in_array($oldStatus, ['present', 'late'], true)
            ? $oldStatus
            : (($required > 0 && $completed >= $required) ? 'present' : 'pending');

        $times = one(
            "SELECT MIN(CASE WHEN slot IN ('morning_in','afternoon_in') THEN scanned_at END) AS time_in,
                    MAX(CASE WHEN slot IN ('morning_out','afternoon_out') THEN scanned_at END) AS time_out
             FROM attendance_scans WHERE event_participant_id=?",
            [$participant['id']]
        );
        $isIn = str_ends_with($slot, '_in');
        run_sql(
            'INSERT INTO attendance(event_participant_id,time_in,time_out,status,scanned_in_by,scanned_out_by)
             VALUES(?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
             time_in=CASE WHEN time_in IS NULL THEN VALUES(time_in) WHEN VALUES(time_in) IS NULL THEN time_in ELSE LEAST(time_in,VALUES(time_in)) END,
             time_out=CASE WHEN time_out IS NULL THEN VALUES(time_out) WHEN VALUES(time_out) IS NULL THEN time_out ELSE GREATEST(time_out,VALUES(time_out)) END,
             status=VALUES(status),
             scanned_in_by=COALESCE(VALUES(scanned_in_by),scanned_in_by),
             scanned_out_by=COALESCE(VALUES(scanned_out_by),scanned_out_by)',
            [$participant['id'], $times['time_in'], $times['time_out'], $newStatus,
             $isIn ? $staff['id'] : null, $isIn ? null : $staff['id']]
        );
        run_sql('UPDATE event_participants SET attendance_status=? WHERE id=?', [$newStatus, $participant['id']]);
        $attendance = one('SELECT id FROM attendance WHERE event_participant_id=?', [$participant['id']]);

        $methodLabel = $mode === 'student_id' ? 'Manual Student ID (' . $manualReason . ')'
            : ($source === 'camera' ? 'Camera QR' : 'USB / pasted QR');
        run_sql(
            'INSERT INTO attendance_logs(attendance_id,action,performed_by,new_value,remarks)
             VALUES(?,?,?,?,?)',
            [$attendance['id'], $isIn ? 'time_in' : 'time_out', $staff['id'], $now,
             $methodLabel . ' · ' . SLOTS[$slot]]
        );
        audit('attendance_recorded', 'event_participants', (int) $participant['id'], [
            'event_id' => $eventId, 'slot' => $slot, 'method' => $methodLabel,
            'attendance_status' => $newStatus,
        ]);
        db()->commit();

        echo json_encode([
            'ok' => true,
            'student' => $student['last_name'] . ', ' . $student['first_name'],
            'student_number' => $student['student_number'],
            'program' => $student['program'],
            'year_level' => (int) $student['year_level'],
            'checkpoint' => SLOTS[$slot],
            'progress' => $completed . '/' . $required,
            'status' => $newStatus,
            'message' => SLOTS[$slot] . ' saved successfully via ' . $methodLabel . '.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Attendance insert error: ' . $e->getMessage());
        $duplicate = $e->getCode() === '23000';
        http_response_code($duplicate ? 409 : 500);
        echo json_encode(['ok' => false, 'message' => $duplicate
            ? 'Already recorded: this student has checked in/out for the selected checkpoint.'
            : 'Could not save attendance. Check the PHP error log.']);
        exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$events = all("SELECT id,title,starts_at FROM events WHERE status='ongoing' ORDER BY starts_at DESC LIMIT 100");
$eventId = (int) ($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$selected = $eventId ? one("SELECT id,title FROM events WHERE id=? AND status='ongoing'", [$eventId]) : null;
if (!$selected) $eventId = 0;
$slots = $eventId ? all(
    'SELECT slot,opens_at,closes_at FROM event_attendance_slots
     WHERE event_id=? AND required=1 ORDER BY opens_at', [$eventId]
) : [];
$canRecord = $eventId > 0 && count($slots) > 0;

page_header('Attendance Scanner', $staff);
title_block('Attendance Scanner', 'Camera QR scanning + supervised Student ID backup for all four checkpoints.', '<a class="btn btn-soft" href="' . h(app_path('attendance-import.php')) . '">Import CSV / Excel Attendance</a>');
?>
<link rel="stylesheet" href="<?=h(app_path('assets/css/attendance-scanner.css'))?>">
<section class="panel">
    <div class="form-grid wide scan-setup">
        <label>Ongoing Event
            <select id="attendanceEvent">
                <?php foreach ($events as $ev): ?>
                    <option value="<?=h($ev['id'])?>" <?=$eventId === (int) $ev['id'] ? 'selected' : ''?>>
                        <?=h($ev['title'] . ' · ' . substr($ev['starts_at'], 0, 10))?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Attendance Checkpoint
            <select id="attendanceSlot">
                <?php foreach ($slots as $sl): ?>
                    <option value="<?=h($sl['slot'])?>">
                        <?=h((SLOTS[$sl['slot']] ?? $sl['slot']) . ' · '
                            . substr($sl['opens_at'], 11, 5) . '–' . substr($sl['closes_at'], 11, 5))?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <p class="hint">Select the event and correct checkpoint before recording. Entries must be within the scheduled time window. Verify the student in person: the six QR fields are public and copyable.</p>
    <?php if (!$events): ?>
        <div class="alert alert-warning">There are no ongoing events. Set an event to Ongoing in Events first.</div>
    <?php elseif (!$slots): ?>
        <div class="alert alert-warning">No attendance checkpoints are configured for this event. Set the four checkpoints in Events → Edit.</div>
    <?php endif; ?>
</section>

<div class="grid-2 attendance-methods">
    <section class="panel" aria-labelledby="cameraHeading">
        <h2 id="cameraHeading">Option C — Camera QR Scanner</h2>
        <p class="hint">Point the device camera at the student QR. Successful scans save automatically.</p>
        <div id="qrReader" class="camera-reader" aria-label="Camera preview"></div>
        <video id="nativePreview" class="scanner-video native-preview" autoplay muted playsinline hidden></video>
        <div class="row scan-controls">
            <button type="button" class="btn btn-primary" id="startCamera" <?=$canRecord ? '' : 'disabled'?>>Start Camera</button>
            <button type="button" class="btn btn-ghost" id="stopCamera">Stop Camera</button>
        </div>
        <p class="hint" id="cameraStatus" role="status">Camera requires HTTPS or localhost. Manual Student ID works on rmmc-ite.local.</p>
        <div class="divider"></div>
        <label>QR reader output (six student details) or legacy token
            <textarea id="qrValue" autocomplete="off" placeholder="Paste the six student details shown by a QR reader, or an old token" maxlength="512" rows="6"></textarea>
        </label>
        <button class="btn btn-soft full" type="button" id="submitQr" <?=$canRecord ? '' : 'disabled'?>>Record QR Value</button>
    </section>

    <section class="panel" aria-labelledby="manualHeading">
        <h2 id="manualHeading">Option A — Student ID Backup</h2>
        <p class="hint">No camera required. Officers must verify the student's identity before recording.</p>
        <form id="manualForm" class="form-stack" autocomplete="off">
            <label>Student Number
                <input id="studentNumber" maxlength="30" placeholder="Example: 2411600165" required>
            </label>
            <label>Reason for manual attendance
                <select id="manualReason" required>
                    <option value="Camera unavailable">Camera unavailable</option>
                    <option value="QR unavailable">QR unavailable</option>
                    <option value="Accessibility backup">Accessibility backup</option>
                    <option value="Other supervised check-in">Other supervised check-in</option>
                </select>
            </label>
            <label class="checkline scan-verify"><input type="checkbox" id="manualVerified" required> I verified the student's identity.</label>
            <button type="submit" class="btn btn-primary full" <?=$canRecord ? '' : 'disabled'?>>Record Student ID Attendance</button>
        </form>
        <p class="hint">Manual attendance is recorded with the Officer's account and the reason selected. Duplicate checkpoint entries are blocked.</p>
    </section>
</div>

<section class="panel">
    <h2>Latest Attendance Result</h2>
    <div id="scanResult" class="scan-result" role="status" aria-live="polite">Ready. Select the event and checkpoint above, then scan a QR or enter a Student ID.</div>
    <div class="row scan-result-actions">
        <a class="btn btn-soft" href="<?=h(app_path('attendance.php?event_id=' . $eventId))?>">View Attendance Records</a>
        <a class="btn btn-ghost" href="<?=h(app_path('events.php'))?>">Manage Events</a>
    </div>
</section>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js" defer></script>
<script>
window.RMMC_ATTENDANCE_CONFIG = {
  endpoint: <?=json_encode(app_path('scan.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
  csrf: <?=json_encode(csrf(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
  canRecord: <?=$canRecord ? 'true' : 'false'?>
};
</script>
<script src="<?=h(app_path('assets/js/attendance-scanner.js'))?>" defer></script>
<?php page_footer();
