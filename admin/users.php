<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcAdminUser;

function cmc_dept_belongs_to_org(PDO $pdo, int $deptId, int $orgId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM departments WHERE id = ? AND organisation_id = ?');
    $st->execute([$deptId, $orgId]);
    return (bool) $st->fetchColumn();
}

function cmc_dept_has_hod(PDO $pdo, int $deptId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM users WHERE department_id = ? AND role = \'hod\' LIMIT 1');
    $st->execute([$deptId]);
    return (bool) $st->fetchColumn();
}

/** @return list<string> human-readable blockers */
function cmc_user_delete_blockers(PDO $pdo, int $userId): array
{
    $blockers = [];
    $st = $pdo->prepare('SELECT COUNT(*) FROM complaints WHERE raised_by_user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) {
        $blockers[] = 'complaints raised by this user';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM complaint_events WHERE actor_user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) {
        $blockers[] = 'complaint timeline entries';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM complaint_fulfillments WHERE sde_user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) {
        $blockers[] = 'fulfillment records';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE actor_user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) {
        $blockers[] = 'inventory movements';
    }
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='internal_bills'")->fetch();
    if ($ex) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM internal_bills WHERE created_by_user_id = ?');
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() > 0) {
            $blockers[] = 'billing records';
        }
    }

    return $blockers;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            cmc_flash_set('error', 'Invalid user.');
        } elseif ($id === (int) $user['id']) {
            cmc_flash_set('error', 'You cannot delete your own account.');
        } else {
            $pdo = cmc_db();
            $who = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $who->execute([$id]);
            $row = $who->fetch();
            if (!$row) {
                cmc_flash_set('error', 'User not found.');
            } elseif (mb_strtolower(trim((string) ($row['email'] ?? ''))) === 'pawan@gndec.ac.in') {
                cmc_flash_set(
                    'error',
                    'Haha, nice try. Legend cannot be deleted.(o_0)'
                );
            } else {
            $blockers = cmc_user_delete_blockers($pdo, $id);
            if ($blockers !== []) {
                cmc_flash_set(
                    'error',
                    'This user cannot be deleted while linked to: ' . implode(', ', $blockers) . '.'
                );
            } else {
                try {
                    $st = $pdo->prepare('DELETE FROM users WHERE id = ? AND role != \'admin\'');
                    $st->execute([$id]);
                    if ($st->rowCount() === 0) {
                        cmc_flash_set('error', 'User could not be deleted (admins are protected).');
                    } else {
                        cmc_flash_set('success', 'User removed.');
                    }
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'FOREIGN KEY') || str_contains($e->getMessage(), 'constraint')) {
                        cmc_flash_set('error', 'This user is still referenced elsewhere and cannot be deleted.');
                    } else {
                        throw $e;
                    }
                }
            }
            }
        }
        cmc_redirect('admin/users.php');
    }

    if ($action === 'create') {
        $role = (string) ($_POST['role'] ?? '');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $orgId = (int) ($_POST['organisation_id'] ?? 0);
        $deptId = (int) ($_POST['department_id'] ?? 0);

        $allowed = ['sde', 'hod', 'member'];
        if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            cmc_flash_set('error', 'Enter a valid name and email.');
        } elseif (strlen($password) < 10) {
            cmc_flash_set('error', 'Password must be at least 10 characters.');
        } elseif (!in_array($role, $allowed, true)) {
            cmc_flash_set('error', 'Pick a valid role.');
        } elseif (in_array($role, ['hod', 'member'], true) && ($orgId < 1 || $deptId < 1)) {
            cmc_flash_set('error', 'Organisation and department are required for HOD and members.');
        } elseif (in_array($role, ['hod', 'member'], true) && !cmc_dept_belongs_to_org(cmc_db(), $deptId, $orgId)) {
            cmc_flash_set('error', 'Department does not belong to the selected organisation.');
        } elseif ($role === 'hod' && cmc_dept_has_hod(cmc_db(), $deptId)) {
            cmc_flash_set('error', 'This department already has an HOD. Change the existing HOD to a member first, then assign a new HOD.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                if ($role === 'sde') {
                    $st = cmc_db()->prepare(
                        'INSERT INTO users (email, password_hash, full_name, role, organisation_id, department_id)
                         VALUES (?, ?, ?, \'sde\', NULL, NULL)'
                    );
                    $st->execute([$email, $hash, $fullName]);
                } else {
                    $st = cmc_db()->prepare(
                        'INSERT INTO users (email, password_hash, full_name, role, organisation_id, department_id)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([$email, $hash, $fullName, $role, $orgId, $deptId]);
                }
                cmc_flash_set('success', 'User created.');
            } catch (Throwable $e) {
                cmc_flash_set('error', 'Could not create user (email may already exist).');
            }
        }
        cmc_redirect('admin/users.php');
    }
}

