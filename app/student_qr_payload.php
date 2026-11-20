<?php
/**
 * RMMC ITE — human-readable STUDENT QR format.
 * Exactly six public identity fields. Do not include a login password, reset
 * token, private attendance token, email, phone or other personal data.
 *
 * NOTE: these details can be copied and QR codes can be duplicated. An Officer
 * must visually confirm the student when used for attendance.
 */
declare(strict_types=1);

function student_qr_clean($value): string {
    $value = trim((string) ($value ?? ''));
    return trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value));
}

function student_qr_year_label(int $year): string {
    if ($year < 1) return 'N/A';
    $suffix = ($year % 100 >= 11 && $year % 100 <= 13) ? 'th' : match ($year % 10) {
        1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th'
    };
    return $year . $suffix . ' Year';
}

function student_qr_text(array $student): string {
    $middle = student_qr_clean($student['middle_name'] ?? '');
    if ($middle !== '') {
        $letter = function_exists('mb_substr') ? mb_substr($middle, 0, 1, 'UTF-8') : substr($middle, 0, 1);
        $letter = function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter);
        $middleInitial = $letter . '.';
    } else {
        $middleInitial = 'N/A';
    }
    return implode("\n", [
        'Last Name: ' . student_qr_clean($student['last_name'] ?? ''),
        'First Name: ' . student_qr_clean($student['first_name'] ?? ''),
        'Middle Initial: ' . $middleInitial,
        'Student Number: ' . student_qr_clean($student['student_number'] ?? ''),
        'Course: ' . student_qr_clean($student['program'] ?? ''),
        'Year Level: ' . student_qr_year_label((int) ($student['year_level'] ?? 0)),
    ]);
}

function student_qr_normalize_scanned(string $raw): string {
    return trim(str_replace(["\r\n", "\r"], "\n", $raw));
}

/** Returns student number only if input has the exact six-field schema. */
function student_qr_read_number(string $raw): ?string {
    $lines = explode("\n", student_qr_normalize_scanned($raw));
    $labels = ['Last Name', 'First Name', 'Middle Initial', 'Student Number', 'Course', 'Year Level'];
    if (count($lines) !== count($labels)) return null;
    $fields = [];
    foreach ($labels as $i => $label) {
        $prefix = $label . ': ';
        if (!str_starts_with($lines[$i], $prefix)) return null;
        $fields[] = substr($lines[$i], strlen($prefix));
        if ($fields[$i] === '' || strlen($fields[$i]) > 120) return null;
    }
    $number = $fields[3];
    if (strlen($number) > 30 || preg_match('/[\x00-\x1f\x7f]/', $number)) return null;
    return $number;
}
