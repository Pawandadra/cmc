<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcAdminUser;
$pdo = cmc_db();

function cmc_edit_dept_belongs_to_org(PDO $pdo, int $deptId, int $orgId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM departments WHERE id = ? AND organisation_id = ?');
    $st->execute([$deptId, $orgId]);

    return (bool) $st->fetchColumn();
}

function cmc_edit_dept_has_other_hod(PDO $pdo, int $deptId, int $exceptUserId): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM users WHERE department_id = ? AND role = \'hod\' AND id != ? LIMIT 1'
    );
    $st->execute([$deptId, $exceptUserId]);

    return (bool) $st->fetchColumn();
}

$targetId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($targetId < 1) {
    cmc_flash_set('error', 'Invalid user.');
    cmc_redirect('admin/users.php');
}

$st = $pdo->prepare(
    'SELECT u.id, u.email, u.full_name, u.role, u.organisation_id, u.department_id,
            o.name AS organisation_name, d.name AS department_name
     FROM users u
     LEFT JOIN organisations o ON o.id = u.organisation_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.id = ?'
);
$st->execute([$targetId]);
$target = $st->fetch();
if (!$target) {
    cmc_flash_set('error', 'User not found.');
    cmc_redirect('admin/users.php');
}

$isAdminTarget = ($target['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $postedId = (int) ($_POST['id'] ?? 0);
    if ($postedId !== $targetId) {
        cmc_flash_set('error', 'Invalid request.');
        cmc_redirect('admin/users.php');
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cmc_flash_set('error', 'Enter a valid name and email.');
        cmc_redirect('admin/edit_user.php?id=' . $targetId);
    }

    $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id != ?');
    $dup->execute([$email, $targetId]);
    if ($dup->fetch()) {
        cmc_flash_set('error', 'Another user already uses that email.');
        cmc_redirect('admin/edit_user.php?id=' . $targetId);
    }

    if ($isAdminTarget) {
        $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ? AND role = \'admin\'')
            ->execute([$fullName, $email, $targetId]);
        cmc_flash_set('success', 'Administrator updated.');
        cmc_redirect('admin/users.php');
    }

    $role = (string) ($_POST['role'] ?? '');
    $orgId = (int) ($_POST['organisation_id'] ?? 0);
    $deptId = (int) ($_POST['department_id'] ?? 0);
    $allowed = ['sde', 'hod', 'member'];

    if (!in_array($role, $allowed, true)) {
        cmc_flash_set('error', 'Pick a valid role.');
        cmc_redirect('admin/edit_user.php?id=' . $targetId);
    }
    if (in_array($role, ['hod', 'member'], true) && ($orgId < 1 || $deptId < 1)) {
        cmc_flash_set('error', 'Organisation and department are required for HOD and members.');
        cmc_redirect('admin/edit_user.php?id=' . $targetId);
    }
    if (in_array($role, ['hod', 'member'], true) && !cmc_edit_dept_belongs_to_org($pdo, $deptId, $orgId)) {
        cmc_flash_set('error', 'Department does not belong to the selected organisation.');
        cmc_redirect('admin/edit_user.php?id=' . $targetId);
    }
    if ($role === 'hod' && cmc_edit_dept_has_other_hod($pdo, $deptId, $targetId)) {
        cmc_flash_set('error', 'This department already has an HOD. Reassign or demote the existing HOD first.');
        cmc_redirect('admin/edit_user.php?id=' . $targetId);
    }

    try {
        if ($role === 'sde') {
            $up = $pdo->prepare(
                'UPDATE users SET email = ?, full_name = ?, role = ?, organisation_id = NULL, department_id = NULL
                 WHERE id = ? AND role != \'admin\''
            );
            $up->execute([$email, $fullName, $role, $targetId]);
        } else {
            $up = $pdo->prepare(
                'UPDATE users SET email = ?, full_name = ?, role = ?, organisation_id = ?, department_id = ?
                 WHERE id = ? AND role != \'admin\''
            );
            $up->execute([$email, $fullName, $role, $orgId, $deptId, $targetId]);
        }
        cmc_flash_set('success', 'User updated.');
    } catch (Throwable $e) {
        cmc_flash_set('error', 'Could not update user (constraints or duplicate role in department).');
    }
    cmc_redirect('admin/users.php');
}

$orgs = $pdo->query('SELECT id, name FROM organisations ORDER BY name')->fetchAll();
$depts = $pdo->query(
    'SELECT d.id, d.name, d.organisation_id, o.name AS organisation_name
     FROM departments d JOIN organisations o ON o.id = d.organisation_id
     ORDER BY o.name, d.name'
)->fetchAll();

$orgIdVal = (int) ($target['organisation_id'] ?? 0);
$deptIdVal = (int) ($target['department_id'] ?? 0);

cmc_layout_start('Edit user', $user);
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('admin/users.php')) ?>">← All users</a>
</div>