$orgs = cmc_db()->query('SELECT id, name FROM organisations ORDER BY name')->fetchAll();
$depts = cmc_db()->query(
    'SELECT d.id, d.name, d.organisation_id, o.name AS organisation_name
     FROM departments d JOIN organisations o ON o.id = d.organisation_id
     ORDER BY o.name, d.name'
)->fetchAll();

$usersList = cmc_db()->query(
    'SELECT u.id, u.email, u.full_name, u.role, u.organisation_id, u.department_id,
            o.name AS organisation_name, d.name AS department_name
     FROM users u
     LEFT JOIN organisations o ON o.id = u.organisation_id
     LEFT JOIN departments d ON d.id = u.department_id
     ORDER BY u.role, u.full_name'
)->fetchAll();

cmc_layout_start('Users', $user);
?>
<div class="toolbar">
    <h2 class="section-title">New user</h2>
</div>
<div class="card card-form">
    <form method="post" class="form-stack" id="user-create-form">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label class="field">
            <span class="field-label">Role</span>
            <select class="input" name="role" id="role-select" required>
                <option value="sde">SDE (cell)</option>
                <option value="hod">HOD</option>
                <option value="member">Department member</option>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Full name</span>
            <input class="input" name="full_name" required autocomplete="name">
        </label>
        <label class="field">
            <span class="field-label">Email</span>
            <input class="input" type="email" name="email" required autocomplete="username">
        </label>
        <label class="field">
            <span class="field-label">Password</span>
            <input class="input" type="password" name="password" required autocomplete="new-password" minlength="10">
        </label>
        <div id="org-dept-fields">
            <label class="field">
                <span class="field-label">Organisation</span>
                <select class="input" name="organisation_id" id="org-select">
                    <option value="">Select…</option>
                    <?php foreach ($orgs as $o) : ?>
                        <option value="<?= (int) $o['id'] ?>"><?= e((string) $o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Department</span>
                <select class="input" name="department_id" id="dept-select">
                    <option value="">Select organisation first…</option>
                    <?php foreach ($depts as $d) : ?>
                        <option value="<?= (int) $d['id'] ?>" data-org="<?= (int) $d['organisation_id'] ?>" hidden>
                            <?= e((string) $d['organisation_name']) ?> — <?= e((string) $d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Create user</button>
        </div>
    </form>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">All users</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Organisation</th>
                <th>Department</th>
                <th class="th-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usersList as $u) : ?>
                <tr>
                    <td><?= e((string) $u['full_name']) ?></td>
                    <td><?= e((string) $u['email']) ?></td>
                    <td><span class="pill"><?= e(strtoupper((string) $u['role'])) ?></span></td>
                    <td><?= $u['organisation_name'] !== null ? e((string) $u['organisation_name']) : '—' ?></td>
                    <td><?= $u['department_name'] !== null ? e((string) $u['department_name']) : '—' ?></td>
                    <td class="td-actions">
                        <?php if ((int) $u['id'] !== (int) $user['id']) : ?>
                            <form method="post" class="inline-form" data-confirm="Delete this user?">
                                <?= cmc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit" <?= $u['role'] === 'admin' ? 'disabled title="Cannot delete admin"' : '' ?>>Delete</button>
                            </form>
                        <?php else : ?>
                            <span class="muted">You</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
cmc_layout_end();
