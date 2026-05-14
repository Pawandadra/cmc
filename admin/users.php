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

/** @return string Relative path with query for list (preserves filters on redirect). */
function cmc_admin_users_list_path(): string
{
    $keep = [];
    foreach (['q', 'role', 'org_id', 'dept_id'] as $k) {
        if (!isset($_GET[$k]) || $_GET[$k] === '' || $_GET[$k] === null) {
            continue;
        }
        $keep[$k] = $_GET[$k];
    }

    return 'admin/users.php' . ($keep !== [] ? '?' . http_build_query($keep) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    $usersRedir = cmc_admin_users_list_path();

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            cmc_flash_set('error', 'Invalid user.');
        } elseif ($id === (int) $user['id']) {
            cmc_flash_set('error', 'You cannot delete your own account.');
        } else {
            $pdo = cmc_db();
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                cmc_flash_set('error', 'User not found.');
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
        cmc_redirect($usersRedir);
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
        cmc_redirect($usersRedir);
    }
}

$pdo = cmc_db();
$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$orgFilter = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
$deptFilter = isset($_GET['dept_id']) ? (int) $_GET['dept_id'] : 0;

$roleChoices = ['admin', 'sde', 'hod', 'member'];
if ($roleFilter !== '' && !in_array($roleFilter, $roleChoices, true)) {
    $roleFilter = '';
}
if ($orgFilter > 0) {
    $chk = $pdo->prepare('SELECT 1 FROM organisations WHERE id = ?');
    $chk->execute([$orgFilter]);
    if (!$chk->fetch()) {
        $orgFilter = 0;
    }
}
if ($deptFilter > 0) {
    $chk = $pdo->prepare('SELECT 1 FROM departments WHERE id = ?');
    $chk->execute([$deptFilter]);
    if (!$chk->fetch()) {
        $deptFilter = 0;
    }
}
if ($deptFilter > 0 && $orgFilter > 0 && !cmc_dept_belongs_to_org($pdo, $deptFilter, $orgFilter)) {
    $deptFilter = 0;
}

