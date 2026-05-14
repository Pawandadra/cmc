<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcBillingUser;
$pdo = cmc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        $bid = (int) ($_POST['id'] ?? 0);
        $err = cmc_billing_delete_internal_bill($pdo, $bid);
        if ($err !== null) {
            cmc_flash_set('error', $err);
        } else {
            cmc_flash_set('success', 'Bill deleted.');
        }
        cmc_redirect('billing/index.php');
    }
    cmc_flash_set('error', 'Invalid request.');
    cmc_redirect('billing/index.php');
}

$bills = $pdo->query(
    'SELECT b.id, b.fulfillment_id, b.material_subtotal, b.labour_subtotal, b.equipment_subtotal, b.wage_adjustment, b.other_expenses, b.grand_total, b.created_at,
            f.complaint_id, c.reference_code, c.subject
     FROM internal_bills b
     JOIN complaint_fulfillments f ON f.id = b.fulfillment_id
     JOIN complaints c ON c.id = f.complaint_id
     ORDER BY b.id DESC
     LIMIT 150'
)->fetchAll();

cmc_layout_start('Billing', $user);
?>
<div class="toolbar">
    <a class="btn btn-primary" href="<?= e(cmc_url('billing/create.php')) ?>">Generate bill</a>
</div>
<p class="muted small">From fulfillments and the fields on each bill.</p>

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
            <?php if (!$bills) : ?>
                <tr><td colspan="11" class="muted">No bills yet.</td></tr>
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
                            <form method="post" action="<?= e(cmc_url('billing/index.php')) ?>" class="inline-form" data-confirm="Delete this bill permanently? Line items will be removed. The fulfillment record is kept.">
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
