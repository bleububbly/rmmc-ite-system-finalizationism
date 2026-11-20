<?php
declare(strict_types=1);
require __DIR__ . '/app/services.php';

$user = require_login();
$isStaff = in_array($user['role'], ['Admin', 'Officer'], true);

/** Display the student exactly the same way across the ledger and receipts. */
function payment_student_name(array $row): string {
    $middle = trim((string)($row['middle_name'] ?? ''));
    $initial = $middle === '' ? '' : ' ' . (preg_match('/^./u', $middle, $found) ? $found[0] : substr($middle, 0, 1)) . '.';
    return trim((string)$row['last_name'] . ', ' . (string)$row['first_name'] . $initial);
}

/** Convert validated decimal currency text to integer centavos (no float input rounding). */
function payment_centavos(string $value): ?int {
    $value = trim($value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $value)) return null;
    $pieces = explode('.', $value, 2);
    $cents = (int)$pieces[0] * 100 + (int)str_pad($pieces[1] ?? '0', 2, '0');
    return $cents > 0 ? $cents : null;
}

function money_cents($value): int {
    return (int)round((float)$value * 100);
}

// Staff choose a student; Student accounts only see their own history.
$studentId = $isStaff ? max(0, (int)($_GET['student_id'] ?? 0)) : (int)(mine()['id'] ?? 0);
if (!$isStaff && $studentId < 1) fail('Student profile not found.', 404);

