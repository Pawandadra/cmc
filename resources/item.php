<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcResourceUser;
$pdo = cmc_db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    cmc_redirect('resources/index.php');
}

$st = $pdo->prepare(
    'SELECT id, name, item_code, description, unit, quantity, resource_kind, unit_rate, created_at, updated_at
     FROM inventory_items WHERE id = ?'
);
$st->execute([$id]);
$item = $st->fetch();
if (!$item) {
    cmc_flash_set('error', 'Resource not found.');
    cmc_redirect('resources/index.php');
}

$kind = (string) ($item['resource_kind'] ?? 'material');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_meta') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['item_code'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $newKind = (string) ($_POST['resource_kind'] ?? $kind);
        $rateRaw = trim((string) ($_POST['unit_rate'] ?? '0'));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($name === '' || strlen($name) > 255) {
            cmc_flash_set('error', 'Name is required (max 255 characters).');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        if (!in_array($newKind, ['material', 'equipment', 'worker'], true)) {
            cmc_flash_set('error', 'Invalid resource type.');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        if ($unit === '' || strlen($unit) > 32 || !cmc_resource_unit_is_allowed($unit, (string) $item['unit'])) {
            cmc_flash_set('error', 'Please choose a valid unit from the list.');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        if (!is_numeric($rateRaw) || (float) $rateRaw < 0) {
            cmc_flash_set('error', 'Rate must be a number ≥ 0.');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        $unitRate = (float) $rateRaw;
        if (strlen($description) > 5000) {
            cmc_flash_set('error', 'Description is too long.');
            cmc_redirect('resources/item.php?id=' . $id);
        }

        $codeVal = $code === '' ? null : $code;
        if ($codeVal !== null && strlen($codeVal) > 64) {
            cmc_flash_set('error', 'Item code is too long.');
            cmc_redirect('resources/item.php?id=' . $id);
        }

        try {
            $pdo->prepare(
                'UPDATE inventory_items SET name = ?, item_code = ?, description = ?, unit = ?, resource_kind = ?, unit_rate = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([
                $name,
                $codeVal,
                $description === '' ? null : $description,
                $unit,
                $newKind,
                $unitRate,
                $id,
            ]);
            cmc_flash_set('success', 'Resource updated.');
        } catch (Throwable $e) {
            cmc_flash_set('error', 'Could not update (duplicate item code?).');
        }
        cmc_redirect('resources/item.php?id=' . $id);
    }

    if ($action === 'adjust_quantity') {
        $deltaRaw = trim((string) ($_POST['quantity_delta'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!is_numeric($deltaRaw)) {
            cmc_flash_set('error', 'Quantity change must be a number.');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        $delta = (float) $deltaRaw;
        if (abs($delta) > 1e12) {
            cmc_flash_set('error', 'Quantity change is too large.');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        if (abs($delta) < 1e-12) {
            cmc_flash_set('error', 'Enter a non-zero quantity change.');
            cmc_redirect('resources/item.php?id=' . $id);
        }
        if (strlen($note) > 500) {
            cmc_flash_set('error', 'Note is too long (max 500 characters).');
            cmc_redirect('resources/item.php?id=' . $id);
        }

        $noteFinal = $note === '' ? 'Quantity adjustment' : $note;
        if (strlen($noteFinal) < 3) {
            cmc_flash_set('error', 'If you add a note, use at least 3 characters (or leave it blank).');
            cmc_redirect('resources/item.php?id=' . $id);
        }

        $err = cmc_resource_apply_delta($pdo, $id, (int) $user['id'], $delta, $noteFinal);
        if ($err !== null) {
            cmc_flash_set('error', $err);
            cmc_redirect('resources/item.php?id=' . $id);
        }
        $stBal = $pdo->prepare('SELECT quantity FROM inventory_items WHERE id = ?');
        $stBal->execute([$id]);
        $after = (float) ($stBal->fetchColumn() ?: 0);
        cmc_flash_set('success', 'Quantity updated. New balance: ' . cmc_resource_format_qty($after) . '.');
        cmc_redirect('resources/item.php?id=' . $id);
    }

    cmc_redirect('resources/item.php?id=' . $id);
}

$movements = $pdo->prepare(
    'SELECT m.id, m.quantity_delta, m.balance_after, m.note, m.created_at, u.full_name AS actor_name
     FROM inventory_movements m
     LEFT JOIN users u ON u.id = m.actor_user_id
     WHERE m.item_id = ?
     ORDER BY m.id DESC
     LIMIT 50'
);
$movements->execute([$id]);
$rows = $movements->fetchAll();

$unitChoices = cmc_resource_unit_choices();

cmc_layout_start('Resource #' . $id, $user);
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('resources/index.php')) ?>">← All resources</a>
    <h2 class="section-title"><?= e((string) $item['name']) ?></h2>
</div>

<div class="card card-form">
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="action" value="update_meta">
        <label class="field">
            <span class="field-label">Type</span>
            <select class="input" name="resource_kind" required>
                <option value="material"<?= $kind === 'material' ? ' selected' : '' ?>>Material</option>
                <option value="equipment"<?= $kind === 'equipment' ? ' selected' : '' ?>>Equipment</option>
                <option value="worker"<?= $kind === 'worker' ? ' selected' : '' ?>>Workers</option>
            </select>
        </label>
        <div class="form-row">
            <label class="field grow">
                <span class="field-label">Name</span>
                <input class="input" name="name" required maxlength="255" value="<?= e((string) $item['name']) ?>">
            </label>
            <label class="field">
                <span class="field-label">Code (optional)</span>
                <input class="input" name="item_code" maxlength="64" value="<?= e((string) ($item['item_code'] ?? '')) ?>">
            </label>
        </div>
        <div class="form-row">
            <label class="field">
                <span class="field-label">Unit</span>
                <select class="input" name="unit" required>
                    <?php foreach ($unitChoices as $code => $label) : ?>
                        <option value="<?= e($code) ?>"<?= (string) $item['unit'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Rate (₹)</span>
                <input class="input" name="unit_rate" value="<?= e((string) ($item['unit_rate'] ?? '0')) ?>" inputmode="decimal" min="0" step="any" required>
            </label>
        </div>
        <label class="field">
            <span class="field-label">Description (optional)</span>
            <textarea class="input textarea" name="description" rows="3" maxlength="5000"><?= e((string) ($item['description'] ?? '')) ?></textarea>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save changes</button>
        </div>
    </form>
</div>

<div class="card card-form">
    <h3 class="subsection-title">Adjust quantity</h3>
    <p class="muted small">Current available: <strong><?= e(cmc_resource_format_qty((float) $item['quantity'])) ?></strong> <?= e((string) $item['unit']) ?>.</p>
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="action" value="adjust_quantity">
        <div class="form-row">
            <label class="field grow">
                <span class="field-label">Change (+ or −)</span>
                <input class="input" type="number" name="quantity_delta" value="0" step="any" inputmode="decimal" autocomplete="off" required>
            </label>
            <label class="field grow">
                <span class="field-label">Note (optional)</span>
                <input class="input" name="note" maxlength="500" placeholder="Reason / reference">
            </label>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply</button>
        </div>
    </form>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">Recent movements</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>Δ</th>
                <th>Balance</th>
                <th>By</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="5" class="muted">No movements yet.</td></tr>
            <?php else : ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td class="muted"><?= e((string) $r['created_at']) ?></td>
                        <td><?= e(cmc_resource_format_qty((float) $r['quantity_delta'])) ?></td>
                        <td><?= e(cmc_resource_format_qty((float) $r['balance_after'])) ?></td>
                        <td><?= $r['actor_name'] !== null ? e((string) $r['actor_name']) : '—' ?></td>
                        <td><?= $r['note'] !== null ? e((string) $r['note']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
cmc_layout_end();
