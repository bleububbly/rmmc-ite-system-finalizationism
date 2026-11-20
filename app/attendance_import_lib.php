<?php
/**
 * RMMC ITE: read third-party QR scanner exports safely.
 * This file parses uploaded CSV and XLSX data. Database writes occur only in
 * attendance-import.php after an authenticated Admin/Officer confirms preview.
 */
declare(strict_types=1);

const RMMC_IMPORT_MAX_BYTES = 3 * 1024 * 1024;
const RMMC_IMPORT_MAX_ROWS = 1000;

function rmmc_import_headers(string $header): string {
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header));
    $header = strtolower($header);
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', $header), '_');
}

function rmmc_import_aliases(): array {
    return [
        'student_number' => ['student_number', 'student_no', 'student_id', 'id_number', 'id_no', 'studentid', 'studentnum', 'lrn'],
        'first_name' => ['first_name', 'firstname', 'given_name', 'givenname'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name'],
        'middle_initial' => ['middle_initial', 'middle_name', 'middleinitial', 'mi'],
        'program' => ['program', 'course', 'degree', 'degree_program'],
        'year_level' => ['year_level', 'year', 'yearlevel', 'grade_level'],
        'qr_data' => ['qr_data', 'qr_content', 'qr_code', 'qr_text', 'scanned_qr', 'raw_value', 'scanned_value', 'scan_result', 'barcode', 'code', 'text', 'data'],
        'scanned_at' => ['scanned_at', 'scan_time', 'scan_datetime', 'date_time', 'datetime', 'timestamp', 'scanned_time', 'scan_date_time'],
        'date' => ['date', 'scan_date'],
        'time' => ['time', 'scan_clock_time'],
        'status' => ['status', 'scan_status', 'attendance_status', 'result'],
    ];
}

function rmmc_import_read_upload(array $upload): array {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a CSV or XLSX file. Upload error: ' . (int)($upload['error'] ?? -1));
    }
    $path = (string)($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($path)) throw new RuntimeException('The upload was not received securely.');
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > RMMC_IMPORT_MAX_BYTES) {
        throw new RuntimeException('The file must be smaller than 3 MB. Split large exports into separate files.');
    }
    $ext = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Supported file types: .csv and .xlsx. Save older .xls files as .xlsx first.');
    }
    if ($ext === 'csv') return rmmc_import_csv($path);
    return rmmc_import_xlsx($path);
}

function rmmc_import_csv(string $path): array {
    $f = fopen($path, 'rb');
    if ($f === false) throw new RuntimeException('Could not read the CSV file.');
    try {
        // Most scanner exports use commas; some Excel locales use semicolons/tabs.
        $first = fgets($f);
        if ($first === false) throw new RuntimeException('CSV file is empty.');
        $delimiters = [',', ';', "\t"];
        $scores = [];
        foreach ($delimiters as $delimiter) {
            $scores[$delimiter] = count(str_getcsv($first, $delimiter, '"', ''));
        }
        arsort($scores);
        $delimiter = (string)array_key_first($scores);
        rewind($f);
        $rows = [];
        while (($row = fgetcsv($f, 0, $delimiter, '"', '')) !== false) {
            if (count($rows) > RMMC_IMPORT_MAX_ROWS) throw new RuntimeException('Maximum 1,000 attendance rows per file. Split your export.');
            // Ignore fully empty rows.
            if (count($row) === 1 && trim((string)($row[0] ?? '')) === '') continue;
            $rows[] = array_map(static fn($v) => (string)($v ?? ''), $row);
        }
        return rmmc_import_map_rows($rows);
    } finally {
        fclose($f);
    }
}