if (request_post()) {
    check_post();
    if (!$isStaff) fail('Only Admin and Officer may record student payments.', 403);
    $id = (int)($_POST['student_id'] ?? 0);
    $amountInput = (string)($_POST['amount'] ?? '');
    $amountCents = payment_centavos($amountInput);
    $reference = trim((string)($_POST['reference'] ?? ''));
    $method = (string)($_POST['method'] ?? 'cash');
    $back = 'fines.php' . ($id > 0 ? '?student_id=' . $id : '');

    if ($id < 1 || $amountCents === null || $reference === '' || strlen($reference) > 100 ||
        preg_match('/[\x00-\x1F\x7F]/', $reference) ||
        !in_array($method, ['cash', 'gcash', 'bank_transfer', 'other'], true)) {
        flash('Select a student and enter a valid payment amount, unique receipt number, and payment method.', 'error');
        go($back);
    }

    try {
        db()->beginTransaction();
        // Serialize simultaneous payments against the same student.
        $student = one('SELECT id, student_number, first_name, last_name FROM students WHERE id=? FOR UPDATE', [$id]);
        if (!$student) throw new RuntimeException('Student not found.');

        // Lock all this student's fine rows so a repeated/parallel payment cannot double-charge.
        $fineRows = all("SELECT id, amount, status FROM fines WHERE student_id=? ORDER BY assessed_at, id FOR UPDATE", [$id]);
        $allocations = [];
        $stillToAllocate = $amountCents;
        $totalUnpaidCents = 0;
        foreach ($fineRows as $fine) {
            if ($fine['status'] === 'waived') continue;
            $paid = one("SELECT COALESCE(SUM(pa.amount),0) AS paid
                         FROM payment_allocations pa
                         JOIN payments p ON p.id=pa.payment_id
                         WHERE pa.fine_id=? AND p.status='verified'", [(int)$fine['id']]);
            $alreadyCents = money_cents($paid['paid'] ?? 0);
            $fineCents = money_cents($fine['amount']);
            $remainingCents = max(0, $fineCents - $alreadyCents);
            $totalUnpaidCents += $remainingCents;
            if ($remainingCents === 0 || $stillToAllocate === 0) continue;
            $allocate = min($stillToAllocate, $remainingCents);
            $allocations[] = [
                'fine_id' => (int)$fine['id'],
                'cents' => $allocate,
                'new_status' => ($alreadyCents + $allocate >= $fineCents) ? 'paid' : 'partially_paid',
            ];
            $stillToAllocate -= $allocate;
        }
        if ($totalUnpaidCents === 0) throw new RuntimeException('This student has no unpaid fines.');
        if ($stillToAllocate > 0) {
            throw new RuntimeException('Payment exceeds the student\'s remaining fine balance of ' . pesos($totalUnpaidCents / 100) . '.');
        }
        if (one('SELECT id FROM payments WHERE reference_number=? LIMIT 1', [$reference])) {
            throw new RuntimeException('That receipt/reference number has already been used. Enter a new one.');
        }

        // Create one verified payment, allocated across one or more unpaid fines.
        run_sql("INSERT INTO payments
                 (student_id, amount, reference_number, payment_method, status, received_by, verified_by, paid_at, verified_at)
                 VALUES (?,?,?,?,'verified',?,?,NOW(),NOW())",
            [$id, number_format($amountCents / 100, 2, '.', ''), $reference, $method, $user['id'], $user['id']]
        );
        $paymentId = (int)db()->lastInsertId();
        foreach ($allocations as $allocation) {
            run_sql('INSERT INTO payment_allocations(payment_id, fine_id, amount) VALUES(?,?,?)',
                [$paymentId, $allocation['fine_id'], number_format($allocation['cents'] / 100, 2, '.', '')]
            );
            run_sql('UPDATE fines SET status=? WHERE id=?', [$allocation['new_status'], $allocation['fine_id']]);
        }
        audit('payment_verified', 'payments', $paymentId, [
            'student_id' => $id, 'student_number' => $student['student_number'],
            'amount' => number_format($amountCents / 100, 2, '.', ''),
            'receipt' => $reference, 'fine_ids' => array_column($allocations, 'fine_id'),
        ]);
        db()->commit();
        flash('Payment recorded for ' . $student['last_name'] . ', ' . $student['first_name'] .
            ' (' . $student['student_number'] . '): ' . pesos($amountCents / 100) . '. Receipt: ' . $reference . '.');
        go($back);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('RMMC payment failure: ' . $e->getMessage());
        $publicMessage = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The payment could not be saved. Check that the receipt is unique and retry.';
        flash('Unable to save payment: ' . $publicMessage, 'error');
        go($back);
    }
}

// Student directory combines aggregate balance and verified payment figures.
$students = [];
$selected = null;
if ($isStaff) {
    $students = all("SELECT s.id,s.student_number,s.first_name,s.middle_name,s.last_name,s.program,s.year_level,
        COALESCE(fb.fine_count,0) AS fine_count,
        COALESCE(fb.balance,0) AS balance,
        COALESCE(ps.paid_total,0) AS paid_total,
        COALESCE(ps.payment_count,0) AS payment_count,
        ps.last_payment
        FROM students s
        LEFT JOIN (
            SELECT f.student_id,COUNT(*) AS fine_count,
              SUM(CASE WHEN f.status='waived' THEN 0 ELSE GREATEST(0, f.amount-COALESCE(a.paid,0)) END) AS balance
            FROM fines f
            LEFT JOIN (
                SELECT pa.fine_id,SUM(pa.amount) AS paid
                FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id
                WHERE p.status='verified' GROUP BY pa.fine_id
            ) a ON a.fine_id=f.id
            GROUP BY f.student_id
        ) fb ON fb.student_id=s.id
        LEFT JOIN (
            SELECT student_id,SUM(amount) AS paid_total, COUNT(*) AS payment_count, MAX(paid_at) AS last_payment
            FROM payments WHERE status='verified' GROUP BY student_id
        ) ps ON ps.student_id=s.id
        ORDER BY s.last_name,s.first_name,s.student_number");
    foreach ($students as $s) {
        if ($studentId > 0 && (int)$s['id'] === $studentId) { $selected = $s; break; }
    }
    if ($studentId > 0 && !$selected) fail('Student not found.', 404);
} else {
    $selected = one('SELECT id,student_number,first_name,middle_name,last_name,program,year_level FROM students WHERE id=?', [$studentId]);
}

$fines = $isStaff && !$studentId ? get_fines() : get_fines($studentId);
$owing = $selected ? outstanding($studentId) : array_sum(array_map(fn($s) => (float)$s['balance'], $students));
$paymentCount = (int)(one("SELECT COUNT(*) AS n FROM payments WHERE status='verified'" .
    (($isStaff && $studentId === 0) ? '' : ' AND student_id=?'),
    ($isStaff && $studentId === 0) ? [] : [$studentId])['n'] ?? 0);

$paymentSql = "SELECT p.id,p.reference_number,p.student_id,p.amount,p.payment_method,p.status,p.paid_at,
                    s.student_number,s.first_name,s.middle_name,s.last_name,
                    u.login_id AS recorded_by,
                    (SELECT GROUP_CONCAT(
                        DISTINCT CONCAT(e.title, ' — ', COALESCE(REPLACE(f.attendance_slot,'_',' '),'Historical fine'))
                        SEPARATOR '; '
                     ) FROM payment_allocations pa
                     JOIN fines f ON f.id=pa.fine_id
                     JOIN event_participants ep ON ep.id=f.event_participant_id
                     JOIN events e ON e.id=ep.event_id
                     WHERE pa.payment_id=p.id) AS covered_fines
               FROM payments p JOIN students s ON s.id=p.student_id
               LEFT JOIN users u ON u.id=p.verified_by";
$paymentParams = [];
if (!$isStaff || $studentId > 0) { $paymentSql .= ' WHERE p.student_id=?'; $paymentParams[] = $studentId; }
$payments = all($paymentSql . ' ORDER BY p.paid_at DESC,p.id DESC LIMIT 150', $paymentParams);

page_header('Fines & Payments', $user);
title_block($isStaff ? 'Student Fines & Payments' : 'My Fines & Payments',
    $isStaff ? 'Select a student, record verified payments, and see who has paid.' : 'Your assessed fines and payment history.',
    $isStaff && $studentId > 0 ? '<a class="btn btn-ghost" href="'.h(app_path('fines.php')).'">← All Students</a>' : null);
?>
<style>
.pay-directory{width:100%;min-width:750px}
.pay-directory td:nth-child(1){font-weight:700}
.pay-student{border:1px solid #f0d7d9;border-radius:11px;padding:18px;background:#fffafa;margin:17px 0}
.pay-student h2{margin:0}
.pay-student p{color:#697386;margin:4px 0 0}
.pay-student form{margin-top:13px}
.pay-money{white-space:nowrap;font-weight:700}
.pay-muted{color:#667085}
.pay-positive{color:#157347}
.pay-title{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.pay-label{font-size:12px;color:#606b7d;margin-bottom:4px}
.pay-top{display:flex;align-items:center;gap:13px;flex-wrap:wrap}
.pay-btn{margin-top:9px}
</style>

<div class="cards">
 <div class="card"><div class="kicker"><?= $selected ? 'Student' : 'Students' ?></div><div class="metric"><?= $selected ? h($selected['student_number']) : h(count($students)) ?></div></div>
 <div class="card"><div class="kicker">Outstanding Balance</div><div class="metric accent"><?= pesos($owing) ?></div></div>
 <div class="card"><div class="kicker">Verified Payments</div><div class="metric"><?= $paymentCount ?></div></div>
 <div class="card"><div class="kicker">Penalty per Missed Checkpoint</div><div class="metric">₱20</div><div class="footnote">Morning In · Morning Out · Afternoon In · Afternoon Out</div></div>
</div>

<?php if ($isStaff): ?>
<section class="panel">
 <h2>Choose Student Who Will Pay</h2>
 <p class="hint">Choose by name and official Student Number. You can also click “Record Payment” beside a student in the directory below.</p>
 <form method="get" class="form-grid wide" style="align-items:end">
  <label style="grid-column:span 2">Student Name / Student Number
   <select name="student_id" required>
    <option value="">— Select student —</option>
    <?php foreach ($students as $s): ?>
     <option value="<?= (int)$s['id'] ?>" <?= $studentId === (int)$s['id'] ? 'selected' : '' ?>>
       <?= h(payment_student_name($s).' · '.$s['student_number'].' · '.$s['program'].' / Year '.$s['year_level']) ?>
     </option>
    <?php endforeach; ?>
   </select>
  </label>
  <button class="btn btn-primary">View Student Fines</button>
 </form>
</section>
<?php if ($selected): ?>
<section class="panel">
 <div class="pay-title">
  <div><h2><?= h(payment_student_name($selected)) ?></h2>
   <div class="pay-muted">Student Number: <?= h($selected['student_number']) ?> · <?= h($selected['program']) ?> · Year <?= h($selected['year_level']) ?></div>
  </div>
  <span class="badge <?= $owing > 0.001 ? 'badge-unpaid' : 'badge-paid' ?>"><?= $owing > 0.001 ? 'Balance Due: '.pesos($owing) : 'No Outstanding Fine' ?></span>
 </div>
 <?php if ($owing > 0.001): ?>
  <div class="pay-student">
   <h2>Record Verified Payment</h2>
   <p>Only record a payment after you have actually received or confirmed the money. A single payment automatically covers the oldest unpaid fines first.</p>
   <form method="post" class="form-grid wide" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="student_id" value="<?= (int)$studentId ?>">
    <label>Payment Amount (₱)
     <input type="number" name="amount" min="0.01" step="0.01" max="<?= h(number_format($owing, 2, '.', '')) ?>" value="<?= h(number_format($owing, 2, '.', '')) ?>" required>
    </label>
    <label>Unique Receipt / Reference Number
     <input name="reference" maxlength="100" placeholder="e.g. RMMC-2026-0001" required autocomplete="off">
    </label>
    <label>Payment Method
     <select name="method" required><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_transfer">Bank Transfer</option><option value="other">Other</option></select>
    </label>
    <button class="btn btn-primary">Record Payment for <?= h($selected['student_number']) ?></button>
   </form>
   <p class="hint" style="margin-top:10px">Partial payments are allowed. A verified payment will update the paid amount of each covered fine; it does not automatically approve semester clearance.</p>
  </div>
 <?php else: ?><p class="hint" style="margin-top:16px">There are no unpaid fines for this student. No payment is needed.</p><?php endif; ?>
</section>
<?php endif; ?>
<section class="panel">
 <div class="pay-title"><h2>Student Payment Directory</h2><span class="hint"><?= count($students) ?> student(s) · Verified payments only</span></div>
 <label style="max-width:460px;margin:10px 0 16px">Find a student by name or Student Number
  <input type="search" id="paymentStudentSearch" placeholder="Search student names or numbers" autocomplete="off">
 </label>
 <div class="table-scroll"><table class="table pay-directory" id="paymentStudentTable">
  <thead><tr><th>Student Number</th><th>Student Name</th><th>Course / Year</th><th>Fine Balance</th><th>Total Paid</th><th>Last Payment</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($students as $s): ?>
   <tr>
    <td><?= h($s['student_number']) ?></td>
    <td><?= h(payment_student_name($s)) ?></td>
    <td><?= h($s['program']) ?> / Year <?= h($s['year_level']) ?></td>
    <td class="pay-money"><?= pesos($s['balance']) ?></td>
    <td class="pay-money pay-positive"><?= pesos($s['paid_total']) ?></td>
    <td><?= h($s['last_payment'] ?? '—') ?></td>
    <td><a class="btn btn-soft btn-sm" href="<?= h(app_path('fines.php?student_id='.$s['id'])) ?>"><?= (float)$s['balance'] > 0.001 ? 'Record Payment' : 'View History' ?></a></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if (!$students): ?><p class="empty">No students enrolled.</p><?php endif; ?>
  <script>
   (function () {
     const input = document.getElementById('paymentStudentSearch');
     const rows = document.querySelectorAll('#paymentStudentTable tbody tr');
     if (!input) return;
     input.addEventListener('input', function () {
       const query = input.value.trim().toLocaleLowerCase();
       rows.forEach(function (row) {
         row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
       });
     });
   })();
  </script>
</section>
<?php endif; ?>

<section class="panel">
 <h2><?= $selected ? 'Fine Ledger — '.h($selected['student_number']) : 'Recent Fine Ledger' ?></h2>
 <p class="hint">Each missed required Morning In / Out or Afternoon In / Out checkpoint creates a separate ₱20 fine after the event is finalized. Historical fines are retained.</p>
 <div class="table-scroll"><table class="table">
 <thead><tr><th>Fine #</th><th>Student</th><th>Event</th><th>Checkpoint</th><th>Fine</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
 <tbody>
 <?php foreach ($fines as $f): ?>
  <tr>
   <td><?= h($f['id']) ?></td>
   <td><?php if ($isStaff): ?>
    <?php $fStudent = null; foreach ($students as $s) if ((int)$s['id'] === (int)$f['student_id']) { $fStudent = $s; break; } ?>
    <?= $fStudent ? h(payment_student_name($fStudent).' ('.$fStudent['student_number'].')') : h($f['student_id']) ?>
    <?php else: ?><?= h($selected['student_number'] ?? '') ?><?php endif; ?></td>
   <td><?= h($f['title']) ?></td>
   <td><?= h($f['attendance_slot'] ? (SLOTS[$f['attendance_slot']] ?? $f['attendance_slot']) : 'Historical event fine') ?></td>
   <td><?= pesos($f['amount']) ?></td>
   <td><?= pesos($f['paid']) ?></td>
   <td class="pay-money"><?= pesos($f['status'] === 'waived' ? 0 : max(0, (float)$f['amount']-(float)$f['paid'])) ?></td>
   <td><?= status_badge($f['status']) ?></td>
  </tr>
 <?php endforeach; ?>
 </tbody></table></div>
 <?php if (!$fines): ?><p class="empty">No fine records found.</p><?php endif; ?>
 <?php if (count($fines) >= 300): ?><p class="hint">Showing the latest 300 fine records; select a student to narrow the view.</p><?php endif; ?>
</section>

<section class="panel">
 <div class="pay-title"><h2>Payment History — Who Paid</h2><span class="hint">Showing the latest <?= count($payments) ?> transaction(s)</span></div>
 <div class="table-scroll"><table class="table">
  <thead><tr><th>Receipt / Reference</th><th>Student Name</th><th>Student Number</th><th>Amount</th><th>Method</th><th>Checkpoints Paid</th><th>Payment Date</th><th>Recorded By</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
   <tr>
    <td><strong><?= h($p['reference_number']) ?></strong></td>
    <td><?= h(payment_student_name($p)) ?></td>
    <td><?= h($p['student_number']) ?></td>
    <td class="pay-money"><?= pesos($p['amount']) ?></td>
    <td><?= h(ucwords(str_replace('_',' ',$p['payment_method']))) ?></td>
    <td><?= h($p['covered_fines'] ?: '—') ?></td>
    <td><?= h($p['paid_at']) ?></td>
    <td><?= h($p['recorded_by'] ?: '—') ?></td>
    <td><?= status_badge($p['status']) ?></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if (!$payments): ?><p class="empty">No recorded payments yet.</p><?php endif; ?>
</section>
<?php page_footer(); ?>
