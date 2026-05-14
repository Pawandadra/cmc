<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcResourceUser;
$pdo = cmc_db();

/** @return string Path with query to preserve list filters after POST. */
function cmc_resources_index_list_path(): string
{
    $keep = [];
    foreach (['q', 'kind'] as $k) {
        if (!isset($_GET[$k]) || $_GET[$k] === '' || $_GET[$k] === null) {
            continue;
        }
        $keep[$k] = $_GET[$k];
    }

    return 'resources/index.php' . ($keep !== [] ? '?' . http_build_query($keep) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    $resListPath = cmc_resources_index_list_path();
    if ($action === 'delete') {
        $did = (int) ($_POST['id'] ?? 0);
        $err = cmc_resource_item_delete($pdo, $did);
        if ($err !== null) {
            cmc_flash_set('error', $err);
        } else {
            cmc_flash_set('success', 'Resource deleted.');
        }
        cmc_redirect($resListPath);
    }
    if ($action !== 'create') {
        cmc_redirect($resListPath);
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
        cmc_redirect($resListPath);
    }
    if (!in_array($kind, ['material', 'equipment', 'worker'], true)) {
        cmc_flash_set('error', 'Invalid resource type.');
        cmc_redirect($resListPath);
    }
    if ($unit === '' || strlen($unit) > 32 || !cmc_resource_unit_is_allowed($unit, null)) {
        cmc_flash_set('error', 'Please choose a valid unit from the list.');
        cmc_redirect($resListPath);
    }
    if (!is_numeric($rateRaw) || (float) $rateRaw < 0) {
        cmc_flash_set('error', 'Rate must be a number ≥ 0.');
        cmc_redirect($resListPath);
    }
    $unitRate = (float) $rateRaw;
    if (strlen($description) > 5000) {
        cmc_flash_set('error', 'Description is too long.');
        cmc_redirect($resListPath);
    }

    $codeVal = $code === '' ? null : $code;
    if ($codeVal !== null && strlen($codeVal) > 64) {
        cmc_flash_set('error', 'Item code is too long.');
        cmc_redirect($resListPath);
    }

    if (!is_numeric($initRaw)) {
        cmc_flash_set('error', 'Initial quantity must be a number.');
        cmc_redirect($resListPath);
    }
    $init = (float) $initRaw;
    if ($init < 0 || abs($init) > 1e12) {
        cmc_flash_set('error', 'Initial quantity is not valid.');
        cmc_redirect($resListPath);
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
        cmc_redirect($resListPath);
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$kindFilter = trim((string) ($_GET['kind'] ?? ''));
if (!in_array($kindFilter, ['material', 'equipment', 'worker', ''], true)) {
    $kindFilter = '';
}

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $needle = mb_strtolower($q, 'UTF-8');
    $where[] = '(
        INSTR(LOWER(i.name), ?) > 0
        OR INSTR(LOWER(COALESCE(i.item_code, \'\')), ?) > 0
        OR INSTR(LOWER(CAST(i.id AS TEXT)), ?) > 0
        OR INSTR(LOWER(COALESCE(i.description, \'\')), ?) > 0
    )';
    array_push($params, $needle, $needle, $needle, $needle);
}
if ($kindFilter !== '') {
    $where[] = 'i.resource_kind = ?';
    $params[] = $kindFilter;
}

$sql = 'SELECT i.id, i.name, i.item_code, i.unit, i.quantity, i.resource_kind, i.unit_rate, i.updated_at
        FROM inventory_items i
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY i.resource_kind, i.name COLLATE NOCASE';
$st = $pdo->prepare($sql);
$st->execute($params);
$items = $st->fetchAll();

$unitChoices = cmc_resource_unit_choices();

$listUrl = cmc_url('resources/index.php');
$listQueryParams = [];
if ($q !== '') {
    $listQueryParams['q'] = $q;
}
if ($kindFilter !== '') {
    $listQueryParams['kind'] = $kindFilter;
}
$listUrlWithQuery = $listUrl . ($listQueryParams !== [] ? '?' . http_build_query($listQueryParams) : '');
$hasFilters = $q !== '' || $kindFilter !== '';

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
<div class="card card-form" style="margin-bottom: 1rem;">
    <form method="get" action="<?= e($listUrl) ?>" class="form-stack">
        <div class="form-row" style="flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <label class="field grow" style="min-width: 200px;">
                <span class="field-label">Search</span>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, code, ID, description" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">Type</span>
                <select class="input" name="kind">
                    <option value="">All types</option>
                    <option value="material"<?= $kindFilter === 'material' ? ' selected' : '' ?>><?= e(cmc_resource_kind_label('material')) ?></option>
                    <option value="equipment"<?= $kindFilter === 'equipment' ? ' selected' : '' ?>><?= e(cmc_resource_kind_label('equipment')) ?></option>
                    <option value="worker"<?= $kindFilter === 'worker' ? ' selected' : '' ?>><?= e(cmc_resource_kind_label('worker')) ?></option>
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
            <?php if ($items === []) : ?>
                <tr><td colspan="9" class="muted"><?= $hasFilters ? 'No resources match your filters.' : 'No resources yet.' ?></td></tr>
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
                            <form method="post" action="<?= e($listUrlWithQuery) ?>" class="inline-form" data-confirm="Delete this resource? This cannot be undone if the item is unused on fulfillments.">
                                <?= cmc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
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
