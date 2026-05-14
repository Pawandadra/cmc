<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcAdminUser;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            cmc_flash_set('error', 'Organisation name is required.');
        } else {
            $st = cmc_db()->prepare('INSERT INTO organisations (name) VALUES (?)');
            $st->execute([$name]);
            cmc_flash_set('success', 'Organisation created.');
        }
        cmc_redirect('admin/organisations.php');
    }
    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id < 1 || $name === '') {
            cmc_flash_set('error', 'Invalid organisation.');
        } else {
            $st = cmc_db()->prepare('UPDATE organisations SET name = ? WHERE id = ?');
            $st->execute([$name, $id]);
            cmc_flash_set('success', 'Organisation updated.');
        }
        cmc_redirect('admin/organisations.php');
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            cmc_flash_set('error', 'Invalid organisation.');
        } else {
            $st = cmc_db()->prepare(
                'SELECT COUNT(*) FROM users WHERE organisation_id = ? OR department_id IN (SELECT id FROM departments WHERE organisation_id = ?)'
            );
            $st->execute([$id, $id]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                cmc_flash_set('error', 'Remove or reassign users before deleting this organisation.');
            } else {
                cmc_db()->prepare('DELETE FROM organisations WHERE id = ?')->execute([$id]);
                cmc_flash_set('success', 'Organisation deleted.');
            }
        }
        cmc_redirect('admin/organisations.php');
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $st = cmc_db()->prepare('SELECT id, name FROM organisations WHERE id = ?');
    $st->execute([$editId]);
    $editRow = $st->fetch() ?: null;
}

$orgs = cmc_db()->query('SELECT id, name, created_at FROM organisations ORDER BY name')->fetchAll();

cmc_layout_start('Organisations', $user);
?>
<div class="toolbar">
    <h2 class="section-title"><?= $editRow ? 'Edit organisation' : 'New organisation' ?></h2>
</div>
<div class="card card-form">
    <form method="post" class="form-row">
        <?= cmc_csrf_field() ?>
        <?php if ($editRow) : ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <label class="field grow">
                <span class="field-label">Name</span>
                <input class="input" name="name" required value="<?= e((string) $editRow['name']) ?>">
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn btn-ghost" href="<?= e(cmc_url('admin/organisations.php')) ?>">Cancel</a>
            </div>
        <?php else : ?>
            <input type="hidden" name="action" value="create">
            <label class="field grow">
                <span class="field-label">Name</span>
                <input class="input" name="name" required placeholder="e.g. Public Works Department">
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Create</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">All organisations</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Created</th>
                <th class="th-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$orgs) : ?>
                <tr><td colspan="3" class="muted">No organisations yet.</td></tr>
            <?php else : ?>
                <?php foreach ($orgs as $o) : ?>
                    <tr>
                        <td><?= e((string) $o['name']) ?></td>
                        <td class="muted"><?= e((string) $o['created_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('admin/organisations.php?edit=' . (int) $o['id'])) ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Delete this organisation?">
                                <?= cmc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
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
