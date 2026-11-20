<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
const SLOTS = ['morning_in'=>'Morning In','morning_out'=>'Morning Out','afternoon_in'=>'Afternoon In','afternoon_out'=>'Afternoon Out'];
function current_semester(): ?array {
    return one("SELECT * FROM semesters WHERE status='active' ORDER BY start_date DESC LIMIT 1")
        ?? one('SELECT * FROM semesters ORDER BY start_date DESC LIMIT 1');
}
function sync_participants(int $eventId): void {
    // No surprise changes to historical events: only add eligible students to active/upcoming events.
    $event=one('SELECT status FROM events WHERE id=?', [$eventId]);
    if (!$event || !in_array($event['status'], ['published','ongoing'], true)) return;
    run_sql('INSERT IGNORE INTO event_participants (event_id,student_id,is_required) SELECT ?,s.id,1 FROM students s JOIN users u ON u.id=s.user_id WHERE u.is_active=1',[$eventId]);
}
function fine_balance_sql(): string {
    return 'SELECT f.id, f.student_id, f.amount, f.reason, f.status, f.attendance_slot, e.title, e.semester_id, e.id AS event_id,
       COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.fine_id=f.id AND p.status=\'verified\'),0) AS paid
       FROM fines f JOIN event_participants ep ON ep.id=f.event_participant_id JOIN events e ON e.id=ep.event_id';
}
function get_fines(?int $studentId=null, ?int $semesterId=null): array {
    $where=[]; $p=[];
    if ($studentId !== null) { $where[]='f.student_id=?'; $p[]=$studentId; }
    if ($semesterId !== null) { $where[]='e.semester_id=?'; $p[]=$semesterId; }
    return all(fine_balance_sql() . ($where ? ' WHERE '.implode(' AND ',$where):'') . ' ORDER BY f.assessed_at DESC LIMIT 300', $p);
}
function outstanding(int $studentId, ?int $semesterId = null): float {
    $params=[$studentId];
    $where=' WHERE f.student_id=?';
    if($semesterId!==null){$where.=' AND e.semester_id=?';$params[]=$semesterId;}
    $row=one("SELECT COALESCE(SUM(CASE WHEN f.status='waived' THEN 0 ELSE GREATEST(0,f.amount-COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.fine_id=f.id AND p.status='verified'),0)) END),0) balance FROM fines f JOIN event_participants ep ON ep.id=f.event_participant_id JOIN events e ON e.id=ep.event_id".$where,$params);
    return round((float)($row['balance']??0),2);
}
function attendance_for_student(int $studentId): array {
    return all('SELECT e.title,e.starts_at,ep.attendance_status, a.time_in,a.time_out,e.id AS event_id,ep.id AS participant_id
        FROM event_participants ep JOIN events e ON e.id=ep.event_id LEFT JOIN attendance a ON a.event_participant_id=ep.id
        WHERE ep.student_id=? ORDER BY e.starts_at DESC LIMIT 100',[$studentId]);
}
function clearance_blockers(int $studentId, int $semesterId): array {
    $issues=[]; $unpaid=outstanding($studentId,$semesterId);
    if($unpaid>0.001) $issues[]='Outstanding fine balance: '.pesos($unpaid);
    $pending=(int)(one("SELECT COUNT(*) AS n FROM event_participants ep JOIN events e ON e.id=ep.event_id
        WHERE ep.student_id=? AND e.semester_id=? AND ep.is_required=1 AND e.status IN ('published','ongoing','draft')",[$studentId,$semesterId])['n']??0);
    if($pending) $issues[]="$pending required event(s) have not yet been completed.";
    $unresolved=(int)(one("SELECT COUNT(*) AS n FROM event_participants ep JOIN events e ON e.id=ep.event_id
        WHERE ep.student_id=? AND e.semester_id=? AND ep.is_required=1 AND e.status='completed' AND ep.attendance_status='pending'",[$studentId,$semesterId])['n']??0);
    if($unresolved) $issues[]="$unresolved attendance record(s) still need review or finalization.";
    return $issues;
}
/**
 * Assess one ₱20 fine for every required, unrecorded checkpoint.
 * Called only after the event and each checkpoint have closed.
 * Unique(event_participant_id,attendance_slot) makes this safely repeatable.
 * Existing historical event-level fines (attendance_slot IS NULL) remain untouched.
 */
function finalize_event(int $eventId, int $staffId): int {
    db()->beginTransaction();
    try {
        $event = one('SELECT * FROM events WHERE id=? FOR UPDATE', [$eventId]);
        if (!$event || $event['status'] !== 'completed' || strtotime($event['ends_at']) > time()) {
            throw new RuntimeException('Complete the event and wait until its end time before assessing fines.');
        }

        $slots = all('SELECT slot, closes_at FROM event_attendance_slots WHERE event_id=? AND required=1', [$eventId]);
        if (!$slots) throw new RuntimeException('No required checkpoints are configured for this event.');
        foreach ($slots as $slot) {
            if (strtotime($slot['closes_at']) > time()) {
                throw new RuntimeException('The '.(SLOTS[$slot['slot']] ?? $slot['slot']).' checkpoint has not closed yet.');
            }
        }

        $participants = all(
            'SELECT ep.id, ep.student_id, ep.attendance_status
             FROM event_participants ep WHERE ep.event_id=? AND ep.is_required=1 FOR UPDATE',
            [$eventId]
        );
        $added = 0;
        foreach ($participants as $p) {
            // Approved event-wide excuses / staff-present corrections are not fined.
            if (in_array($p['attendance_status'], ['excused','present','late'], true)) continue;

            // Preserve all old pre-migration event-level fines as-is. Do not double-bill.
            $legacy = one('SELECT id FROM fines WHERE event_participant_id=? AND attendance_slot IS NULL LIMIT 1', [$p['id']]);
            if ($legacy) continue;

            $scanned = all('SELECT slot FROM attendance_scans WHERE event_participant_id=?', [$p['id']]);
            $scannedSet = array_fill_keys(array_column($scanned, 'slot'), true);
            $missingCount = 0;
            foreach ($slots as $slot) {
                $key = (string)$slot['slot'];
                if (isset($scannedSet[$key])) continue;
                $missingCount++;

                // Repeat finalization cannot recreate paid or waived fines.
                $already = one('SELECT id FROM fines WHERE event_participant_id=? AND attendance_slot=? LIMIT 1', [$p['id'], $key]);
                if ($already) continue;

                run_sql(
                    "INSERT INTO fines(student_id,event_participant_id,attendance_slot,amount,reason,status,assessed_by)
                     VALUES(?,?,?,?,?,'unpaid',?)",
                    [(int)$p['student_id'], (int)$p['id'], $key, 20.00,
                     'Missed required checkpoint: '.(SLOTS[$key] ?? $key), $staffId]
                );
                $added++;
            }

            $requiredCount = count($slots);
            $recordedCount = $requiredCount - $missingCount;
            $newStatus = $missingCount === 0 ? 'present' : ($recordedCount === 0 ? 'absent' : 'partial');
            run_sql('UPDATE event_participants SET attendance_status=? WHERE id=?', [$newStatus, $p['id']]);
            run_sql('INSERT INTO attendance(event_participant_id,status) VALUES(?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)', [$p['id'], $newStatus]);

            if ($missingCount > 0) {
                run_sql(
                    "UPDATE clearances SET status='blocked',cleared_by=NULL,cleared_at=NULL,
                      remarks='Required attendance checkpoint fine assessed after prior clearance'
                     WHERE student_id=? AND semester_id=? AND status='cleared'",
                    [$p['student_id'], $event['semester_id']]
                );
            }
        }
        audit('finalize_checkpoint_fines', 'events', $eventId, [
            'new_fine_records' => $added, 'amount_per_checkpoint' => 20,
        ]);
        db()->commit();
        return $added;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}