/** A small no-dependency XLSX reader for standard spreadsheet/QR-scanner exports. */
function rmmc_import_xlsx(string $path): array {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('Excel import requires PHP ZIP and SimpleXML extensions. Enable extension=zip and extension=xml in your XAMPP php.ini, or save the file as CSV UTF-8.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Invalid Excel .xlsx file.');
    try {
        $xml = static function (string $name) use ($zip): ?SimpleXMLElement {
            $stat = $zip->statName($name);
            if (!$stat) return null;
            // Bound uncompressed XML to reduce zip bomb risk.
            if (($stat['size'] ?? 0) > 15 * 1024 * 1024) throw new RuntimeException('Excel file contains an oversized XML worksheet.');
            $source = $zip->getFromName($name);
            if ($source === false || str_contains($source, '<!DOCTYPE')) throw new RuntimeException('Unsafe or unreadable Excel file.');
            $value = simplexml_load_string($source, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if (!$value) throw new RuntimeException('Excel workbook contains invalid XML.');
            return $value;
        };
        $strings = [];
        $shared = $xml('xl/sharedStrings.xml');
        if ($shared) {
            foreach ($shared->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $si) {
                $strings[] = rmmc_import_xml_text($si);
            }
        }
        $sheetName = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($sheetName) === false) {
            // Accept workbooks whose first sheet is not named sheet1.xml.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n !== false && preg_match('~^xl/worksheets/sheet[0-9]+\.xml$~', $n)) {
                    $sheetName = $n;
                    break;
                }
            }
        }
        $sheet = $xml($sheetName);
        if (!$sheet) throw new RuntimeException('No readable worksheet found in the Excel file.');
        $main = $sheet->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($main->sheetData->row as $row) {
            $result = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                if (!preg_match('/^([A-Z]{1,3})[0-9]+$/', $ref, $match)) continue;
                $index = 0;
                foreach (str_split($match[1]) as $char) $index = $index * 26 + (ord($char) - 64);
                $index--;
                if ($index >= 80) continue;
                $type = (string)$cell['t'];
                if ($type === 's') {
                    $result[$index] = $strings[(int)$cell->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $result[$index] = rmmc_import_xml_text($cell->is);
                } else {
                    $result[$index] = (string)$cell->v;
                }
            }
            if (!$result) continue;
            if (count($rows) > RMMC_IMPORT_MAX_ROWS) throw new RuntimeException('Maximum 1,000 attendance rows per file. Split your export.');
            $max = max(array_keys($result));
            $rows[] = array_map(static fn($v) => (string)$v, array_replace(array_fill(0, $max + 1, ''), $result));
        }
        return rmmc_import_map_rows($rows);
    } finally {
        $zip->close();
    }
}

function rmmc_import_xml_text(SimpleXMLElement $element): string {
    $main = $element->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $text = (string)($main->t ?? '');
    foreach ($main->r as $run) {
        $text .= (string)($run->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->t ?? '');
    }
    return $text;
}

function rmmc_import_map_rows(array $data): array {
    if (count($data) < 2) throw new RuntimeException('Include a header row and at least one scanned student.');
    $columns = array_map('rmmc_import_headers', array_shift($data));
    if (count($columns) > 80) throw new RuntimeException('Too many columns in the file.');
    $known = [];
    foreach (rmmc_import_aliases() as $canonical => $aliases) {
        foreach ($columns as $index => $column) {
            if (in_array($column, $aliases, true)) { $known[$canonical] = $index; break; }
        }
    }
    if (!isset($known['qr_data']) && !isset($known['student_number'])) {
        throw new RuntimeException('Missing columns. The file needs student_number or a QR data column. Download the sample template.');
    }
    $rows = [];
    foreach ($data as $line => $values) {
        if (count($rows) >= RMMC_IMPORT_MAX_ROWS) throw new RuntimeException('Maximum 1,000 attendance rows per file.');
        $entry = ['row' => $line + 2];
        foreach ($known as $field => $idx) {
            $entry[$field] = trim((string)($values[$idx] ?? ''));
        }
        if (implode('', array_map('trim', $values)) === '') continue;
        $rows[] = $entry;
    }
    return $rows;
}

function rmmc_import_normalize(string $value): string {
    $value = trim(strtolower($value));
    return (string)preg_replace('/[^a-z0-9]+/u', '', $value);
}
function rmmc_import_year(string $value): int {
    if (preg_match('/^\s*([1-9])(?:st|nd|rd|th)?\s*(?:year)?\s*$/i', $value, $m)) return (int)$m[1];
    return 0;
}
function rmmc_import_middle(string $value): string {
    if (rmmc_import_normalize($value) === 'na') return '';
    return substr(rmmc_import_normalize($value), 0, 1);
}

