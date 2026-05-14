<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcResourceUser;
$pdo = cmc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'create') {
        cmc_redirect('resources/index.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['item_code'] ?? ''));
    $unit = trim((string) ($_POST['unit'] ?? ''));
    $kind = (string) ($_POST['resource_kind'] ?? 'material');
    $rateRaw = trim((string) ($_POST['unit_rate'] ?? '0'));
    $description = trim((string) ($_POST['description'] ?? ''));
    $initRaw = trim((string) ($_POST['initial_quantity'] ?? '0'));

    if ($name === '' || strlen($name) > 255) {
        cmc_flash_set('error', 'Name is required (max 255 characters).');
        cmc_redirect('resources/index.php');
    }
    if (!in_array($kind, ['material', 'equipment', 'worker'], true)) {
        cmc_flash_set('error', 'Invalid resource type.');
        cmc_redirect('resources/index.php');
    }
    if ($unit === '' || strlen($unit) > 32 || !cmc_resource_unit_is_allowed($unit, null)) {
        cmc_flash_set('error', 'Please choose a valid unit from the list.');
        cmc_redirect('resources/index.php');
    }
    if (!is_numeric($rateRaw) || (float) $rateRaw < 0) {
        cmc_flash_set('error', 'Rate must be a number ≥ 0.');
        cmc_redirect('resources/index.php');
    }
    $unitRate = (float) $rateRaw;
    if (strlen($description) > 5000) {
        cmc_flash_set('error', 'Description is too long.');
        cmc_redirect('resources/index.php');
    }

    $codeVal = $code === '' ? null : $code;
    if ($codeVal !== null && strlen($codeVal) > 64) {
        cmc_flash_set('error', 'Item code is too long.');
        cmc_redirect('resources/index.php');
    }

    if (!is_numeric($initRaw)) {
        cmc_flash_set('error', 'Initial quantity must be a number.');
        cmc_redirect('resources/index.php');
    }
    $init = (float) $initRaw;
    if ($init < 0 || abs($init) > 1e12) {
        cmc_flash_set('error', 'Initial quantity is not valid.');
        cmc_redirect('resources/index.php');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO inventory_items (name, item_code, description, unit, quantity, resource_kind, unit_rate)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$name, $codeVal, $description === '' ? null : $description, $unit, $init, $kind, $unitRate]);
        $newId = (int) $pdo->lastInsertId();

        if (abs($init) > 1e-12) {
            $pdo->prepare(
                'INSERT INTO inventory_movements (item_id, actor_user_id, quantity_delta, balance_after, note)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$newId, (int) $user['id'], $init, $init, 'Opening balance']);
        }

        $pdo->commit();
        cmc_flash_set('success', 'Resource created.');
        cmc_redirect('resources/item.php?id=' . $newId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        cmc_flash_set('error', 'Could not create resource (duplicate item code?).');
        cmc_redirect('resources/index.php');
    }
}

$items = $pdo->query(
    'SELECT id, name, item_code, unit, quantity, resource_kind, unit_rate, updated_at FROM inventory_items ORDER BY resource_kind, name COLLATE NOCASE'
)->fetchAll();

$unitChoices = cmc_resource_unit_choices();

cmc_layout_start('Resources', $user);
?>
<div class="toolbar">
    <h2 class="section-title">Add resource</h2>
</div>
<div class="card card-form">
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label class="field">
            <span class="field-label">Type</span>
            <select class="input" name="resource_kind" required>
                <option value="material">Material</option>
                <option value="equipment">Equipment</option>
                <option value="worker">Workers</option>
            </select>
        </label>
        <div class="form-row">
            <label class="field grow">
                <span class="field-label">Name</span>
                <input class="input" name="name" required maxlength="255" placeholder="e.g. Cement 50kg or Mason crew">
            </label>
            <label class="field">
                <span class="field-label">Code (optional)</span>
                <input class="input" name="item_code" maxlength="64" placeholder="SKU / role code">
            </label>
        </div>
        <div class="form-row">
            <label class="field">
                <span class="field-label">Unit</span>
                <select class="input" name="unit" required>
                    <?php foreach ($unitChoices as $code => $label) : ?>
                        <option value="<?= e($code) ?>" <?= $code === 'ea' ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Rate (₹)</span>
                <input class="input" name="unit_rate" value="0" inputmode="decimal" min="0" step="any" required>
            </label>
            <label class="field">
                <span class="field-label">Initial quantity</span>
                <input class="input" name="initial_quantity" value="0" inputmode="decimal">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Description (optional)</span>
            <textarea class="input textarea" name="description" rows="2" maxlength="5000"></textarea>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save resource</button>
        </div>
    </form>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">All resources</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Code</th>
                <th>Name</th>
                <th>Available</th>
                <th>Unit</th>
                <th>Rate (₹)</th>
                <th>Updated</th>
                <th class="th-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$items) : ?>
                <tr><td colspan="9" class="muted">No resources yet.</td></tr>
            <?php else : ?>
                <?php foreach ($items as $it) : ?>
                    <tr>
                        <td class="muted"><?= (int) $it['id'] ?></td>
                        <td><span class="pill pill-soft"><?= e(cmc_resource_kind_label((string) ($it['resource_kind'] ?? 'material'))) ?></span></td>
                        <td><?= $it['item_code'] !== null ? e((string) $it['item_code']) : '—' ?></td>
                        <td><?= e((string) $it['name']) ?></td>
                        <td><?= e(cmc_resource_format_qty((float) $it['quantity'])) ?></td>
                        <td><?= e((string) $it['unit']) ?></td>
                        <td><?= e(cmc_resource_format_money((float) ($it['unit_rate'] ?? 0))) ?></td>
                        <td class="muted"><?= e((string) $it['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('resources/item.php?id=' . (int) $it['id'])) ?>">Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
cmc_layout_end();
