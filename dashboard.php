<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/dashboard_lib.php';

$user = cmc_require_login();
$pdo = cmc_db();
$title = 'Dashboard';

/** @param array<string, mixed> $row */
function cmc_dashboard_complaint_href(array $row): string
{
    $ref = trim((string) ($row['reference_code'] ?? ''));

    return cmc_url('complaints/view.php?' . ($ref !== '' ? 'ref=' . rawurlencode($ref) : 'id=' . (int) ($row['id'] ?? 0)));
}

cmc_layout_start($title, $user);

$role = (string) $user['role'];

if ($role === 'member') {
    $uid = (int) $user['id'];
    $stats = cmc_dashboard_complaint_workflow_stats($pdo, 'raised_by_user_id', $uid);
    $fulMix = cmc_dashboard_fulfillment_mix_for_scope($pdo, 'raised_by_user_id', $uid);
    $recent = cmc_dashboard_recent_complaints($pdo, 'c.raised_by_user_id = ?', [$uid], 8);
    $by = $stats['by_status'];
    $total = (int) $stats['total'];
    $fulfilled = (int) $stats['fulfilled_completed'];
    $sumFulMix = array_sum($fulMix);
    $statusSegs = [
        ['n' => $by['pending_hod'], 'class' => 'seg-hod', 'href' => 'complaints/index.php?status=pending_hod', 'label' => 'Awaiting HOD'],
        ['n' => $by['pending_sde'], 'class' => 'seg-sde', 'href' => 'complaints/index.php?status=pending_sde', 'label' => 'Awaiting SDE'],
        ['n' => $by['sde_approved'], 'class' => 'seg-approved', 'href' => 'complaints/index.php?status=sde_approved', 'label' => 'SDE approved'],
        ['n' => $by['hod_rejected'], 'class' => 'seg-rej', 'href' => 'complaints/index.php?status=hod_rejected', 'label' => 'Rejected by HOD'],
        ['n' => $by['sde_rejected'], 'class' => 'seg-rej-sde', 'href' => 'complaints/index.php?status=sde_rejected', 'label' => 'Rejected by SDE'],
    ];
    ?>
    <section class="card dashboard-snapshot">
        <header class="dashboard-snapshot-header">
            <div>
                <h2 class="dashboard-snapshot-title">Welcome, <?= e((string) $user['full_name']) ?></h2>
                <p class="muted dashboard-snapshot-meta"><?= e((string) $user['organisation_name']) ?> · <?= e((string) $user['department_name']) ?></p>
            </div>
            <div class="dashboard-snapshot-actions">
                <a class="btn btn-primary" href="<?= e(cmc_url('complaints/create.php')) ?>">Raise a complaint</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">My complaints</a>
            </div>
        </header>

        <div class="dashboard-snapshot-body">
            <div class="dashboard-snapshot-total" aria-label="Total complaints">
                <span class="dashboard-snapshot-total-num"><?= $total ?></span>
                <span class="dashboard-snapshot-total-label"><?= $total === 1 ? 'complaint' : 'complaints' ?></span>
            </div>
            <div class="dashboard-snapshot-main">
                <h3 class="dashboard-snapshot-h">Status</h3>
                <p class="muted small dashboard-snapshot-lead">Each colour matches a list filter — click a row to open that view.</p>
                <div class="dashboard-stackbar" role="img" aria-label="Complaints by status">
                    <?php if ($total < 1) : ?>
                        <span class="dashboard-stackbar-empty">No complaints yet — raise one to see progress here.</span>
                    <?php else : ?>
                        <?php foreach ($statusSegs as $seg) : ?>
                            <?php if ($seg['n'] > 0) : ?>
                                <span class="dashboard-stackbar-seg <?= e($seg['class']) ?>" style="flex: <?= (int) $seg['n'] ?> 1 0; min-width: <?= $seg['n'] > 0 ? '8px' : '0' ?>;" title="<?= e($seg['label']) ?>: <?= (int) $seg['n'] ?>"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <ul class="dashboard-legend">
                    <?php foreach ($statusSegs as $seg) : ?>
                        <li>
                            <a class="dashboard-legend-link" href="<?= e(cmc_url($seg['href'])) ?>">
                                <span class="dashboard-legend-dot <?= e($seg['class']) ?>"></span>
                                <span class="dashboard-legend-label"><?= e($seg['label']) ?></span>
                                <span class="dashboard-legend-count"><?= (int) $seg['n'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="dashboard-legend-extra">
                        <span class="dashboard-legend-dot seg-fulfilled"></span>
                        <span class="dashboard-legend-label">Delivered (fulfillment completed)</span>
                        <span class="dashboard-legend-count"><?= $fulfilled ?></span>
                    </li>
                </ul>
                <?php if ($by['sde_approved'] > 0 && (int) $stats['approved_with_open_fulfillment'] > 0) : ?>
                    <p class="muted small dashboard-snapshot-note">
                        <?= (int) $stats['approved_with_open_fulfillment'] ?> approved <?= (int) $stats['approved_with_open_fulfillment'] === 1 ? 'case has' : 'cases have' ?> open fulfillment work.
                    </p>
                <?php endif; ?>

                <?php if ($by['sde_approved'] > 0) : ?>
                    <div class="dashboard-snapshot-divider"></div>
                    <h3 class="dashboard-snapshot-h">Fulfillment (SDE-approved)</h3>
                    <?php if ($sumFulMix < 1) : ?>
                        <p class="muted small">The cell has not attached a fulfillment plan yet, or it is still being set up.</p>
                    <?php else : ?>
                        <div class="dashboard-stackbar dashboard-stackbar-ful" role="img" aria-label="Fulfillment mix">
                            <?php
                            $ffClasses = [
                                'pending_setup' => 'segff-setup',
                                'planning' => 'segff-plan',
                                'in_progress' => 'segff-run',
                                'on_hold' => 'segff-hold',
                                'completed' => 'segff-done',
                                'cancelled' => 'segff-can',
                            ];
                            foreach ($fulMix as $bucket => $n) :
                                $cls = $ffClasses[$bucket] ?? 'segff-plan';
                                ?>
                                <span class="dashboard-stackbar-seg <?= e($cls) ?>" style="flex: <?= (int) $n ?> 1 0; min-width: <?= $n > 0 ? '8px' : '0' ?>;" title="<?= e((string) $bucket) ?>: <?= (int) $n ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <ul class="dashboard-legend dashboard-legend-compact">
                            <?php
                            $bucketLabels = [
                                'pending_setup' => 'Not started',
                                'planning' => cmc_fulfillment_work_status_label('planning'),
                                'in_progress' => cmc_fulfillment_work_status_label('in_progress'),
                                'on_hold' => cmc_fulfillment_work_status_label('on_hold'),
                                'completed' => cmc_fulfillment_work_status_label('completed'),
                                'cancelled' => cmc_fulfillment_work_status_label('cancelled'),
                            ];
                            foreach ($fulMix as $bucket => $n) :
                                ?>
                                <li>
                                    <span class="dashboard-legend-dot <?= e($ffClasses[$bucket] ?? 'segff-plan') ?>"></span>
                                    <span class="dashboard-legend-label"><?= e($bucketLabels[$bucket] ?? $bucket) ?></span>
                                    <span class="dashboard-legend-count"><?= (int) $n ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card dashboard-recent">
        <div class="dashboard-recent-head">
            <h3 class="card-title">Recent complaints</h3>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">View all</a>
        </div>
        <?php if ($recent === []) : ?>
            <p class="muted">No complaints yet.</p>
        <?php else : ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="th-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r) : ?>
                            <tr>
                                <td><code class="dashboard-ref"><?= e((string) ($r['reference_code'] ?? '')) ?></code></td>
                                <td><?= e((string) ($r['subject'] ?? '')) ?></td>
                                <td><span class="pill pill-soft"><?= e(cmc_complaint_status_label((string) ($r['status'] ?? ''))) ?></span></td>
                                <td class="muted small"><?= e((string) ($r['updated_at'] ?? '')) ?></td>
                                <td class="td-actions"><a class="btn btn-sm btn-ghost" href="<?= e(cmc_dashboard_complaint_href($r)) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($role === 'hod') {
    $deptId = (int) ($user['department_id'] ?? 0);
    $stats = cmc_dashboard_complaint_workflow_stats($pdo, 'department_id', $deptId);
    $fulMix = cmc_dashboard_fulfillment_mix_for_scope($pdo, 'department_id', $deptId);
    $recent = cmc_dashboard_recent_complaints($pdo, 'c.department_id = ?', [$deptId], 8);
    $by = $stats['by_status'];
    $total = (int) $stats['total'];
    $fulfilled = (int) $stats['fulfilled_completed'];
    $sumFulMix = array_sum($fulMix);
    $statusSegs = [
        ['n' => $by['pending_hod'], 'class' => 'seg-hod', 'href' => 'complaints/index.php?status=pending_hod', 'label' => 'Awaiting HOD'],
        ['n' => $by['pending_sde'], 'class' => 'seg-sde', 'href' => 'complaints/index.php?status=pending_sde', 'label' => 'Awaiting SDE'],
        ['n' => $by['sde_approved'], 'class' => 'seg-approved', 'href' => 'complaints/index.php?status=sde_approved', 'label' => 'SDE approved'],
        ['n' => $by['hod_rejected'], 'class' => 'seg-rej', 'href' => 'complaints/index.php?status=hod_rejected', 'label' => 'Rejected by HOD'],
        ['n' => $by['sde_rejected'], 'class' => 'seg-rej-sde', 'href' => 'complaints/index.php?status=sde_rejected', 'label' => 'Rejected by SDE'],
    ];
    ?>
    <section class="card dashboard-snapshot">
        <header class="dashboard-snapshot-header">
            <div>
                <h2 class="dashboard-snapshot-title">Department overview</h2>
                <p class="muted dashboard-snapshot-meta"><strong><?= e((string) $user['department_name']) ?></strong> · <?= e((string) $user['organisation_name']) ?></p>
            </div>
            <div class="dashboard-snapshot-actions">
                <a class="btn btn-primary" href="<?= e(cmc_url('complaints/index.php')) ?>">All complaints</a>
                <?php if ($by['pending_hod'] > 0) : ?>
                    <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=pending_hod')) ?>">Needs your action (<?= (int) $by['pending_hod'] ?>)</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="dashboard-snapshot-body">
            <div class="dashboard-snapshot-total" aria-label="Total complaints in department">
                <span class="dashboard-snapshot-total-num"><?= $total ?></span>
                <span class="dashboard-snapshot-total-label"><?= $total === 1 ? 'complaint' : 'complaints' ?></span>
            </div>
            <div class="dashboard-snapshot-main">
                <h3 class="dashboard-snapshot-h">Status</h3>
                <p class="muted small dashboard-snapshot-lead">Click a row to filter the department list.</p>
                <div class="dashboard-stackbar" role="img" aria-label="Complaints by status">
                    <?php if ($total < 1) : ?>
                        <span class="dashboard-stackbar-empty">No complaints in this department yet.</span>
                    <?php else : ?>
                        <?php foreach ($statusSegs as $seg) : ?>
                            <?php if ($seg['n'] > 0) : ?>
                                <span class="dashboard-stackbar-seg <?= e($seg['class']) ?>" style="flex: <?= (int) $seg['n'] ?> 1 0; min-width: <?= $seg['n'] > 0 ? '8px' : '0' ?>;" title="<?= e($seg['label']) ?>: <?= (int) $seg['n'] ?>"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <ul class="dashboard-legend">
                    <?php foreach ($statusSegs as $seg) : ?>
                        <li>
                            <a class="dashboard-legend-link" href="<?= e(cmc_url($seg['href'])) ?>">
                                <span class="dashboard-legend-dot <?= e($seg['class']) ?>"></span>
                                <span class="dashboard-legend-label"><?= e($seg['label']) ?></span>
                                <span class="dashboard-legend-count"><?= (int) $seg['n'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="dashboard-legend-extra">
                        <span class="dashboard-legend-dot seg-fulfilled"></span>
                        <span class="dashboard-legend-label">Delivered (fulfillment completed)</span>
                        <span class="dashboard-legend-count"><?= $fulfilled ?></span>
                    </li>
                </ul>
                <?php if ($by['sde_approved'] > 0 && (int) $stats['approved_with_open_fulfillment'] > 0) : ?>
                    <p class="muted small dashboard-snapshot-note">
                        <?= (int) $stats['approved_with_open_fulfillment'] ?> approved <?= (int) $stats['approved_with_open_fulfillment'] === 1 ? 'case has' : 'cases have' ?> open fulfillment work.
                    </p>
                <?php endif; ?>

                <?php if ($by['sde_approved'] > 0) : ?>
                    <div class="dashboard-snapshot-divider"></div>
                    <h3 class="dashboard-snapshot-h">Fulfillment (SDE-approved)</h3>
                    <?php if ($sumFulMix < 1) : ?>
                        <p class="muted small">No fulfillment rows yet for approved cases.</p>
                    <?php else : ?>
                        <div class="dashboard-stackbar dashboard-stackbar-ful" role="img" aria-label="Fulfillment mix">
                            <?php
                            $ffClasses = [
                                'pending_setup' => 'segff-setup',
                                'planning' => 'segff-plan',
                                'in_progress' => 'segff-run',
                                'on_hold' => 'segff-hold',
                                'completed' => 'segff-done',
                                'cancelled' => 'segff-can',
                            ];
                            foreach ($fulMix as $bucket => $n) :
                                $cls = $ffClasses[$bucket] ?? 'segff-plan';
                                ?>
                                <span class="dashboard-stackbar-seg <?= e($cls) ?>" style="flex: <?= (int) $n ?> 1 0; min-width: <?= $n > 0 ? '8px' : '0' ?>;"></span>
                            <?php endforeach; ?>
                        </div>
                        <ul class="dashboard-legend dashboard-legend-compact">
                            <?php
                            $bucketLabels = [
                                'pending_setup' => 'Not started',
                                'planning' => cmc_fulfillment_work_status_label('planning'),
                                'in_progress' => cmc_fulfillment_work_status_label('in_progress'),
                                'on_hold' => cmc_fulfillment_work_status_label('on_hold'),
                                'completed' => cmc_fulfillment_work_status_label('completed'),
                                'cancelled' => cmc_fulfillment_work_status_label('cancelled'),
                            ];
                            foreach ($fulMix as $bucket => $n) :
                                ?>
                                <li>
                                    <span class="dashboard-legend-dot <?= e($ffClasses[$bucket] ?? 'segff-plan') ?>"></span>
                                    <span class="dashboard-legend-label"><?= e($bucketLabels[$bucket] ?? $bucket) ?></span>
                                    <span class="dashboard-legend-count"><?= (int) $n ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card dashboard-recent">
        <div class="dashboard-recent-head">
            <h3 class="card-title">Recently updated</h3>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">View all</a>
        </div>
        <?php if ($recent === []) : ?>
            <p class="muted">No complaints yet.</p>
        <?php else : ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Subject</th>
                            <th>Raised by</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="th-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r) : ?>
                            <tr>
                                <td><code class="dashboard-ref"><?= e((string) ($r['reference_code'] ?? '')) ?></code></td>
                                <td><?= e((string) ($r['subject'] ?? '')) ?></td>
                                <td class="small"><?= e((string) ($r['raised_by_name'] ?? '')) ?></td>
                                <td><span class="pill pill-soft"><?= e(cmc_complaint_status_label((string) ($r['status'] ?? ''))) ?></span></td>
                                <td class="muted small"><?= e((string) ($r['updated_at'] ?? '')) ?></td>
                                <td class="td-actions"><a class="btn btn-sm btn-ghost" href="<?= e(cmc_dashboard_complaint_href($r)) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($role === 'sde') {
    $sq = cmc_dashboard_sde_queue_stats($pdo);
    $bs = $sq['by_status'];
    $total = (int) $sq['total'];
    $recent = cmc_dashboard_recent_complaints(
        $pdo,
        "c.status IN ('pending_sde', 'sde_approved', 'sde_rejected')",
        [],
        8
    );
    $segs = [
        ['n' => $bs['pending_sde'], 'class' => 'seg-sde', 'href' => 'complaints/index.php?status=pending_sde', 'label' => 'Awaiting your review'],
        ['n' => $bs['sde_approved'], 'class' => 'seg-approved', 'href' => 'complaints/index.php?status=sde_approved', 'label' => 'Approved'],
        ['n' => $bs['sde_rejected'], 'class' => 'seg-rej-sde', 'href' => 'complaints/index.php?status=sde_rejected', 'label' => 'Rejected'],
    ];
    ?>
    <section class="card dashboard-snapshot">
        <header class="dashboard-snapshot-header">
            <div>
                <h2 class="dashboard-snapshot-title">Cell queue</h2>
                <p class="muted dashboard-snapshot-meta">Review, approve or reject — then fulfillment, resources, and billing.</p>
            </div>
            <div class="dashboard-snapshot-actions">
                <a class="btn btn-primary" href="<?= e(cmc_url('complaints/index.php')) ?>">Complaint queue</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('fulfillment/index.php')) ?>">Fulfillment</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('resources/index.php')) ?>">Resources</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('billing/index.php')) ?>">Billing</a>
            </div>
        </header>
        <div class="dashboard-snapshot-body dashboard-snapshot-body-sde">
            <div class="dashboard-snapshot-total">
                <span class="dashboard-snapshot-total-num"><?= $total ?></span>
                <span class="dashboard-snapshot-total-label">in cell scope</span>
            </div>
            <div class="dashboard-snapshot-main">
                <h3 class="dashboard-snapshot-h">Queue</h3>
                <div class="dashboard-stackbar" role="img" aria-label="Queue by status">
                    <?php if ($total < 1) : ?>
                        <span class="dashboard-stackbar-empty">No complaints in the cell queue.</span>
                    <?php else : ?>
                        <?php foreach ($segs as $seg) : ?>
                            <?php if ($seg['n'] > 0) : ?>
                                <span class="dashboard-stackbar-seg <?= e($seg['class']) ?>" style="flex: <?= (int) $seg['n'] ?> 1 0; min-width: <?= $seg['n'] > 0 ? '8px' : '0' ?>;"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <ul class="dashboard-legend">
                    <?php foreach ($segs as $seg) : ?>
                        <li>
                            <a class="dashboard-legend-link" href="<?= e(cmc_url($seg['href'])) ?>">
                                <span class="dashboard-legend-dot <?= e($seg['class']) ?>"></span>
                                <span class="dashboard-legend-label"><?= e($seg['label']) ?></span>
                                <span class="dashboard-legend-count"><?= (int) $seg['n'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <section class="card dashboard-recent">
        <div class="dashboard-recent-head">
            <h3 class="card-title">Recently updated</h3>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">View all</a>
        </div>
        <?php if ($recent === []) : ?>
            <p class="muted">No complaints in the cell queue.</p>
        <?php else : ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Subject</th>
                            <th>Dept</th>
                            <th>Status</th>
                            <th class="th-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r) : ?>
                            <tr>
                                <td><code class="dashboard-ref"><?= e((string) ($r['reference_code'] ?? '')) ?></code></td>
                                <td><?= e((string) ($r['subject'] ?? '')) ?></td>
                                <td class="small muted"><?= e((string) ($r['department_name'] ?? '')) ?></td>
                                <td><span class="pill pill-soft"><?= e(cmc_complaint_status_label((string) ($r['status'] ?? ''))) ?></span></td>
                                <td class="td-actions"><a class="btn btn-sm btn-ghost" href="<?= e(cmc_dashboard_complaint_href($r)) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
} elseif ($role === 'admin') {
    $adm = cmc_dashboard_admin_complaint_stats($pdo);
    $by = $adm['by_status'];
    $total = (int) $adm['total'];
    $recent = cmc_dashboard_recent_complaints($pdo, '1=1', [], 8);
    $statusSegs = [
        ['n' => $by['pending_hod'], 'class' => 'seg-hod', 'href' => 'complaints/index.php?status=pending_hod', 'label' => 'Awaiting HOD'],
        ['n' => $by['pending_sde'], 'class' => 'seg-sde', 'href' => 'complaints/index.php?status=pending_sde', 'label' => 'Awaiting SDE'],
        ['n' => $by['sde_approved'], 'class' => 'seg-approved', 'href' => 'complaints/index.php?status=sde_approved', 'label' => 'SDE approved'],
        ['n' => $by['hod_rejected'], 'class' => 'seg-rej', 'href' => 'complaints/index.php?status=hod_rejected', 'label' => 'Rejected by HOD'],
        ['n' => $by['sde_rejected'], 'class' => 'seg-rej-sde', 'href' => 'complaints/index.php?status=sde_rejected', 'label' => 'Rejected by SDE'],
    ];
    $pipeline = $by['pending_hod'] + $by['pending_sde'];
    ?>
    <section class="card dashboard-snapshot">
        <header class="dashboard-snapshot-header">
            <div>
                <h2 class="dashboard-snapshot-title">Administration</h2>
                <p class="muted dashboard-snapshot-meta">Organisations, departments, users, and system-wide complaints.</p>
            </div>
            <div class="dashboard-snapshot-actions">
                <a class="btn btn-primary" href="<?= e(cmc_url('admin/organisations.php')) ?>">Organisations</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('admin/departments.php')) ?>">Departments</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('admin/users.php')) ?>">Users</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">Complaints</a>
            </div>
        </header>
        <div class="dashboard-snapshot-body">
            <div class="dashboard-snapshot-total">
                <span class="dashboard-snapshot-total-num"><?= $total ?></span>
                <span class="dashboard-snapshot-total-label">complaints</span>
                <?php if ($pipeline > 0) : ?>
                    <span class="dashboard-snapshot-total-sub muted small"><?= $pipeline ?> in pipeline</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-snapshot-main">
                <h3 class="dashboard-snapshot-h">Status mix</h3>
                <div class="dashboard-stackbar" role="img" aria-label="Complaints by status">
                    <?php if ($total < 1) : ?>
                        <span class="dashboard-stackbar-empty">No complaints in the system yet.</span>
                    <?php else : ?>
                        <?php foreach ($statusSegs as $seg) : ?>
                            <?php if ($seg['n'] > 0) : ?>
                                <span class="dashboard-stackbar-seg <?= e($seg['class']) ?>" style="flex: <?= (int) $seg['n'] ?> 1 0; min-width: <?= $seg['n'] > 0 ? '8px' : '0' ?>;"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <ul class="dashboard-legend">
                    <?php foreach ($statusSegs as $seg) : ?>
                        <li>
                            <a class="dashboard-legend-link" href="<?= e(cmc_url($seg['href'])) ?>">
                                <span class="dashboard-legend-dot <?= e($seg['class']) ?>"></span>
                                <span class="dashboard-legend-label"><?= e($seg['label']) ?></span>
                                <span class="dashboard-legend-count"><?= (int) $seg['n'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <section class="card dashboard-recent">
        <div class="dashboard-recent-head">
            <h3 class="card-title">Recently updated</h3>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">View all</a>
        </div>
        <?php if ($recent === []) : ?>
            <p class="muted">No complaints yet.</p>
        <?php else : ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Subject</th>
                            <th>Dept</th>
                            <th>Raised by</th>
                            <th>Status</th>
                            <th class="th-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r) : ?>
                            <tr>
                                <td><code class="dashboard-ref"><?= e((string) ($r['reference_code'] ?? '')) ?></code></td>
                                <td><?= e((string) ($r['subject'] ?? '')) ?></td>
                                <td class="small muted"><?= e((string) ($r['department_name'] ?? '')) ?></td>
                                <td class="small"><?= e((string) ($r['raised_by_name'] ?? '')) ?></td>
                                <td><span class="pill pill-soft"><?= e(cmc_complaint_status_label((string) ($r['status'] ?? ''))) ?></span></td>
                                <td class="td-actions"><a class="btn btn-sm btn-ghost" href="<?= e(cmc_dashboard_complaint_href($r)) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
} else {
    ?>
    <div class="card">
        <p class="muted">No dashboard layout for this role.</p>
    </div>
    <?php
}

cmc_layout_end();
