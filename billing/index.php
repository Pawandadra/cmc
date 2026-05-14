<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcBillingUser;
$pdo = cmc_db();

/** @return string Path with query to preserve list filters after POST. */
function cmc_billing_index_list_path(): string
{
    $keep = [];
    foreach (['q', 'fulfillment_id'] as $k) {
        if (!isset($_GET[$k]) || $_GET[$k] === '' || $_GET[$k] === null) {
            continue;
        }
        $keep[$k] = $_GET[$k];
    }

    return 'billing/index.php' . ($keep !== [] ? '?' . http_build_query($keep) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $billRedir = cmc_billing_index_list_path();
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        $bid = (int) ($_POST['id'] ?? 0);
        $err = cmc_billing_delete_internal_bill($pdo, $bid);
        if ($err !== null) {
            cmc_flash_set('error', $err);
        } else {
            cmc_flash_set('success', 'Bill deleted.');
        }
        cmc_redirect($billRedir);
    }
    cmc_flash_set('error', 'Invalid request.');
    cmc_redirect($billRedir);
}

$q = trim((string) ($_GET['q'] ?? ''));
$fulfillmentFilter = isset($_GET['fulfillment_id']) ? (int) $_GET['fulfillment_id'] : 0;
if ($fulfillmentFilter < 1) {
    $fulfillmentFilter = 0;
} else {
    $chk = $pdo->prepare('SELECT 1 FROM complaint_fulfillments WHERE id = ?');
    $chk->execute([$fulfillmentFilter]);
    if (!$chk->fetch()) {
        $fulfillmentFilter = 0;
    }
}

$where = ['1 = 1'];
$params = [];
if ($fulfillmentFilter > 0) {
    $where[] = 'b.fulfillment_id = ?';
    $params[] = $fulfillmentFilter;
}
if ($q !== '') {
    $needle = mb_strtolower($q, 'UTF-8');
    $where[] = '(
        INSTR(LOWER(COALESCE(c.reference_code, \'\')), ?) > 0
        OR INSTR(LOWER(c.subject), ?) > 0
        OR INSTR(LOWER(CAST(b.id AS TEXT)), ?) > 0
        OR INSTR(LOWER(CAST(c.id AS TEXT)), ?) > 0
        OR INSTR(LOWER(CAST(b.fulfillment_id AS TEXT)), ?) > 0
    )';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

$sql = 'SELECT b.id, b.fulfillment_id, b.material_subtotal, b.labour_subtotal, b.equipment_subtotal, b.wage_adjustment, b.other_expenses, b.grand_total, b.created_at,
            f.complaint_id, c.reference_code, c.subject
     FROM internal_bills b
     JOIN complaint_fulfillments f ON f.id = b.fulfillment_id
     JOIN complaints c ON c.id = f.complaint_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY b.id DESC
     LIMIT 150';
$st = $pdo->prepare($sql);
$st->execute($params);
$bills = $st->fetchAll();

$listUrl = cmc_url('billing/index.php');
$listQueryParams = [];
if ($q !== '') {
    $listQueryParams['q'] = $q;
}
if ($fulfillmentFilter > 0) {
    $listQueryParams['fulfillment_id'] = $fulfillmentFilter;
}
$listUrlWithQuery = $listUrl . ($listQueryParams !== [] ? '?' . http_build_query($listQueryParams) : '');
$hasFilters = $q !== '' || $fulfillmentFilter > 0;

cmc_layout_start('Billing', $user);
?>
<div class="toolbar">
    <a class="btn btn-primary" href="<?= e(cmc_url('billing/create.php')) ?>">Generate bill</a>
</div>
<p class="muted small">From fulfillments and the fields on each bill.</p>

<div class="card card-form" style="margin-bottom: 1rem;">
    <form method="get" action="<?= e($listUrl) ?>" class="form-stack">
        <div class="form-row" style="flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <label class="field grow" style="min-width: 200px;">
                <span class="field-label">Search</span>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Complaint ID, subject, bill #, complaint #, fulfillment #" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">Fulfillment ID</span>
                <input class="input" type="number" name="fulfillment_id" value="<?= $fulfillmentFilter > 0 ? (string) $fulfillmentFilter : '' ?>" min="1" step="1" placeholder="Exact match">
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
                <th>Bill</th>
                <th>Complaint ID</th>
                <th>Fulfillment</th>
                <th>Materials</th>
                <th>Labour</th>
                <th>Equipment</th>
                <th>Wage adj.</th>
                <th>Other</th>
                <th>Total</th>
                <th>Created</th>
                <th class="th-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($bills === []) : ?>
                <tr><td colspan="11" class="muted"><?= $hasFilters ? 'No bills match your filters.' : 'No bills yet.' ?></td></tr>
            <?php else : ?>
                <?php foreach ($bills as $b) : ?>
                    <tr>
                        <td class="muted">#<?= (int) $b['id'] ?></td>
                        <td><code><?= e((string) ($b['reference_code'] ?? '')) ?></code></td>
                        <td class="muted"><?= (int) $b['fulfillment_id'] ?></td>
                        <td><?= e(cmc_resource_format_money((float) $b['material_subtotal'])) ?></td>
                        <td><?= e(cmc_resource_format_money((float) $b['labour_subtotal'])) ?></td>
                        <td><?= e(cmc_resource_format_money((float) ($b['equipment_subtotal'] ?? 0))) ?></td>
                        <td><?= e(cmc_resource_format_money((float) $b['wage_adjustment'])) ?></td>
                        <td><?= e(cmc_resource_format_money((float) $b['other_expenses'])) ?></td>
                        <td><strong><?= e(cmc_resource_format_money((float) $b['grand_total'])) ?></strong></td>
                        <td class="muted"><?= e((string) $b['created_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('billing/view.php?id=' . (int) $b['id'])) ?>">View</a>
                            <form method="post" action="<?= e($listUrlWithQuery) ?>" class="inline-form" data-confirm="Delete this bill permanently? Line items will be removed. The fulfillment record is kept.">
                                <?= cmc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
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
