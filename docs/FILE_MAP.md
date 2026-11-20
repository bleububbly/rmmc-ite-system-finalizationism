# Route map

| File | Purpose | Authorized roles |
|---|---|---|
| `login.php` | Secure login, hash verification | Public |
| `change-password.php` | Current password verification, mandatory first-login change | Signed-in users |
| `dashboard.php` | Program/year dashboard or personal student overview | All signed-in users |
| `students.php` | Search students, Admin add/edit/deactivate/QR rotation | Admin, Officer (read-only) |
| `student.php` | Full student profile and history | Admin, Officer |
| `users.php` | Officer create/edit/deactivate | Admin |
| `semesters.php` | Semester create/activate | Admin |
| `events.php` | Publish events, set scan windows, change status, finalize no-show fines | Admin, Officer; Students read-only |
| `attendance.php` | Attendance logs, manual corrections | Admin, Officer; Students personal history |
| `scan.php` | QR camera and keyboard/USB scanning | Admin, Officer |
| `fines.php` | Payments and fine ledger | Admin, Officer; Students personal read-only |
| `clearance.php` | Semester eligibility and approvals | Admin, Officer; Students personal read-only |
| `qr.php` | Student and Officer QR identity view | Signed-in users per access rights |
| `settings.php` | Personal profile updates and security link | All signed-in users |

Shared code is under `app/`, responsive UI under `assets/`, design reference SVGs under `design/`, database additive migration under `database/`, and CLI diagnostic tools under `scripts/`.

The uploaded project ZIP used a different mysqli schema from the supplied SQL export. The integration uses a **new PDO implementation against the uploaded SQL** rather than copying incompatible functions into old routes. Original files are not modified.
