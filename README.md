# RMMC ITE Event and Attendance Management System

A **new PHP/MySQL implementation** using the supplied `rmmc_ite_system.sql` schema and your 13-screen red-and-white Figma UI as design reference. This is **not** an automatic conversion of the incompatible uploaded legacy PHP files; your original ZIP remains your backup. This project contains functional PHP routes, user role permissions, server-side data operations, QR identity, an additive migration and static tests.

## Install on XAMPP (Windows)

1. **Back up your old folder and your current database first.** Keep the uploaded `rmmc-ite-system(3).zip` unchanged, and export a current backup from phpMyAdmin.
2. Extract this ZIP into `C:\XAMPP 2\htdocs\`. The folder must be named `rmmc-ite-system`. **Do not merge individual files into the older mysqli project.** Use a clean folder, or temporarily rename your existing folder to `rmmc-ite-system-backup`.
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. In phpMyAdmin, select database `rmmc_ite_system`. If it does not exist, create it and import your **original uploaded** `rmmc_ite_system.sql` first (do not execute this base dump twice on a populated database). If it already exists and contains your 13 original tables and records, **do not re-import the dump over existing data**.
5. In the same phpMyAdmin database, open the SQL tab and execute `database/002_ui_attendance_upgrade.sql`. This is additive and creates `event_attendance_slots`, `attendance_scans`, and `staff_profiles`; it does not drop your 13 original tables.
6. The default MySQL config is `127.0.0.1`, database `rmmc_ite_system`, user `root`, blank password. If different, copy `config.local.example.php` to `config.local.php` and change the constants there. **Never publish the local credentials.**
7. Open `http://localhost/rmmc-ite-system/` or `http://rmmc-ite.local/` if your VirtualHost points to this folder. Log in with your **existing** Admin/Officer/Student account credentials from the database, not a hardcoded demo password. First-time users will be required to update their password.

### Optional command-line checks

From PowerShell in the project folder:

```powershell
& 'C:\XAMPP 2\php\php.exe' scripts\doctor.php
& 'C:\XAMPP 2\php\php.exe' tests\smoke.php
```

If you don't know your local Admin password, you can reset it **locally from XAMPP's command line** without publishing a password:

```powershell
& 'C:\XAMPP 2\php\php.exe' scripts\reset_password.php Admin
```

Then enter a private new temporary password, which will have to be changed again at sign-in. Do not place that password in ChatGPT or a shared screenshot.

## Features by role

**Admin** — dashboard statistics, student create/update/deactivate and QR rotation, officer create/update/deactivate, semester management, event create/edit/cancel/delete (when no participant records), event attendance schedules, QR scanning, attendance corrections, fine assessment/payment recording, clearance approvals and revocations.

**Officer** — dashboard, student directory/profiles, event management, QR scanner, attendance corrections, fine/payment recording, clearance approvals, personal officer QR, settings.

**Student** — personal dashboard, student ID/QR code, published event list, event attendance, fines and verified payments, semester clearance, email settings and password change.

**Business rules** — four optional event-specific checkpoint types: Morning In, Morning Out, Afternoon In and Afternoon Out. Duplicate scans per checkpoint are blocked with a database unique key. Scan times must fall inside the configured window for an **ongoing** event. Each student QR holds a private random 64-character token (not raw name/ID). No-scan absences in **completed** required events can be finalized into one event fine (default ₱20); partial attendance is left for staff review. Fine payments are transactionally verified with unique receipt references. Semester clearance is blocked by unpaid fines, unfinished required events or unresolved attendance, and requires explicit Officer/Admin approval.

## Suggested first end-to-end test

1. Sign in as Admin, change your password if required, and activate a semester.
2. Add one test student and one Officer; store temporary passwords privately.
3. Create a test event with valid date, four checkpoint windows, and status `ongoing`. The system registers active students as participants when published/ongoing.
4. Student signs in, changes temporary password, then opens My QR.
5. Officer/Admin opens QR Scanner, selects the event and the currently open checkpoint, and scans the student's QR. The student appears in Attendance immediately. Scan the same checkpoint again: duplicate should be rejected.
6. Complete the required checkpoints or use **Attendance → Manual Correction** for an excused attendance test.
7. After the event's end time, change status to `completed` and use **Events → Finalize**. A student with no scans receives an absence fine; scanning just once does not automatically trigger a fine.
8. Staff records and verifies the payment with a unique receipt number; Student sees the payment.
9. Staff opens **Clearance** and approves only eligible students. Student verifies their personal clearance status.
10. Test logout, unauthorized URLs, inactive users, and the layout on a mobile browser.

## Important limitations and disclosures

- **PHP syntax and non-database smoke checks have been performed in the preparation environment.** The environment cannot connect to your Windows XAMPP database, so no claim is made that real MySQL transactions, login, camera permission, or end-to-end scans have been tested on your machine. Run the test sequence above and share any errors to fix.
- Camera scanning requires a secure browser context (HTTPS or localhost), permission, and native `BarcodeDetector` support. On an ordinary HTTP custom VirtualHost (`http://rmmc-ite.local`) the camera may be blocked. Use a USB QR scanner/manual QR input, or configure HTTPS.
- The interface is a **responsive PHP/CSS implementation inspired by** the red-and-white Figma screens. The Figma SVGs are included in `design/` as reference, not rendered screenshots pretending to be functioning controls.
- Fines are applied **per unexcused event absence**, not for each missed scan. Review your department policy for partial attendance before processing live students.
- Students enrolled after an event is published are registered automatically during the Add Student workflow. This does not backfill past completed events.
- No email/SMS integration, online checkout, Google sign-in, bulk CSV importer, offline camera decoding fallback, or multi-day repeated attendance configuration is implemented. Those need additional development if required.
- Designed as a **local school prototype**, not a certified production deployment. Before public internet exposure, add formal security assessment, HTTPS, backups, access reviews, proper audit retention, and tested production database permissions.

## Source and design

- Figma: https://www.figma.com/design/dJ7beF6xsmkWpoCkplJDTi
- Database: `rmmc_ite_system.sql` supplied separately by you (not duplicated here to avoid leaking real account hashes/QR tokens)
- `database/002_ui_attendance_upgrade.sql`: additive tables for new functionality
- `assets/js/qrcode.min.js`: local QR generation library retained from the uploaded source ZIP; preserve its licensing obligations when distributing
