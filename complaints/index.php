<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = cmc_require_login();
$pdo = cmc_db();

$rows = [];
$heading = 'Complaints';

if ($user['role'] === 'member' || $user['role'] === 'hod') {
    $heading = $user['role'] === 'hod' ? 'Department complaints' : 'My complaints';
    if ($user['role'] === 'member') {
        $st = $pdo->prepare(
            'SELECT c.id, c.subject, c.status, c.created_at, c.updated_at,
                    d.name AS department_name, o.name AS organisation_name
             FROM complaints c
             JOIN departments d ON d.id = c.department_id
             JOIN organisations o ON o.id = c.organisation_id
             WHERE c.raised_by_user_id = ?
             ORDER BY c.id DESC
             LIMIT 200'
        );
        $st->execute([(int) $user['id']]);
    } else {
        $st = $pdo->prepare(
            'SELECT c.id, c.subject, c.status, c.created_at, c.updated_at,
                    rb.full_name AS raised_by_name,
                    d.name AS department_name, o.name AS organisation_name
             FROM complaints c
             JOIN users rb ON rb.id = c.raised_by_user_id
             JOIN departments d ON d.id = c.department_id
             JOIN organisations o ON o.id = c.organisation_id
             WHERE c.department_id = ?
             ORDER BY c.id DESC
             LIMIT 200'
        );
        $st->execute([(int) $user['department_id']]);
    }
    $rows = $st->fetchAll();
} elseif ($user['role'] === 'sde') {
    $heading = 'Complaints (cell queue)';
    $rows = $pdo->query(
        "SELECT c.id, c.subject, c.status, c.created_at, c.updated_at,
                rb.full_name AS raised_by_name, rb.email AS raised_by_email,
                d.name AS department_name, o.name AS organisation_name
         FROM complaints c
         JOIN users rb ON rb.id = c.raised_by_user_id
         JOIN departments d ON d.id = c.department_id
         JOIN organisations o ON o.id = c.organisation_id
         WHERE c.status IN ('pending_sde', 'sde_approved', 'sde_rejected')
         ORDER BY CASE WHEN c.status = 'pending_sde' THEN 0 ELSE 1 END, c.updated_at DESC
         LIMIT 200"
    )->fetchAll();
} elseif ($user['role'] === 'admin') {
    $rows = $pdo->query(
        "SELECT c.id, c.subject, c.status, c.created_at, c.updated_at,
                rb.full_name AS raised_by_name,
                d.name AS department_name, o.name AS organisation_name
         FROM complaints c
         JOIN users rb ON rb.id = c.raised_by_user_id
         JOIN departments d ON d.id = c.department_id
         JOIN organisations o ON o.id = c.organisation_id
         ORDER BY c.id DESC
         LIMIT 200"
    )->fetchAll();
} else {
    http_response_code(403);
    exit('Forbidden');
}

cmc_layout_start($heading, $user);
?>
<?php if (in_array($user['role'], ['member', 'hod'], true)) : ?>
    <div class="toolbar">
        <a class="btn btn-primary" href="<?= e(cmc_url('complaints/create.php')) ?>">Raise complaint</a>
    </div>
<?php endif; ?>

<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Subject</th>
                <?php if ($user['role'] !== 'member') : ?>
                    <th>Raised by</th>
                <?php endif; ?>
                <?php if ($user['role'] === 'sde' || $user['role'] === 'admin') : ?>
                    <th>Organisation</th>
                    <th>Department</th>
                <?php endif; ?>
                <th>Status</th>
                <th>Updated</th>
                <th class="th-actions"> </th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="99" class="muted">No complaints to show.</td></tr>
            <?php else : ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td class="muted"><?= (int) $r['id'] ?></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <?php if ($user['role'] !== 'member') : ?>
                            <td><?= e((string) ($r['raised_by_name'] ?? '')) ?></td>
                        <?php endif; ?>
                        <?php if ($user['role'] === 'sde' || $user['role'] === 'admin') : ?>
                            <td><?= e((string) $r['organisation_name']) ?></td>
                            <td><?= e((string) $r['department_name']) ?></td>
                        <?php endif; ?>
                        <td><?= e(cmc_complaint_status_label((string) $r['status'])) ?></td>
                        <td class="muted"><?= e((string) $r['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/view.php?id=' . (int) $r['id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
cmc_layout_end();
