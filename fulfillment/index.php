<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcFulfillmentUser;
$pdo = cmc_db();

$awaiting = $pdo->query(
    "SELECT c.id, c.subject, c.updated_at,
            rb.full_name AS raised_by_name,
            o.name AS organisation_name, d.name AS department_name
     FROM complaints c
     JOIN users rb ON rb.id = c.raised_by_user_id
     JOIN organisations o ON o.id = c.organisation_id
     JOIN departments d ON d.id = c.department_id
     LEFT JOIN complaint_fulfillments f ON f.complaint_id = c.id
     WHERE c.status = 'sde_approved' AND f.id IS NULL
     ORDER BY c.updated_at DESC
     LIMIT 100"
)->fetchAll();

$active = $pdo->query(
    "SELECT f.id, f.complaint_id, f.work_status, f.updated_at,
            c.subject,
            rb.full_name AS raised_by_name,
            o.name AS organisation_name, d.name AS department_name
     FROM complaint_fulfillments f
     JOIN complaints c ON c.id = f.complaint_id
     JOIN users rb ON rb.id = c.raised_by_user_id
     JOIN organisations o ON o.id = c.organisation_id
     JOIN departments d ON d.id = c.department_id
     WHERE f.work_status IN ('planning', 'in_progress', 'on_hold')
     ORDER BY CASE f.work_status WHEN 'in_progress' THEN 0 WHEN 'planning' THEN 1 ELSE 2 END, f.updated_at DESC
     LIMIT 150"
)->fetchAll();

$closed = $pdo->query(
    "SELECT f.id, f.complaint_id, f.work_status, f.updated_at,
            c.subject,
            rb.full_name AS raised_by_name,
            o.name AS organisation_name, d.name AS department_name
     FROM complaint_fulfillments f
     JOIN complaints c ON c.id = f.complaint_id
     JOIN users rb ON rb.id = c.raised_by_user_id
     JOIN organisations o ON o.id = c.organisation_id
     JOIN departments d ON d.id = c.department_id
     WHERE f.work_status IN ('completed', 'cancelled')
     ORDER BY f.updated_at DESC
     LIMIT 80"
)->fetchAll();

cmc_layout_start('Fulfillment work', $user);
?>
<p class="muted">Approved complaints appear below. Open a row to manage fulfillment. <a href="<?= e(cmc_url('resources/index.php')) ?>">Resources</a> · <a href="<?= e(cmc_url('billing/index.php')) ?>">Billing</a></p>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">Awaiting fulfillment plan</h2>
    <span class="muted small"><?= count($awaiting) ?> complaint(s)</span>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Subject</th>
                <th>Raised by</th>
                <th>Organisation</th>
                <th>Department</th>
                <th>Approved</th>
                <th class="th-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$awaiting) : ?>
                <tr><td colspan="7" class="muted">No approved complaints waiting for a fulfillment plan.</td></tr>
            <?php else : ?>
                <?php foreach ($awaiting as $r) : ?>
                    <tr>
                        <td class="muted"><?= (int) $r['id'] ?></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td><?= e((string) $r['raised_by_name']) ?></td>
                        <td><?= e((string) $r['organisation_name']) ?></td>
                        <td><?= e((string) $r['department_name']) ?></td>
                        <td class="muted"><?= e((string) $r['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-primary" href="<?= e(cmc_url('fulfillment/work.php?complaint_id=' . (int) $r['id'])) ?>">Start</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">Active fulfillment</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>Complaint</th>
                <th>Subject</th>
                <th>Raised by</th>
                <th>Status</th>
                <th>Updated</th>
                <th class="th-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$active) : ?>
                <tr><td colspan="6" class="muted">No work in planning or in progress.</td></tr>
            <?php else : ?>
                <?php foreach ($active as $r) : ?>
                    <tr>
                        <td class="muted"><?= (int) $r['complaint_id'] ?></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td><?= e((string) $r['raised_by_name']) ?></td>
                        <td><span class="pill"><?= e(cmc_fulfillment_work_status_label((string) $r['work_status'])) ?></span></td>
                        <td class="muted"><?= e((string) $r['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('fulfillment/work.php?complaint_id=' . (int) $r['complaint_id'])) ?>">Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="toolbar toolbar-mt">
    <h2 class="section-title">Completed &amp; cancelled</h2>
</div>
<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>Complaint</th>
                <th>Subject</th>
                <th>Raised by</th>
                <th>Outcome</th>
                <th>Updated</th>
                <th class="th-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$closed) : ?>
                <tr><td colspan="6" class="muted">No closed fulfillment records yet.</td></tr>
            <?php else : ?>
                <?php foreach ($closed as $r) : ?>
                    <tr>
                        <td class="muted"><?= (int) $r['complaint_id'] ?></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td><?= e((string) $r['raised_by_name']) ?></td>
                        <td><?= e(cmc_fulfillment_work_status_label((string) $r['work_status'])) ?></td>
                        <td class="muted"><?= e((string) $r['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('fulfillment/work.php?complaint_id=' . (int) $r['complaint_id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">Complaint list</a>
</div>
<?php
cmc_layout_end();