$orgs = $pdo->query('SELECT id, name FROM organisations ORDER BY name COLLATE NOCASE')->fetchAll();
$depts = $pdo->query(
    'SELECT d.id, d.name, d.organisation_id, o.name AS organisation_name
     FROM departments d JOIN organisations o ON o.id = d.organisation_id
     ORDER BY o.name COLLATE NOCASE, d.name COLLATE NOCASE'
)->fetchAll();

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $needle = mb_strtolower($q, 'UTF-8');
    $where[] = '(
        INSTR(LOWER(u.full_name), ?) > 0
        OR INSTR(LOWER(u.email), ?) > 0
        OR INSTR(LOWER(CAST(u.id AS TEXT)), ?) > 0
        OR INSTR(LOWER(COALESCE(o.name, \'\')), ?) > 0
        OR INSTR(LOWER(COALESCE(d.name, \'\')), ?) > 0
    )';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}
if ($roleFilter !== '') {
    $where[] = 'u.role = ?';
    $params[] = $roleFilter;
}
if ($orgFilter > 0) {
    $where[] = 'u.organisation_id = ?';
    $params[] = $orgFilter;
}
if ($deptFilter > 0) {
    $where[] = 'u.department_id = ?';
    $params[] = $deptFilter;
}

$sql = 'SELECT u.id, u.email, u.full_name, u.role, u.organisation_id, u.department_id,
            o.name AS organisation_name, d.name AS department_name
     FROM users u
     LEFT JOIN organisations o ON o.id = u.organisation_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY u.role, u.full_name COLLATE NOCASE';
$st = $pdo->prepare($sql);
$st->execute($params);
$usersList = $st->fetchAll();

$listUrl = cmc_url('admin/users.php');
$listQueryParams = [];
if ($q !== '') {
    $listQueryParams['q'] = $q;
}
if ($roleFilter !== '') {
    $listQueryParams['role'] = $roleFilter;
}
if ($orgFilter > 0) {
    $listQueryParams['org_id'] = $orgFilter;
}
if ($deptFilter > 0) {
    $listQueryParams['dept_id'] = $deptFilter;
}
$listUrlWithQuery = $listUrl . ($listQueryParams !== [] ? '?' . http_build_query($listQueryParams) : '');
$hasFilters = $q !== '' || $roleFilter !== '' || $orgFilter > 0 || $deptFilter > 0;

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
<div class="card card-form" style="margin-bottom: 1rem;">
    <form method="get" action="<?= e($listUrl) ?>" class="form-stack">
        <div class="form-row" style="flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <label class="field grow" style="min-width: 200px;">
                <span class="field-label">Search</span>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email, ID, org, department" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">Role</span>
                <select class="input" name="role">
                    <option value="">All roles</option>
                    <?php foreach ($roleChoices as $rc) : ?>
                        <option value="<?= e($rc) ?>"<?= $roleFilter === $rc ? ' selected' : '' ?>><?= e(strtoupper($rc)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Organisation</span>
                <select class="input" name="org_id" id="admin-users-filter-org">
                    <option value="">All</option>
                    <?php foreach ($orgs as $o) : ?>
                        <option value="<?= (int) $o['id'] ?>"<?= $orgFilter === (int) $o['id'] ? ' selected' : '' ?>><?= e((string) $o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Department</span>
                <select class="input" name="dept_id" id="admin-users-filter-dept">
                    <option value="">All</option>
                    <?php foreach ($depts as $d) : ?>
                        <option value="<?= (int) $d['id'] ?>"
                            data-org="<?= (int) $d['organisation_id'] ?>"
                            <?= $deptFilter === (int) $d['id'] ? ' selected' : '' ?>
                            <?= $orgFilter > 0 && (int) $d['organisation_id'] !== $orgFilter ? ' hidden' : '' ?>>
                            <?= e((string) $d['organisation_name']) ?> — <?= e((string) $d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form-actions" style="margin-bottom: 0.15rem;">
                <button class="btn btn-primary" type="submit">Apply</button>
                <?php if ($hasFilters) : ?>
                    <a class="btn btn-ghost" href="<?= e($listUrl) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
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
            <?php if ($usersList === []) : ?>
                <tr><td colspan="6" class="muted">No users match your filters.</td></tr>
            <?php else : ?>
                <?php foreach ($usersList as $u) : ?>
                    <tr>
                        <td><?= e((string) $u['full_name']) ?></td>
                        <td><?= e((string) $u['email']) ?></td>
                        <td><span class="pill"><?= e(strtoupper((string) $u['role'])) ?></span></td>
                        <td><?= $u['organisation_name'] !== null ? e((string) $u['organisation_name']) : '—' ?></td>
                        <td><?= $u['department_name'] !== null ? e((string) $u['department_name']) : '—' ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('admin/edit_user.php?id=' . (int) $u['id'])) ?>">Edit</a>
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('admin/reset_user_password.php?id=' . (int) $u['id'])) ?>">Reset password</a>
                            <?php if ((int) $u['id'] !== (int) $user['id']) : ?>
                                <form method="post" action="<?= e($listUrlWithQuery) ?>" class="inline-form" data-confirm="Delete this user?">
                                    <?= cmc_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit" <?= $u['role'] === 'admin' ? 'disabled title="Cannot delete admin"' : '' ?>>Delete</button>
                                </form>
                            <?php else : ?>
                                <span class="muted small">You</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<script>
(function () {
    var org = document.getElementById('admin-users-filter-org');
    var dept = document.getElementById('admin-users-filter-dept');
    if (!org || !dept) return;
    function sync() {
        var oid = org.value ? String(org.value) : '';
        var opts = dept.querySelectorAll('option[data-org]');
        opts.forEach(function (o) {
            o.hidden = oid !== '' && o.getAttribute('data-org') !== oid;
        });
        if (dept.selectedOptions.length && dept.selectedOptions[0].hidden) {
            dept.value = '';
        }
    }
    org.addEventListener('change', sync);
    sync();
})();
</script>
<?php
cmc_layout_end();
