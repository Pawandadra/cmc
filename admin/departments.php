<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcAdminUser;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $orgId = (int) ($_POST['organisation_id'] ?? 0);
        if ($name === '' || $orgId < 1) {
            cmc_flash_set('error', 'Department name and organisation are required.');
        } else {
            try {
                $st = cmc_db()->prepare('INSERT INTO departments (organisation_id, name) VALUES (?, ?)');
                $st->execute([$orgId, $name]);
                cmc_flash_set('success', 'Department created.');
            } catch (Throwable $e) {
                cmc_flash_set('error', 'Could not create department (duplicate name in that organisation?).');
            }
        }
        cmc_redirect('admin/departments.php');
    }
    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id < 1 || $name === '') {
            cmc_flash_set('error', 'Invalid department.');
        } else {
            try {
                $st = cmc_db()->prepare('UPDATE departments SET name = ? WHERE id = ?');
                $st->execute([$name, $id]);
                cmc_flash_set('success', 'Department updated.');
            } catch (Throwable $e) {
                cmc_flash_set('error', 'Could not update (duplicate name in organisation?).');
            }
        }
        cmc_redirect('admin/departments.php');
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            cmc_flash_set('error', 'Invalid department.');
        } else {
            $st = cmc_db()->prepare('SELECT COUNT(*) FROM users WHERE department_id = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn() > 0) {
                cmc_flash_set('error', 'Reassign or remove users before deleting this department.');
            } else {
                cmc_db()->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
                cmc_flash_set('success', 'Department deleted.');
            }
        }
        cmc_redirect('admin/departments.php');
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $st = cmc_db()->prepare(
        'SELECT d.id, d.name, d.organisation_id, o.name AS organisation_name
         FROM departments d JOIN organisations o ON o.id = d.organisation_id WHERE d.id = ?'
    );
    $st->execute([$editId]);
    $editRow = $st->fetch() ?: null;
}

$orgs = cmc_db()->query('SELECT id, name FROM organisations ORDER BY name')->fetchAll();

$depts = cmc_db()->query(
    'SELECT d.id, d.name, d.created_at, o.name AS organisation_name, o.id AS organisation_id
     FROM departments d JOIN organisations o ON o.id = d.organisation_id
     ORDER BY o.name, d.name'
)->fetchAll();

cmc_layout_start('Departments', $user);
?>
<div class="toolbar">
    <h2 class="section-title"><?= $editRow ? 'Edit department' : 'New department' ?></h2>
</div>
<div class="card card-form">
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <?php if ($editRow) : ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <label class="field">
                <span class="field-label">Organisation</span>
                <input class="input" disabled value="<?= e((string) $editRow['organisation_name']) ?>">
            </label>
            <label class="field">
                <span class="field-label">Department name</span>
                <input class="input" name="name" required value="<?= e((string) $editRow['name']) ?>">
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn btn-ghost" href="<?= e(cmc_url('admin/departments.php')) ?>">Cancel</a>
            </div>
        <?php else : ?>
            <input type="hidden" name="action" value="create">
            <label class="field">
                <span class="field-label">Organisation</span>
                <select class="input" name="organisation_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($orgs as $o) : ?>
                        <option value="<?= (int) $o['id'] ?>"><?= e((string) $o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Department name</span>
                <input class="input" name="name" required placeholder="e.g. Civil, Accounts">
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Create</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">All departments</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>Organisation</th>
                <th>Department</th>
                <th>Created</th>
                <th class="th-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$depts) : ?>
                <tr><td colspan="4" class="muted">No departments yet.</td></tr>
            <?php else : ?>
                <?php foreach ($depts as $d) : ?>
                    <tr>
                        <td><?= e((string) $d['organisation_name']) ?></td>
                        <td><?= e((string) $d['name']) ?></td>
                        <td class="muted"><?= e((string) $d['created_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('admin/departments.php?edit=' . (int) $d['id'])) ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Delete this department?">
                                <?= cmc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
cmc_layout_end();
