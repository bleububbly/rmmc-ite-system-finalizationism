<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('rmmc_ite_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (is_file(__DIR__ . '/../config.local.php')) require_once __DIR__ . '/../config.local.php';
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', 'rmmc_ite_system');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
function db(): PDO {
    static $pdo;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            error_log('RMMC database error: ' . $e->getMessage());
            http_response_code(503);
            exit('Database unavailable. Check app/core.php, phpMyAdmin, and XAMPP MySQL.');
        }
    }
    return $pdo;
}
function all(string $sql, array $params = []): array { $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function one(string $sql, array $params = []): ?array { $st = db()->prepare($sql); $st->execute($params); return ($st->fetch() ?: null); }
function run_sql(string $sql, array $params = []): int { $st = db()->prepare($sql); $st->execute($params); return $st->rowCount(); }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pesos($n): string { return '₱' . number_format((float)$n, 2); }
function app_path(string $path = ''): string {
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = $base === '/' || $base === '.' ? '' : rtrim($base, '/');
    return $base . '/' . ltrim($path, '/');
}
function go(string $path): never { header('Location: ' . app_path($path), true, 303); exit; }
function request_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function check_post(): void {
    if (!request_post()) { http_response_code(405); exit('POST required'); }
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Your session expired. Refresh and retry.'); }
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$type, $message]; }
function take_flash(): ?array { $msg = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $msg; }
function me(): ?array {
    static $loaded = false, $user = null;
    if ($loaded) return $user;
    $loaded = true;
    if (empty($_SESSION['uid'])) return null;
    $user = one('SELECT u.id,u.login_id,u.must_change_password,u.is_active,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [(int)$_SESSION['uid']]);
    if (!$user || (int)$user['is_active'] !== 1) { unset($_SESSION['uid']); return null; }
    return $user;
}
function require_login(bool $requireChanged = true): array {
    $u = me();
    if (!$u) go('login.php');
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 3600) {
        unset($_SESSION['uid']); session_regenerate_id(true); flash('Session expired. Please sign in again.', 'warning'); go('login.php');
    }
    $_SESSION['last_seen'] = time();
    if ($requireChanged && (int)$u['must_change_password'] === 1) go('change-password.php');
    return $u;
}
function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('You do not have permission to access this page.'); }
    return $u;
}
function student_for(int $uid): ?array { return one('SELECT * FROM students WHERE user_id=?', [$uid]); }
function mine(): ?array { return student_for((int)require_login()['id']); }
function audit(string $action, string $kind, ?int $id, ?array $details = null): void {
    run_sql('INSERT INTO audit_logs(user_id, action, entity_type, entity_id, new_values, ip_address) VALUES(?,?,?,?,?,?)',
        [me()['id'] ?? null, $action, $kind, $id, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}
function fail(string $message, int $code = 400): never { http_response_code($code); exit(h($message)); }
function friendly_error(Throwable $e, string $message): void { error_log($message . ': ' . $e->getMessage()); flash($message, 'error'); }
function datetime_input(?string $v): string { return $v ? substr(str_replace(' ', 'T', $v), 0, 16) : ''; }
function sql_datetime(string $v): string { return str_replace('T', ' ', trim($v)) . (strlen(trim($v)) === 16 ? ':00' : ''); }
function good_datetime(string $s): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s); return $d !== false && $d->format('Y-m-d H:i:s') === $s; }
function status_badge(string $value): string { $safe = preg_replace('/[^a-z_-]/', '', strtolower($value)); return '<span class="badge badge-' . h($safe) . '">' . h(ucwords(str_replace('_', ' ', $value))) . '</span>'; }
function page_header(string $title, array $user): void {
    $role = $user['role'];
    $nav = [
        ['Dashboard','dashboard.php','layout-dashboard'],
        ['Students','students.php','users'],
        ['Events','events.php','calendar-days'],
        ['Attendance','attendance.php','clipboard-check'],
        ['QR Scanner','scan.php','scan-line'],
        ['Fines & Payments','fines.php','receipt'],
        ['Clearance','clearance.php','badge-check'],
        ['Semesters','semesters.php','calendar'],
        ['User Management','users.php','shield'],
        ['My QR Code','qr.php','qr-code'],
        ['Settings','settings.php','settings'],
    ];
    $visible = match ($role) {
        'Admin' => ['Dashboard','Students','Events','Attendance','QR Scanner','Fines & Payments','Clearance','Semesters','User Management','Settings'],
        'Officer' => ['Dashboard','Students','Events','Attendance','QR Scanner','Fines & Payments','Clearance','My QR Code','Settings'],
        default => ['Dashboard','Events','Attendance','Fines & Payments','Clearance','My QR Code','Settings']
    };
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#991b1b">
<title><?=h($title)?> | RMMC ITE</title><link rel="stylesheet" href="<?=h(app_path('assets/css/app.css'))?>"><link rel="stylesheet" href="<?=h(app_path('assets/css/ite-logo.css'))?>"><!-- RMMC CRIMSON DRAGON THEME -->
<link rel="stylesheet" href="<?=h(app_path('assets/css/dragon-theme.css?v=1'))?>">
</head>
<body><div class="layout"><aside class="sidebar" id="sidebar"><div class="brand"><img class="ite-sidebar-logo" src="<?=h(app_path('assets/images/ite-logo.png'))?>" alt="ITE logo" width="46" height="46"><div><b>RMMC ITE</b><small>Event & Attendance</small></div></div><nav class="side-nav">
<?php foreach ($nav as [$label,$url,$icon]): if (!in_array($label,$visible,true)) continue; ?>
<a href="<?=h(app_path($url))?>" class="<?= $current === $url ? 'active' : '' ?>"><span class="nav-dot">◦</span><?=h($label)?></a>
<?php endforeach; ?></nav><div class="side-foot"><span><?=h($role)?></span><strong><?=h($user['login_id'])?></strong><form method="post" action="<?=h(app_path('logout.php'))?>"><?=csrf_field()?><button class="logout">Sign out</button></form></div></aside>
<div class="content"><header class="topbar"><button type="button" class="hamburger" id="navToggle" aria-label="Toggle menu">☰</button><div class="topbar-title"><strong><?=h($title)?></strong><span>Institute of Technology Education</span></div><div class="topbar-user"><span class="top-avatar"><?=h(strtoupper(substr($user['login_id'], 0, 1)))?></span><div><b><?=h($user['login_id'])?></b><small><?=h($role)?></small></div></div></header>
<main class="main">
<?php if ($flash = take_flash()): ?><div class="alert alert-<?=h($flash[0])?>"><?=h($flash[1])?></div><?php endif; ?>
<?php }
function page_footer(): void { ?><footer class="footer">RMMC ITE Event & Attendance Management System · Designed for secure campus operations</footer></main></div></div><script src="<?=h(app_path('assets/js/app.js'))?>"></script><script src="<?=h(app_path('assets/js/attendance-accordion.js?v=1'))?>" defer></script>
</body></html><?php }
function title_block(string $title, string $subtitle = '', ?string $actionHtml = null): void {
    echo '<div class="heading"><div><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p></div>' . ($actionHtml ?? '') . '</div>';
}
