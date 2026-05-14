<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcAdminUser;
$pdo = cmc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            cmc_flash_set('error', 'Invalid complaint.');
        } else {
            $err = cmc_complaint_admin_delete($pdo, $id);
            if ($err !== null) {
                cmc_flash_set('error', $err);
            } else {
                cmc_flash_set('success', 'Complaint deleted.');
            }
        }
        cmc_redirect('admin/complaints.php');
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $result = cmc_complaint_admin_bulk_delete($pdo, $ids);
        if ($result['error'] !== null) {
            cmc_flash_set('error', $result['error']);
        } else {
            cmc_flash_set('success', 'Deleted ' . $result['deleted'] . ' complaint(s).');
        }
        cmc_redirect('admin/complaints.php');
    } else {
        cmc_flash_set('error', 'Unknown action.');
        cmc_redirect('admin/complaints.php');
    }
}

$rows = $pdo->query(
    'SELECT c.id, c.reference_code, c.subject, c.status, c.updated_at,
            o.name AS organisation_name, d.name AS department_name,
            rb.full_name AS raised_by_name
     FROM complaints c
     JOIN organisations o ON o.id = c.organisation_id
     JOIN departments d ON d.id = c.department_id
     JOIN users rb ON rb.id = c.raised_by_user_id
     ORDER BY c.id DESC
     LIMIT 500'
)->fetchAll();

$listUrl = cmc_url('admin/complaints.php');

cmc_layout_start('Complaints (admin)', $user);
?>
<p class="muted small">Delete removes the complaint, timeline, attachments on disk, fulfillment plans, and any linked internal bills. This cannot be undone.</p>

<form method="post" id="bulk-delete-form" action="<?= e($listUrl) ?>" class="card card-form" style="margin-bottom: 1rem;" data-confirm="Delete all selected complaints? This cannot be undone.">
    <?= cmc_csrf_field() ?>
    <input type="hidden" name="action" value="bulk_delete">
    <div class="form-row" style="align-items: center; gap: 0.75rem;">
        <button class="btn btn-danger" type="submit">Delete selected</button>
        <span class="muted small">Use the row checkboxes, then confirm.</span>
    </div>
</form>

<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th style="width: 2.5rem;">
                    <input type="checkbox" id="complaint-select-all" title="Select all" aria-label="Select all">
                </th>
                <th>Complaint ID</th>
                <th>Subject</th>
                <th>Raised by</th>
                <th>Organisation</th>
                <th>Department</th>
                <th>Status</th>
                <th>Updated</th>
                <th class="th-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="9" class="muted">No complaints.</td></tr>
            <?php else : ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulk-delete-form" aria-label="Select complaint">
                        </td>
                        <td class="muted"><code><?= e((string) ($r['reference_code'] ?? '')) ?></code></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td><?= e((string) $r['raised_by_name']) ?></td>
                        <td><?= e((string) $r['organisation_name']) ?></td>
                        <td><?= e((string) $r['department_name']) ?></td>
                        <td><?= e(cmc_complaint_status_label((string) $r['status'])) ?></td>
                        <td class="muted"><?= e((string) $r['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/view.php?' . (trim((string) ($r['reference_code'] ?? '')) !== '' ? 'ref=' . rawurlencode((string) $r['reference_code']) : 'id=' . (int) $r['id']))) ?>">Open</a>
                            <form method="post" action="<?= e($listUrl) ?>" class="inline-form" data-confirm="Permanently delete this complaint and all related records?">
                                <?= cmc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    var all = document.getElementById('complaint-select-all');
    var form = document.getElementById('bulk-delete-form');
    if (!all || !form) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
            cb.checked = all.checked;
        });
    });
})();
</script>
<?php
cmc_layout_end();