/** Return expected db details or an actionable mismatch message. */
function rmmc_import_student_fields(array $row): array {
    $raw = trim((string)($row['qr_data'] ?? ''));
    if ($raw !== '') {
        $raw = str_replace('\\n', "\n", $raw);
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (preg_match('/\A(?:rmmc:)?[a-f0-9]{64}\z/iD', $raw)) {
            return ['token' => preg_replace('/^rmmc:/i', '', $raw)];
        }
        $lines = explode("\n", trim($raw));
        $fields = [];
        foreach (['Last Name', 'First Name', 'Middle Initial', 'Student Number', 'Course', 'Year Level'] as $idx => $label) {
            if (!isset($lines[$idx]) || !preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/i', trim($lines[$idx]), $m)) {
                return ['error' => 'QR text must contain exactly six labeled fields, or be an older QR token.'];
            }
            $fields[$idx] = trim($m[1]);
        }
        if (count($lines) !== 6) return ['error' => 'QR text contains extra lines.'];
        return [
            'student_number'=>$fields[3], 'first_name'=>$fields[1], 'last_name'=>$fields[0],
            'middle_initial'=>$fields[2], 'program'=>$fields[4], 'year_level'=>$fields[5],
        ];
    }
    $out = [];
    foreach (['student_number','first_name','last_name','middle_initial','program','year_level'] as $name) {
        if (isset($row[$name]) && $row[$name] !== '') $out[$name] = (string)$row[$name];
    }
    if (empty($out['student_number'])) return ['error'=>'Missing student number.'];
    // External scanner exports may have only the number, but do not blindly
    // accept a typed number as a scanned QR: require identity fields.
    if (empty($out['first_name']) || empty($out['last_name'])) {
        return ['error'=>'First Name and Last Name columns are required unless QR data is provided.'];
    }
    return $out;
}

function rmmc_import_timestamp(array $row, array $window, string $now): array {
    $value = trim((string)($row['scanned_at'] ?? ''));
    if ($value === '') {
        $d = trim((string)($row['date'] ?? ''));
        $t = trim((string)($row['time'] ?? ''));
        if ($d !== '' && $t !== '') $value = "$d $t";
    }
    if ($value === '') {
        if ($now < $window['opens_at'] || $now > $window['closes_at']) {
            return ['error'=>'Missing scan timestamp; this checkpoint is no longer open. Export scanner timestamps to import historical scans.'];
        }
        return ['time'=>$now, 'note'=>'No scan timestamp: using current import time (checkpoint still open).'];
    }
    // Excel numeric serial dates, including time fraction.
    if (preg_match('/^\d{5}(?:\.\d+)?$/', $value)) {
        $serial = (float)$value;
        if ($serial < 30000 || $serial > 90000) return ['error'=>'Invalid Excel date serial.'];
        $seconds = (int)round($serial * 86400);
        $dt = (new DateTimeImmutable('1899-12-30 00:00:00'))->modify('+' . $seconds . ' seconds');
        $value = $dt->format('Y-m-d H:i:s');
    }
    $dt = null;
    foreach (['!Y-m-d H:i:s','!Y-m-d H:i','!m/d/Y H:i:s','!m/d/Y H:i','!m/d/Y h:i:s A','!m/d/Y h:i A','!d/m/Y H:i:s','!d/m/Y H:i','!Y/m/d H:i:s','!Y/m/d H:i', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'] as $fmt) {
        $try = DateTimeImmutable::createFromFormat($fmt, $value, new DateTimeZone('Asia/Manila'));
        $err = DateTimeImmutable::getLastErrors();
        if ($try instanceof DateTimeImmutable && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) { $dt=$try; break; }
    }
    if (!$dt) return ['error'=>'Invalid scanner timestamp. Use YYYY-MM-DD HH:MM:SS (Philippines time).'];
    $time = $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    if ($time < $window['opens_at'] || $time > $window['closes_at']) {
        return ['error'=>'Scan time is outside the selected checkpoint window (' . substr($window['opens_at'], 0, 16) . ' to '.substr($window['closes_at'], 0, 16).').'];
    }
    if ($time > date('Y-m-d H:i:s', strtotime($now) + 300)) return ['error'=>'Scanner time is in the future. Check scanner timezone.'];
    return ['time'=>$time, 'note'=>''];
}

function rmmc_import_status_allowed(array $row): bool {
    $status = rmmc_import_normalize((string)($row['status'] ?? ''));
    if ($status === '') return true;
    return in_array($status, ['present','scanned','success','valid','checkedin','checkedout','timein','timeout','on time','ontime','late','recorded'], true);
}
