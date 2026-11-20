<?php
/**
 * External QR scanner CSV/XLSX attendance import.
 * Admin / Officer only. Preview does not modify records.
 * Confirmed import writes attendance_scans, attendance, event_participants,
 * attendance_logs and audit_logs in one transaction.
 */
declare(strict_types=1);
require __DIR__ . '/app/services.php';
require __DIR__ . '/app/attendance_import_lib.php';
$staff = require_role('Admin', 'Officer');

/** Compare all supplied public identity fields with their database counterparts. */
function rmmc_import_match_details(array $details, array $student): ?string {
    foreach (['student_number','first_name','last_name','program'] as $field) {
        if (!isset($details[$field])) continue;
        if (rmmc_import_normalize((string)$details[$field]) !== rmmc_import_normalize((string)$student[$field])) {
            return 'QR/file ' . str_replace('_', ' ', $field) . ' does not match student records.';
        }
    }
    if (isset($details['middle_initial']) && rmmc_import_middle((string)$details['middle_initial']) !==
        rmmc_import_middle((string)($student['middle_name'] ?? ''))) {
        return 'Middle initial does not match student records.';
    }
    if (isset($details['year_level']) && rmmc_import_year((string)$details['year_level']) !== (int)$student['year_level']) {
        return 'Year level does not match student records.';
    }
    return null;
}

