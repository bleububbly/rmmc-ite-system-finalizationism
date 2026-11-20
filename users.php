<?php
require __DIR__ . '/app/core.php';
$u = require_role('Admin');
$edit = (int) ($_GET['edit'] ?? 0);

if (request_post()) {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle') {
        if ($id === (int) $u['id']) fail('Cannot deactivate your own account.');
        $other = one("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.name='Officer'", [$id]);
        if (!$other) fail('Only Officer accounts may be modified here.');
        $active = (int) (($_POST['active'] ?? '0') === '1');
        run_sql('UPDATE users SET is_active=? WHERE id=?', [$active, $id]);
        audit('officer_account_status', 'users', $id, ['active' => $active]);
        flash('Officer account updated.');
        go('users.php');
    }

    if ($action === 'save') {
        $login = trim((string) ($_POST['login_id'] ?? ''));
        $first = trim((string) ($_POST['first_name'] ?? ''));
        $last = trim((string) ($_POST['last_name'] ?? ''));
        $middle = trim((string) ($_POST['middle_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $organization = trim((string) ($_POST['organization_name'] ?? ''));
        $position = trim((string) ($_POST['position_title'] ?? ''));

        $invalid = !preg_match('/^[A-Za-z0-9._-]{4,100}$/', $login)
            || $first === '' || strlen($first) > 100
            || $last === '' || strlen($last) > 100
            || strlen($middle) > 100
            || strlen($email) > 150
            || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            || $organization === '' || strlen($organization) > 150
            || $position === '' || strlen($position) > 100;
        if ($invalid) {
            flash('Enter a valid login ID, officer name, organization name, position and email.', 'error');
            go('users.php' . ($id ? '?edit=' . $id : '?new=1'));
        }

        try {
            db()->beginTransaction();
            if ($id > 0) {
                $rec = one(
                    "SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.name='Officer' FOR UPDATE",
                    [$id]
                );
                if (!$rec) throw new RuntimeException('Officer not found.');
                run_sql('UPDATE users SET login_id=? WHERE id=?', [$login, $id]);
                $message = 'Officer profile saved.';
            } else {
                $role = one("SELECT id FROM roles WHERE name='Officer'");
                if (!$role) throw new RuntimeException('Officer role is missing.');
                $password = bin2hex(random_bytes(7));
                run_sql(
                    'INSERT INTO users(role_id,login_id,password_hash,must_change_password,is_active) VALUES(?,?,?,1,1)',
                    [$role['id'], $login, password_hash($password, PASSWORD_DEFAULT)]
                );
                $id = (int) db()->lastInsertId();
                $message = 'Officer created: ' . $login . ' — one-time temporary password: ' . $password . '. Share privately.';
            }

            run_sql(
                'INSERT INTO staff_profiles(user_id,first_name,middle_name,last_name,organization_name,position_title,email,qr_token)
                 VALUES(?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                     first_name=VALUES(first_name),
                     middle_name=VALUES(middle_name),
                     last_name=VALUES(last_name),
                     organization_name=VALUES(organization_name),
                     position_title=VALUES(position_title),
                     email=VALUES(email)',
                [$id, $first, $middle ?: null, $last, $organization, $position, $email ?: null, bin2hex(random_bytes(32))]
            );
            audit('officer_profile_saved', 'users', $id, [
                'organization_name' => $organization,
                'position_title' => $position,
            ]);
            db()->commit();
            flash($message);
            go('users.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            friendly_error($e, 'Cannot save Officer. Check that the login ID is unique and migration 003 was applied.');
            go('users.php' . ($id ? '?edit=' . $id : '?new=1'));
        }
    }
    fail('Invalid action.');
}

$officers = all(
    "SELECT u.id,u.login_id,u.is_active,sp.first_name,sp.middle_name,sp.last_name,
            sp.email,sp.organization_name,sp.position_title
     FROM users u JOIN roles r ON r.id=u.role_id
     LEFT JOIN staff_profiles sp ON sp.user_id=u.id
     WHERE r.name='Officer' ORDER BY u.id DESC"
);
$cur = $edit ? one(
    "SELECT u.id,u.login_id,sp.first_name,sp.middle_name,sp.last_name,
            sp.email,sp.organization_name,sp.position_title
     FROM users u JOIN roles r ON r.id=u.role_id
     LEFT JOIN staff_profiles sp ON sp.user_id=u.id
     WHERE u.id=? AND r.name='Officer'",
    [$edit]
) : null;

page_header('User Management', $u);
title_block('Manage Officers', 'Add, update, or deactivate officer accounts.',
    '<a class="btn btn-primary" href="' . h(app_path('users.php?new=1')) . '">+ Add Officer</a>');
?>
<?php if (isset($_GET['new']) || $cur): ?>
<section class="panel">
    <h2><?= $cur ? 'Edit Officer' : 'Create Officer Account' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($cur['id'] ?? 0) ?>">
        <div class="form-grid wide">
            <label>Login ID
                <input name="login_id" value="<?= h($cur['login_id'] ?? '') ?>" required maxlength="100" placeholder="officer.username">
            </label>
            <label>First Name
                <input name="first_name" value="<?= h($cur['first_name'] ?? '') ?>" required maxlength="100">
            </label>
            <label>Middle Name
                <input name="middle_name" value="<?= h($cur['middle_name'] ?? '') ?>" maxlength="100">
            </label>
            <label>Last Name
                <input name="last_name" value="<?= h($cur['last_name'] ?? '') ?>" required maxlength="100">
            </label>
            <label>Organization Name
                <input name="organization_name" value="<?= h($cur['organization_name'] ?? '') ?>" required maxlength="150" placeholder="e.g. ITE Student Council">
            </label>
            <label>Position
                <input name="position_title" value="<?= h($cur['position_title'] ?? '') ?>" required maxlength="100" placeholder="e.g. President, Secretary, Treasurer">
            </label>
            <label>Email (optional)
                <input type="email" name="email" value="<?= h($cur['email'] ?? '') ?>" maxlength="150">
            </label>
        </div>
        <?php if (!$cur): ?>
        <p class="hint">New Officer accounts receive a random temporary password and must change it on first login.</p>
        <?php endif; ?>
        <div class="row">
            <button class="btn btn-primary" type="submit">Save Officer</button>
            <a class="btn btn-ghost" href="<?= h(app_path('users.php')) ?>">Cancel</a>
        </div>
    </form>
</section>
<?php endif; ?>
<section class="panel">
    <h2>Officer Directory</h2>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>Login ID</th><th>Full Name</th><th>Organization</th><th>Position</th><th>Email</th><th>Account</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($officers as $o): ?>
                <tr>
                    <td><?= h($o['login_id']) ?></td>
                    <td><?= h(trim(($o['last_name'] ?? '') . ', ' . ($o['first_name'] ?? '') . ' ' . ($o['middle_name'] ?? ''))) ?></td>
                    <td><?= h($o['organization_name'] ?: 'Not set') ?></td>
                    <td><?= h($o['position_title'] ?: 'Not set') ?></td>
                    <td><?= h($o['email'] ?? '') ?></td>
                    <td><?= status_badge($o['is_active'] ? 'active' : 'inactive') ?></td>
                    <td class="actions">
                        <a class="btn btn-soft btn-sm" href="<?= h(app_path('users.php?edit=' . $o['id'])) ?>">Edit</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                            <input type="hidden" name="active" value="<?= $o['is_active'] ? '0' : '1' ?>">
                            <button class="btn btn-ghost btn-sm" data-confirm="Change officer account access?">
                                <?= $o['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$officers): ?><p class="empty">No Officer accounts found.</p><?php endif; ?>
</section>
<?php page_footer(); ?>