<div class="card card-form" style="max-width: 520px;">
    <h2 class="card-title"><?= e((string) $target['full_name']) ?></h2>
    <p class="muted"><?= e((string) $target['email']) ?> · <span class="pill"><?= e(strtoupper((string) $target['role'])) ?></span></p>
    <?php if (!$isAdminTarget && !empty($target['organisation_name'])) : ?>
        <p class="muted small"><?= e((string) $target['organisation_name']) ?><?= !empty($target['department_name']) ? ' · ' . e((string) $target['department_name']) : '' ?></p>
    <?php endif; ?>

    <form method="post" class="form-stack" id="user-edit-form">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="id" value="<?= $targetId ?>">
        <label class="field">
            <span class="field-label">Full name</span>
            <input class="input" name="full_name" required autocomplete="name" value="<?= e((string) $target['full_name']) ?>">
        </label>
        <label class="field">
            <span class="field-label">Email</span>
            <input class="input" type="email" name="email" required autocomplete="email" value="<?= e((string) $target['email']) ?>">
        </label>

        <?php if ($isAdminTarget) : ?>
            <p class="muted small">Administrator accounts can only change name and email here. Use <strong>Reset password</strong> from the user list to set a new password.</p>
        <?php else : ?>
            <label class="field">
                <span class="field-label">Role</span>
                <select class="input" name="role" id="edit-role-select" required>
                    <option value="sde" <?= ($target['role'] ?? '') === 'sde' ? 'selected' : '' ?>>SDE (cell)</option>
                    <option value="hod" <?= ($target['role'] ?? '') === 'hod' ? 'selected' : '' ?>>HOD</option>
                    <option value="member" <?= ($target['role'] ?? '') === 'member' ? 'selected' : '' ?>>Department member</option>
                </select>
            </label>
            <div id="edit-org-dept-fields">
                <label class="field">
                    <span class="field-label">Organisation</span>
                    <select class="input" name="organisation_id" id="edit-org-select">
                        <option value="">Select…</option>
                        <?php foreach ($orgs as $o) : ?>
                            <option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === $orgIdVal ? 'selected' : '' ?>><?= e((string) $o['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">Department</span>
                    <select class="input" name="department_id" id="edit-dept-select">
                        <option value="">Select organisation first…</option>
                        <?php foreach ($depts as $d) : ?>
                            <option value="<?= (int) $d['id'] ?>" data-org="<?= (int) $d['organisation_id'] ?>" hidden>
                                <?= e((string) $d['organisation_name']) ?> — <?= e((string) $d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save changes</button>
            <a class="btn btn-ghost" href="<?= e(cmc_url('admin/users.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php if (!$isAdminTarget) : ?>
<script>
(function () {
    var roleSelect = document.getElementById("edit-role-select");
    var orgBlock = document.getElementById("edit-org-dept-fields");
    if (!roleSelect || !orgBlock) return;
    var orgSelect = document.getElementById("edit-org-select");
    var deptSelect = document.getElementById("edit-dept-select");
    var initialOrg = <?= json_encode($orgIdVal, JSON_THROW_ON_ERROR) ?>;
    var initialDept = <?= json_encode($deptIdVal, JSON_THROW_ON_ERROR) ?>;

    function setOrgDeptRequired(required) {
        if (orgSelect) orgSelect.required = required;
        if (deptSelect) deptSelect.required = required;
    }

    function filterDepartments(resetValue) {
        if (!orgSelect || !deptSelect) return;
        var orgId = orgSelect.value;
        var opts = deptSelect.querySelectorAll("option[data-org]");
        var firstVisible = null;
        opts.forEach(function (opt) {
            var match = !orgId || opt.getAttribute("data-org") === orgId;
            opt.hidden = !match;
            opt.disabled = !match;
            if (match && !firstVisible) firstVisible = opt;
        });
        if (resetValue) {
            deptSelect.value = "";
            if (firstVisible && orgId) deptSelect.value = firstVisible.value;
        } else {
            var cur = deptSelect.querySelector('option[value="' + deptSelect.value + '"]');
            if (!cur || cur.hidden || cur.disabled) {
                deptSelect.value = firstVisible && orgId ? firstVisible.value : "";
            }
        }
    }

    function applyRole() {
        var role = roleSelect.value;
        if (role === "sde") {
            orgBlock.style.display = "none";
            setOrgDeptRequired(false);
            if (orgSelect) orgSelect.value = "";
            if (deptSelect) deptSelect.value = "";
        } else {
            orgBlock.style.display = "";
            setOrgDeptRequired(true);
            if (orgSelect && !orgSelect.value && initialOrg) {
                orgSelect.value = String(initialOrg);
            }
            filterDepartments(false);
            if (deptSelect && initialDept) {
                var wanted = deptSelect.querySelector('option[value="' + initialDept + '"]');
                if (wanted && !wanted.hidden && !wanted.disabled) {
                    deptSelect.value = String(initialDept);
                }
            }
        }
    }

    roleSelect.addEventListener("change", applyRole);
    if (orgSelect) orgSelect.addEventListener("change", function () {
        filterDepartments(true);
    });
    applyRole();
})();
</script>
<?php endif; ?>
<?php
cmc_layout_end();