/** Validate and optionally save all rows, respecting unique checkpoint scans. */
function rmmc_import_process(array $rows, int $eventId, string $slot, array $staff, bool $commit, string $fingerprint): array {
    if (!$eventId || !array_key_exists($slot, SLOTS)) throw new RuntimeException('Select an event and a valid attendance checkpoint.');
    if ($commit) db()->beginTransaction();
    try {
        $event = one('SELECT id,title,status FROM events WHERE id=?' . ($commit ? ' FOR UPDATE' : ''), [$eventId]);
        if (!$event || $event['status'] !== 'ongoing') {
            throw new RuntimeException('Select an ongoing event. Import saved scanner files before completing/finalizing the event.');
        }
        $assessed = one('SELECT f.id FROM fines f JOIN event_participants ep ON ep.id=f.event_participant_id WHERE ep.event_id=? LIMIT 1', [$eventId]);
        if ($assessed) throw new RuntimeException('This event already has assessed fines. Do not import scans over financial history; review attendance and fines with the Admin first.');
        $window = one('SELECT opens_at,closes_at FROM event_attendance_slots WHERE event_id=? AND slot=? AND required=1', [$eventId, $slot]);
        if (!$window) throw new RuntimeException('The selected checkpoint is not enabled for that event. Configure it in Events → Edit.');
        $required = (int)(one('SELECT COUNT(*) n FROM event_attendance_slots WHERE event_id=? AND required=1', [$eventId])['n'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $seen = []; $result = []; $counts = ['ready'=>0,'imported'=>0,'duplicate'=>0,'invalid'=>0];
        foreach ($rows as $row) {
            $number = (string)($row['student_number'] ?? '');
            $name = '—'; $progress = null; $scanTime = null; $message = '';
            $decision = 'invalid'; $newStatus = '';
            $detail = rmmc_import_student_fields($row);
            if (isset($detail['error'])) {
                $message = $detail['error'];
            } elseif (!rmmc_import_status_allowed($row)) {
                $message = 'Scanner status is not a successful scan; ignored.';
            } else {
                if (isset($detail['token'])) {
                    $student = one('SELECT s.*,u.is_active FROM students s JOIN users u ON u.id=s.user_id WHERE s.qr_token=?', [$detail['token']]);
                } else {
                    $number = (string)$detail['student_number'];
                    $student = one('SELECT s.*,u.is_active FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_number=?', [$number]);
                }
                if (!$student) {
                    $message = 'Student not found. Check Student Number / QR value.';
                } else {
                    $number = (string)$student['student_number'];
                    $mi = rmmc_import_middle((string)($student['middle_name'] ?? ''));
                    $name = $student['last_name'] . ', ' . $student['first_name'] . ($mi !== '' ? ' ' . strtoupper($mi) . '.' : '');
                    $mismatch = rmmc_import_match_details($detail, $student);
                    if ($mismatch !== null) {
                        $message = $mismatch;
                    } elseif ((int)$student['is_active'] !== 1) {
                        $message = 'Student account is inactive.';
                    } elseif (isset($seen[(int)$student['id']])) {
                        $decision = 'duplicate'; $message = 'Repeated in uploaded file; the first row is used.';
                    } else {
                        $seen[(int)$student['id']] = true;
                        $participant = one('SELECT id,attendance_status FROM event_participants WHERE event_id=? AND student_id=?' . ($commit ? ' FOR UPDATE' : ''), [$eventId, $student['id']]);
                        if (!$participant) {
                            $message = 'Student is not enrolled as a participant in this event.';
                        } elseif (in_array($participant['attendance_status'], ['absent', 'excused','partial'], true)) {
                            $message = 'Attendance already finalized, excused or under review. Do not overwrite; ask staff to correct it.';
                        } else {
                            $existing = one('SELECT id FROM attendance_scans WHERE event_participant_id=? AND slot=?', [$participant['id'], $slot]);
                            if ($existing) {
                                $decision = 'duplicate'; $message = 'This checkpoint is already recorded; no change.';
                            } else {
                                $dateCheck = rmmc_import_timestamp($row, $window, $now);
                                if (isset($dateCheck['error'])) {
                                    $message = $dateCheck['error'];
                                } else {
                                    $decision = $commit ? 'imported' : 'ready';
                                    $scanTime = $dateCheck['time'];
                                    $alreadyCompleted = (int)(one('SELECT COUNT(*) n FROM attendance_scans sc JOIN event_attendance_slots es ON es.event_id=? AND es.slot=sc.slot AND es.required=1 WHERE sc.event_participant_id=?', [$eventId, $participant['id']])['n'] ?? 0);
                                    $progress = ($alreadyCompleted + 1) . '/' . $required;
                                    $newStatus = in_array($participant['attendance_status'], ['present','late'], true) ? $participant['attendance_status'] : ($required && ($alreadyCompleted + 1) >= $required ? 'present' : 'pending');
                                    $message = (string)($dateCheck['note'] ?? '');
                                    if ($commit) {
                                        run_sql('INSERT INTO attendance_scans(event_participant_id,slot,scanned_at,scanned_by) VALUES(?,?,?,?)',
                                            [$participant['id'], $slot, $scanTime, $staff['id']]);
                                        $complete = (int)(one('SELECT COUNT(*) n FROM attendance_scans sc JOIN event_attendance_slots es ON es.event_id=? AND es.slot=sc.slot AND es.required=1 WHERE sc.event_participant_id=?', [$eventId, $participant['id']])['n'] ?? 0);
                                        $progress = $complete . '/' . $required;
                                        $newStatus = in_array($participant['attendance_status'], ['present','late'], true) ? $participant['attendance_status'] : ($required && $complete >= $required ? 'present' : 'pending');
                                        $times = one("SELECT MIN(CASE WHEN slot IN ('morning_in','afternoon_in') THEN scanned_at END) time_in, MAX(CASE WHEN slot IN ('morning_out','afternoon_out') THEN scanned_at END) time_out FROM attendance_scans WHERE event_participant_id=?", [$participant['id']]);
                                        $in = str_ends_with($slot, '_in');
                                        run_sql('INSERT INTO attendance(event_participant_id,time_in,time_out,status,scanned_in_by,scanned_out_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE time_in=VALUES(time_in),time_out=VALUES(time_out),status=VALUES(status),scanned_in_by=COALESCE(VALUES(scanned_in_by),scanned_in_by),scanned_out_by=COALESCE(VALUES(scanned_out_by),scanned_out_by)',
                                            [$participant['id'], $times['time_in'], $times['time_out'], $newStatus, $in ? $staff['id'] : null, $in ? null : $staff['id']]);
                                        run_sql('UPDATE event_participants SET attendance_status=? WHERE id=?', [$newStatus, $participant['id']]);
                                        $aid = one('SELECT id FROM attendance WHERE event_participant_id=?', [$participant['id']]);
                                        run_sql('INSERT INTO attendance_logs(attendance_id,action,performed_by,new_value,remarks) VALUES(?,?,?,?,?)',
                                            [$aid['id'], $in ? 'time_in' : 'time_out', $staff['id'], $scanTime, 'External QR scanner CSV/XLSX import · ' . SLOTS[$slot] . ' · batch ' . substr($fingerprint,0,16)]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $counts[$decision]++;
            $result[] = ['row'=>(int)$row['row'], 'student_number'=>$number, 'name'=>$name,
                'course'=>(string)($student['program'] ?? $row['program'] ?? '—'),
                'year'=>(string)($student['year_level'] ?? $row['year_level'] ?? '—'),
                'scanned_at'=>$scanTime ?? '—', 'result'=>$decision, 'note'=>$message, 'progress'=>$progress, 'attendance_status'=>$newStatus];
            unset($student);
        }
        if ($commit) {
            audit('import_external_qr_attendance', 'events', $eventId, [
                'checkpoint'=>$slot, 'source_hash'=>$fingerprint,
                'imported'=>$counts['imported'], 'duplicates'=>$counts['duplicate'], 'invalid'=>$counts['invalid'],
                'total_rows'=>count($rows)]);
            db()->commit();
        }
        return ['ok'=>true, 'mode'=>$commit ? 'import' : 'preview', 'event'=>$event['title'], 'slot'=>SLOTS[$slot],
            'counts'=>$counts, 'total'=>count($rows), 'rows'=>$result];
    } catch (Throwable $e) {
        if ($commit && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

if (request_post()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        check_post();
        $mode = (string)($_POST['action'] ?? '');
        if (!in_array($mode, ['preview','import'], true)) throw new RuntimeException('Invalid import action.');
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $slot = (string)($_POST['slot'] ?? '');
        if (!is_array($_FILES['attendance_file'] ?? null)) throw new RuntimeException('Select a scanner export CSV/XLSX file.');
        $file = $_FILES['attendance_file'];
        $rows = rmmc_import_read_upload($file);
        $fingerprint = hash_file('sha256', $file['tmp_name']);
        if ($mode === 'import') {
             $expectedPreviewToken = hash_hmac('sha256', $fingerprint . '|' . $eventId . '|' . $slot, csrf());
            if ((string)($_POST['confirm'] ?? '') !== 'yes' || !hash_equals($expectedPreviewToken, (string)($_POST['preview_token'] ?? ''))) {
                throw new RuntimeException('Preview this exact file first, then confirm the import.');
            }
        }
        $data = rmmc_import_process($rows, (int)$eventId, $slot, $staff, $mode === 'import', $fingerprint);
        if ($mode === 'preview') $data['preview_token'] = hash_hmac('sha256', $fingerprint . '|' . $eventId . '|' . $slot, csrf());
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code($e instanceof PDOException ? 500 : 422);
        if ($e instanceof PDOException) error_log('RMMC attendance import: ' . $e->getMessage());
        echo json_encode(['ok'=>false, 'message'=>$e instanceof PDOException ? 'Database import failed; no records were saved. See PHP error log.' : $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

$events = all("SELECT e.id,e.title,e.starts_at,e.status FROM events e WHERE e.status='ongoing' ORDER BY e.starts_at DESC LIMIT 100");
$selected = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$slotData = all("SELECT es.event_id,es.slot,es.opens_at,es.closes_at FROM event_attendance_slots es JOIN events e ON e.id=es.event_id WHERE e.status='ongoing' AND es.required=1 ORDER BY es.opens_at");
$available = [];
foreach ($slotData as $sl) $available[(int)$sl['event_id']][] = $sl;
page_header('Import Scanner Attendance', $staff);
title_block('Import QR Scanner Export', 'Upload external QR scanner records and match them with student attendance.', '<a href="' . h(app_path('scan.php')) . '" class="btn btn-soft">Back to QR Scanner</a>');
?>
<link rel="stylesheet" href="<?=h(app_path('assets/css/attendance-import.css'))?>">
<section class="panel import-panel">
  <h2>1. Select event and checkpoint</h2>
  <p class="hint">The external scanner's file can include the six student QR details in one column or separate columns. Scan time is checked against the selected event checkpoint.</p>
  <form id="importForm" enctype="multipart/form-data" class="form-stack">
    <input type="hidden" name="csrf" value="<?=h(csrf())?>">
    <div class="form-grid wide">
      <label>Ongoing Event
        <select name="event_id" id="importEvent" required>
          <?php foreach ($events as $ev): ?>
          <option value="<?=h($ev['id'])?>" <?=(int)$ev['id'] === $selected ? 'selected' : ''?>><?=h($ev['title'].' · '.substr($ev['starts_at'],0,10))?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Attendance Checkpoint
        <select name="slot" id="importSlot" required></select>
      </label>
    </div>
    <label>Scanner export (.csv or .xlsx, max 3 MB / 1,000 rows)
      <input type="file" name="attendance_file" id="importFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    </label>
    <div class="row import-actions">
      <button type="button" class="btn btn-primary" id="previewImport">Preview &amp; Match Students</button>
      <button type="button" class="btn btn-soft" id="confirmImport" disabled>Confirm Attendance Import</button>
      <a class="btn btn-ghost" href="<?=h(app_path('assets/templates/attendance-import-sample.csv'))?>" download>Download CSV Template</a>
      <a class="btn btn-ghost" href="<?=h(app_path('assets/templates/attendance-import-sample.xlsx'))?>" download>Download Excel Template</a>
    </div>
    <label class="import-check"><input type="checkbox" id="importVerified"> I verified that this file came from our QR attendance scanner and the students' identities were checked.</label>
  </form>
  <?php if (!$events): ?><div class="alert alert-warning">No ongoing events. Set the event to Ongoing before importing. Import scanned records before completing/finalizing the event.</div><?php endif; ?>
  <div id="importMessage" class="import-msg" role="status" aria-live="polite">Choose an event, a checkpoint, and a file to preview matching records.</div>
</section>
<section class="panel" id="importResults" hidden>
  <h2>2. Review the matching students</h2>
  <div id="importSummary" class="import-summary"></div>
  <p class="hint">Only valid, new scans are added. Existing checkpoint records and repeated rows are skipped. Invalid rows will not update attendance.</p>
  <div class="table-scroll"><table class="table"><thead><tr><th>CSV/Excel Row</th><th>Student ID</th><th>Name</th><th>Course / Year</th><th>Scan Time</th><th>Import Result</th><th>Attendance Status</th><th>Notes</th></tr></thead><tbody id="importRows"></tbody></table></div>
  <p class="hint" id="importTruncated"></p>
  <a id="importAttendanceLink" class="btn btn-soft" href="<?=h(app_path('attendance.php'))?>">View Updated Attendance</a>
</section>
<script>
(() => {
  const slotsByEvent = <?=json_encode($available, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
  const endpoint = <?=json_encode(app_path('attendance-import.php'))?>;
  const eventEl = document.getElementById('importEvent'), slotEl = document.getElementById('importSlot'), fileEl = document.getElementById('importFile');
  const previewBtn = document.getElementById('previewImport'), confirmBtn = document.getElementById('confirmImport');
  const verifyEl = document.getElementById('importVerified'), messageEl = document.getElementById('importMessage');
  const resultsEl = document.getElementById('importResults'), rowsEl = document.getElementById('importRows');
  const summaryEl = document.getElementById('importSummary'), attendanceLink = document.getElementById('importAttendanceLink');
  let previewToken='', previewCount=0, busy=false;
  const labels = {morning_in:'Morning In',morning_out:'Morning Out',afternoon_in:'Afternoon In',afternoon_out:'Afternoon Out'};
  const escapeHtml = value => String(value??'').replace(/[&<>"']/g, v=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[v]));
  function reset(){previewToken='';previewCount=0;confirmBtn.disabled=true;resultsEl.hidden=true;messageEl.textContent='Ready to preview your scanner export.';}
  function fillSlots(){slotEl.innerHTML='';for(const s of slotsByEvent[eventEl.value]||[]){const o=document.createElement('option');o.value=s.slot;o.textContent=labels[s.slot]+' · '+String(s.opens_at).slice(11,16)+'–'+String(s.closes_at).slice(11,16);slotEl.appendChild(o);}reset();}
  eventEl.addEventListener('change',fillSlots);slotEl.addEventListener('change',reset);fileEl.addEventListener('change',reset);verifyEl.addEventListener('change',()=>confirmBtn.disabled=!previewToken||!verifyEl.checked||previewCount<1||busy);
  function render(data){const c=data.counts;summaryEl.innerHTML=`<strong>${data.mode==='preview'?'Ready':'Imported'}: ${data.mode==='preview'?c.ready:c.imported}</strong> · Duplicates: ${c.duplicate} · Invalid: ${c.invalid} · Rows: ${data.total} · ${escapeHtml(data.slot)}`;
    rowsEl.innerHTML='';for(const row of data.rows.slice(0,200)){const tr=document.createElement('tr');for(const v of [row.row,row.student_number,row.name,row.course+' / '+row.year,row.scanned_at,row.result,(row.attendance_status?row.attendance_status+' · '+row.progress:'—'),row.note]){const td=document.createElement('td');td.textContent=String(v??'');tr.appendChild(td);}tr.className='import-'+row.result;rowsEl.appendChild(tr);}document.getElementById('importTruncated').textContent=data.rows.length>200?'Showing first 200 rows. The summary includes all '+data.rows.length+' rows.':'';resultsEl.hidden=false;attendanceLink.href=<?=json_encode(app_path('attendance.php?event_id='))?>+encodeURIComponent(eventEl.value);
  }
  async function send(action){if(busy)return;if(!eventEl.value||!slotEl.value||!fileEl.files.length){messageEl.textContent='Choose an event, checkpoint and file first.';return;}
    busy=true;previewBtn.disabled=true;confirmBtn.disabled=true;messageEl.textContent=action==='preview'?'Matching students with the database…':'Recording attendance…';
    const data=new FormData(document.getElementById('importForm'));data.set('action',action);
    if(action==='import'){data.set('confirm','yes');data.set('preview_token',previewToken);}
    try{const resp=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin'});const reply=await resp.json();if(!resp.ok||!reply.ok)throw new Error(reply.message||'Import failed.');
      render(reply);if(action==='preview'){previewToken=reply.preview_token;previewCount=reply.counts.ready;messageEl.textContent=`Preview completed. ${previewCount} new scan(s) ready; ${reply.counts.duplicate} duplicate; ${reply.counts.invalid} invalid. Review the table before confirming.`;}
      else{previewToken='';previewCount=0;verifyEl.checked=false;messageEl.textContent=`Import finished: ${reply.counts.imported} new records saved. Open Attendance to see updated checkpoints and statuses.`;}
    }catch(err){messageEl.textContent=err.message||'Could not read the import file.';previewToken='';previewCount=0;}
    finally{busy=false;previewBtn.disabled=false;confirmBtn.disabled=!previewToken||!verifyEl.checked||previewCount<1;}
  }
  previewBtn.addEventListener('click',()=>send('preview'));
  confirmBtn.addEventListener('click',()=>{if(!verifyEl.checked){messageEl.textContent='Confirm the scanner source and student identity check.';return;}if(window.confirm('Record the valid new scans for '+(eventEl.selectedOptions[0]?.textContent||'this event')+' / '+(slotEl.selectedOptions[0]?.textContent||'this checkpoint')+'?'))send('import');});
  fillSlots();
})();
</script>
<?php page_footer();
