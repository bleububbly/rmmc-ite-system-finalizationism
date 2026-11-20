<?php
// Optional one-time update to show "My QR Code" in the Admin sidebar.
// Edits ONLY the Admin nav-role entry in app/core.php. Existing dashboard/logo changes remain intact.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$file = dirname(__DIR__) . '/app/core.php';
if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
    fwrite(STDERR, "Cannot access app/core.php. Please check your project folder and file permissions.\n");
    exit(1);
}
$source = file_get_contents($file);
if ($source === false) exit("Could not read core.php\n");
$pattern = "/('Admin'\\s*=>\\s*\\[)([^\\]]*)(\\])/";
$matches = [];
if (!preg_match($pattern, $source, $matches)) {
    fwrite(STDERR, "Admin navigation pattern not found. No files changed.\n");
    exit(1);
}
if (str_contains($matches[2], "'My QR Code'")) {
    echo "Admin My QR menu is already enabled.\n";
    exit(0);
}
$items = rtrim($matches[2]);
$items .= str_ends_with($items, ',') ? " 'My QR Code'" : ", 'My QR Code'";
$replacement = $matches[1] . $items . $matches[3];
$updated = preg_replace_callback($pattern, static fn() => $replacement, $source, 1);
if (!is_string($updated) || $updated === $source) {
    fwrite(STDERR, "Unable to modify Admin navigation. No files changed.\n");
    exit(1);
}
if (file_put_contents($file, $updated, LOCK_EX) === false) {
    fwrite(STDERR, "Cannot write updated core.php.\n");
    exit(1);
}
echo "Admin My QR Code menu added successfully.\n";
