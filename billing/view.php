<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcBillingUser;
$pdo = cmc_db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    cmc_redirect('billing/index.php');
}

$st = $pdo->prepare(
    'SELECT b.*, f.complaint_id, c.subject
     FROM internal_bills b
     JOIN complaint_fulfillments f ON f.id = b.fulfillment_id
     JOIN complaints c ON c.id = f.complaint_id
     WHERE b.id = ?'
);
$st->execute([$id]);
$bill = $st->fetch();
if (!$bill) {
    cmc_flash_set('error', 'Bill not found.');
    cmc_redirect('billing/index.php');
}

$lines = $pdo->prepare(
    'SELECT line_type, label, quantity, unit_rate, line_total, sort_order
     FROM internal_bill_lines
     WHERE bill_id = ?
     ORDER BY sort_order ASC, id ASC'
);
$lines->execute([$id]);
$rows = $lines->fetchAll();

cmc_layout_start('Bill #' . $id, $user);
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('billing/index.php')) ?>">← All bills</a>
    <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/view.php?id=' . (int) $bill['complaint_id'])) ?>">Complaint <?= (int) $bill['complaint_id'] ?></a>
</div>

<div class="card">
    <h2 class="card-title"><?= e((string) $bill['subject']) ?></h2>
    <dl class="dl-grid">
        <dt>Fulfillment</dt>
        <dd><?= (int) $bill['fulfillment_id'] ?></dd>
        <dt>Material subtotal</dt>
        <dd><?= e(cmc_resource_format_money((float) $bill['material_subtotal'])) ?></dd>
        <dt>Labour subtotal</dt>
        <dd><?= e(cmc_resource_format_money((float) $bill['labour_subtotal'])) ?></dd>
        <dt>Equipment subtotal</dt>
        <dd><?= e(cmc_resource_format_money((float) ($bill['equipment_subtotal'] ?? 0))) ?></dd>
        <dt>Wage adjustment</dt>
        <dd><?= e(cmc_resource_format_money((float) $bill['wage_adjustment'])) ?></dd>
        <dt>Other expenses</dt>
        <dd><?= e(cmc_resource_format_money((float) $bill['other_expenses'])) ?></dd>
        <dt>Grand total</dt>
        <dd><strong><?= e(cmc_resource_format_money((float) $bill['grand_total'])) ?></strong></dd>
        <dt>Recorded at</dt>
        <dd class="muted"><?= e((string) $bill['created_at']) ?></dd>
    </dl>
    <?php if (!empty($bill['notes'])) : ?>
        <p class="muted"><strong>Notes:</strong> <?= nl2br(e((string) $bill['notes'])) ?></p>
    <?php endif; ?>
</div>

<div class="toolbar toolbar-mt">
    <h3 class="section-title">Line items</h3>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>Type</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Rate</th>
                <th>Line total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="5" class="muted">No lines stored.</td></tr>
            <?php else : ?>
                <?php foreach ($rows as $ln) : ?>
                    <tr>
                        <td><?= e((string) $ln['line_type']) ?></td>
                        <td><?= e((string) $ln['label']) ?></td>
                        <td><?= e(cmc_resource_format_qty((float) $ln['quantity'])) ?></td>
                        <td><?= e(cmc_resource_format_money((float) $ln['unit_rate'])) ?></td>
                        <td><?= e(cmc_resource_format_money((float) $ln['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
cmc_layout_end();
