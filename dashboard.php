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
    $rejected = $by['hod_rejected'] + $by['sde_rejected'];
    $pendingPipe = $by['pending_hod'] + $by['pending_sde'];
    $statusChart = [
        'Awaiting HOD' => $by['pending_hod'],
        'Awaiting SDE' => $by['pending_sde'],
        'SDE approved' => $by['sde_approved'],
        'Rejected' => $rejected,
    ];
    ?>
    <div class="dashboard-welcome card">
        <h2 class="card-title">Welcome, <?= e((string) $user['full_name']) ?></h2>
        <p class="muted dashboard-welcome-meta">
            <?= e((string) $user['organisation_name']) ?> · <?= e((string) $user['department_name']) ?>
        </p>
        <div class="dashboard-shortcuts">
            <a class="btn btn-primary" href="<?= e(cmc_url('complaints/create.php')) ?>">Raise a complaint</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">All my complaints</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=pending_hod')) ?>">Awaiting HOD</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=pending_sde')) ?>">Awaiting SDE</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=sde_approved')) ?>">Approved</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=hod_rejected')) ?>">Rejected (HOD)</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=sde_rejected')) ?>">Rejected (SDE)</a>
        </div>
    </div>

    <div class="dashboard-stat-grid">
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= (int) $stats['total'] ?></div>
            <div class="dashboard-stat-label">Total complaints</div>
        </div>
        <div class="dashboard-stat dashboard-stat-accent">
            <div class="dashboard-stat-value"><?= $pendingPipe ?></div>
            <div class="dashboard-stat-label">In progress</div>
            <div class="dashboard-stat-hint">Awaiting HOD or SDE</div>
        </div>
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= $by['pending_hod'] ?></div>
            <div class="dashboard-stat-label">Awaiting HOD</div>
        </div>
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= $by['pending_sde'] ?></div>
            <div class="dashboard-stat-label">Awaiting SDE</div>
        </div>
        <div class="dashboard-stat dashboard-stat-success">
            <div class="dashboard-stat-value"><?= $by['sde_approved'] ?></div>
            <div class="dashboard-stat-label">SDE approved</div>
            <?php if ($stats['approved_with_open_fulfillment'] > 0) : ?>
                <div class="dashboard-stat-hint"><?= (int) $stats['approved_with_open_fulfillment'] ?> with open fulfillment</div>
            <?php endif; ?>
        </div>
        <div class="dashboard-stat dashboard-stat-teal">
            <div class="dashboard-stat-value"><?= (int) $stats['fulfilled_completed'] ?></div>
            <div class="dashboard-stat-label">Fulfilled</div>
            <div class="dashboard-stat-hint">Fulfillment completed</div>
        </div>
        <div class="dashboard-stat dashboard-stat-muted">
            <div class="dashboard-stat-value"><?= $rejected ?></div>
            <div class="dashboard-stat-label">Rejected</div>
            <div class="dashboard-stat-hint">HOD or SDE</div>
        </div>
    </div>

    <div class="grid-2 dashboard-charts-grid">
        <section class="card">
            <h3 class="card-title">Complaints by stage</h3>
            <?php
            $chartMax = max($statusChart) ?: 1;
            foreach ($statusChart as $label => $n) :
                $pct = $stats['total'] > 0 ? round(100 * $n / $stats['total']) : 0;
                $w = $chartMax > 0 ? round(100 * $n / $chartMax) : 0;
                ?>
                <div class="dashboard-bar-row">
                    <span class="dashboard-bar-label"><?= e($label) ?></span>
                    <div class="dashboard-bar-track" title="<?= (int) $n ?> (<?= (int) $pct ?>%)">
                        <div class="dashboard-bar-fill" style="width: <?= (int) $w ?>%;"></div>
                    </div>
                    <span class="dashboard-bar-count"><?= (int) $n ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($stats['total'] === 0) : ?>
                <p class="muted small">You have not raised any complaints yet. Use <strong>Raise a complaint</strong> to get started.</p>
            <?php endif; ?>
        </section>
        <section class="card">
            <h3 class="card-title">Fulfillment (approved cases)</h3>
            <?php if ($by['sde_approved'] < 1) : ?>
                <p class="muted small">Fulfillment tracking appears once the SDE has approved a complaint.</p>
            <?php elseif ($fulMix === []) : ?>
                <p class="muted small">No fulfillment record yet — the cell may still be setting up the case.</p>
            <?php else :
                $fmMax = max($fulMix) ?: 1;
                $bucketLabels = [
                    'pending_setup' => 'Not started',
                    'planning' => cmc_fulfillment_work_status_label('planning'),
                    'in_progress' => cmc_fulfillment_work_status_label('in_progress'),
                    'on_hold' => cmc_fulfillment_work_status_label('on_hold'),
                    'completed' => cmc_fulfillment_work_status_label('completed'),
                    'cancelled' => cmc_fulfillment_work_status_label('cancelled'),
                ];
                foreach ($fulMix as $bucket => $n) :
                    $bl = $bucketLabels[$bucket] ?? $bucket;
                    $w = $fmMax > 0 ? round(100 * $n / $fmMax) : 0;
                    ?>
                    <div class="dashboard-bar-row">
                        <span class="dashboard-bar-label"><?= e($bl) ?></span>
                        <div class="dashboard-bar-track dashboard-bar-track-teal">
                            <div class="dashboard-bar-fill dashboard-bar-fill-teal" style="width: <?= (int) $w ?>%;"></div>
                        </div>
                        <span class="dashboard-bar-count"><?= (int) $n ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

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
    $rejected = $by['hod_rejected'] + $by['sde_rejected'];
    $pendingPipe = $by['pending_hod'] + $by['pending_sde'];
    $statusChart = [
        'Awaiting HOD' => $by['pending_hod'],
        'Awaiting SDE' => $by['pending_sde'],
        'SDE approved' => $by['sde_approved'],
        'Rejected' => $rejected,
    ];
    ?>
    <div class="dashboard-welcome card">
        <h2 class="card-title">Department overview</h2>
        <p class="muted dashboard-welcome-meta">
            <strong><?= e((string) $user['department_name']) ?></strong> · <?= e((string) $user['organisation_name']) ?>
        </p>
        <div class="dashboard-shortcuts">
            <a class="btn btn-primary" href="<?= e(cmc_url('complaints/index.php')) ?>">Department complaints</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=pending_hod')) ?>">Needs your action</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=pending_sde')) ?>">With SDE</a>
            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/index.php?status=sde_approved')) ?>">Approved</a>
        </div>
    </div>

    <div class="dashboard-stat-grid">
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= (int) $stats['total'] ?></div>
            <div class="dashboard-stat-label">Total in department</div>
        </div>
        <div class="dashboard-stat dashboard-stat-accent">
            <div class="dashboard-stat-value"><?= $pendingPipe ?></div>
            <div class="dashboard-stat-label">In progress</div>
            <div class="dashboard-stat-hint">HOD or SDE queue</div>
        </div>
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= $by['pending_hod'] ?></div>
            <div class="dashboard-stat-label">Awaiting HOD</div>
        </div>
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= $by['pending_sde'] ?></div>
            <div class="dashboard-stat-label">Awaiting SDE</div>
        </div>
        <div class="dashboard-stat dashboard-stat-success">
            <div class="dashboard-stat-value"><?= $by['sde_approved'] ?></div>
            <div class="dashboard-stat-label">SDE approved</div>
            <?php if ($stats['approved_with_open_fulfillment'] > 0) : ?>
                <div class="dashboard-stat-hint"><?= (int) $stats['approved_with_open_fulfillment'] ?> with open fulfillment</div>
            <?php endif; ?>
        </div>
        <div class="dashboard-stat dashboard-stat-teal">
            <div class="dashboard-stat-value"><?= (int) $stats['fulfilled_completed'] ?></div>
            <div class="dashboard-stat-label">Fulfilled</div>
        </div>
        <div class="dashboard-stat dashboard-stat-muted">
            <div class="dashboard-stat-value"><?= $rejected ?></div>
            <div class="dashboard-stat-label">Rejected</div>
        </div>
    </div>

    <div class="grid-2 dashboard-charts-grid">
        <section class="card">
            <h3 class="card-title">Complaints by stage</h3>
            <?php
            $chartMax = max($statusChart) ?: 1;
            foreach ($statusChart as $label => $n) :
                $pct = $stats['total'] > 0 ? round(100 * $n / $stats['total']) : 0;
                $w = $chartMax > 0 ? round(100 * $n / $chartMax) : 0;
                ?>
                <div class="dashboard-bar-row">
                    <span class="dashboard-bar-label"><?= e($label) ?></span>
                    <div class="dashboard-bar-track" title="<?= (int) $n ?> (<?= (int) $pct ?>%)">
                        <div class="dashboard-bar-fill" style="width: <?= (int) $w ?>%;"></div>
                    </div>
                    <span class="dashboard-bar-count"><?= (int) $n ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($stats['total'] === 0) : ?>
                <p class="muted small">No complaints in this department yet.</p>
            <?php endif; ?>
        </section>
        <section class="card">
            <h3 class="card-title">Fulfillment (approved)</h3>
            <?php if ($by['sde_approved'] < 1) : ?>
                <p class="muted small">Nothing SDE-approved yet.</p>
            <?php elseif ($fulMix === []) : ?>
                <p class="muted small">No fulfillment rows yet for approved cases.</p>
            <?php else :
                $fmMax = max($fulMix) ?: 1;
                $bucketLabels = [
                    'pending_setup' => 'Not started',
                    'planning' => cmc_fulfillment_work_status_label('planning'),
                    'in_progress' => cmc_fulfillment_work_status_label('in_progress'),
                    'on_hold' => cmc_fulfillment_work_status_label('on_hold'),
                    'completed' => cmc_fulfillment_work_status_label('completed'),
                    'cancelled' => cmc_fulfillment_work_status_label('cancelled'),
                ];
                foreach ($fulMix as $bucket => $n) :
                    $bl = $bucketLabels[$bucket] ?? $bucket;
                    $w = $fmMax > 0 ? round(100 * $n / $fmMax) : 0;
                    ?>
                    <div class="dashboard-bar-row">
                        <span class="dashboard-bar-label"><?= e($bl) ?></span>
                        <div class="dashboard-bar-track dashboard-bar-track-teal">
                            <div class="dashboard-bar-fill dashboard-bar-fill-teal" style="width: <?= (int) $w ?>%;"></div>
                        </div>
                        <span class="dashboard-bar-count"><?= (int) $n ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

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
    $recent = cmc_dashboard_recent_complaints(
        $pdo,
        "c.status IN ('pending_sde', 'sde_approved', 'sde_rejected')",
        [],
        8
    );
    $chart = ['Awaiting SDE' => $bs['pending_sde'], 'SDE approved' => $bs['sde_approved'], 'Rejected by SDE' => $bs['sde_rejected']];
    ?>
    <div class="dashboard-welcome card">
        <h2 class="card-title">Cell queue</h2>
        <p class="muted">Review forwarded complaints, approve or reject, then use fulfillment and resources as needed.</p>
        <div class="dashboard-shortcuts">
            <a class="btn btn-primary" href="<?= e(cmc_url('complaints/index.php')) ?>">Complaint queue</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('fulfillment/index.php')) ?>">Fulfillment work</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('resources/index.php')) ?>">Resources</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('billing/index.php')) ?>">Billing</a>
        </div>
    </div>
    <div class="dashboard-stat-grid dashboard-stat-grid-4">
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= (int) $sq['total'] ?></div>
            <div class="dashboard-stat-label">In cell scope</div>
        </div>
        <div class="dashboard-stat dashboard-stat-accent">
            <div class="dashboard-stat-value"><?= $bs['pending_sde'] ?></div>
            <div class="dashboard-stat-label">Awaiting your review</div>
        </div>
        <div class="dashboard-stat dashboard-stat-success">
            <div class="dashboard-stat-value"><?= $bs['sde_approved'] ?></div>
            <div class="dashboard-stat-label">Approved</div>
        </div>
        <div class="dashboard-stat dashboard-stat-muted">
            <div class="dashboard-stat-value"><?= $bs['sde_rejected'] ?></div>
            <div class="dashboard-stat-label">Rejected</div>
        </div>
    </div>
    <div class="grid-2">
        <section class="card">
            <h3 class="card-title">Queue mix</h3>
            <?php
            $cm = max($chart) ?: 1;
            foreach ($chart as $label => $n) :
                $w = $cm > 0 ? round(100 * $n / $cm) : 0;
                ?>
                <div class="dashboard-bar-row">
                    <span class="dashboard-bar-label"><?= e($label) ?></span>
                    <div class="dashboard-bar-track">
                        <div class="dashboard-bar-fill" style="width: <?= (int) $w ?>%;"></div>
                    </div>
                    <span class="dashboard-bar-count"><?= (int) $n ?></span>
                </div>
            <?php endforeach; ?>
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
    </div>
    <?php
} elseif ($role === 'admin') {
    $adm = cmc_dashboard_admin_complaint_stats($pdo);
    $by = $adm['by_status'];
    $recent = cmc_dashboard_recent_complaints($pdo, '1=1', [], 8);
    $chart = [
        'Awaiting HOD' => $by['pending_hod'],
        'Awaiting SDE' => $by['pending_sde'],
        'SDE approved' => $by['sde_approved'],
        'Rejected' => $by['hod_rejected'] + $by['sde_rejected'],
    ];
    ?>
    <div class="dashboard-welcome card">
        <h2 class="card-title">Administration</h2>
        <p class="muted">Manage organisations, departments, and users. Oversee complaints from the list.</p>
        <div class="dashboard-shortcuts">
            <a class="btn btn-primary" href="<?= e(cmc_url('admin/organisations.php')) ?>">Organisations</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('admin/departments.php')) ?>">Departments</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('admin/users.php')) ?>">Users</a>
            <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">All complaints</a>
        </div>
    </div>
    <div class="dashboard-stat-grid dashboard-stat-grid-4">
        <div class="dashboard-stat">
            <div class="dashboard-stat-value"><?= (int) $adm['total'] ?></div>
            <div class="dashboard-stat-label">Total complaints</div>
        </div>
        <div class="dashboard-stat dashboard-stat-accent">
            <div class="dashboard-stat-value"><?= $by['pending_hod'] + $by['pending_sde'] ?></div>
            <div class="dashboard-stat-label">Open pipeline</div>
        </div>
        <div class="dashboard-stat dashboard-stat-success">
            <div class="dashboard-stat-value"><?= $by['sde_approved'] ?></div>
            <div class="dashboard-stat-label">SDE approved</div>
        </div>
        <div class="dashboard-stat dashboard-stat-muted">
            <div class="dashboard-stat-value"><?= $by['hod_rejected'] + $by['sde_rejected'] ?></div>
            <div class="dashboard-stat-label">Rejected</div>
        </div>
    </div>
    <div class="grid-2">
        <section class="card">
            <h3 class="card-title">System-wide status mix</h3>
            <?php
            $cm = max($chart) ?: 1;
            foreach ($chart as $label => $n) :
                $w = $cm > 0 ? round(100 * $n / $cm) : 0;
                ?>
                <div class="dashboard-bar-row">
                    <span class="dashboard-bar-label"><?= e($label) ?></span>
                    <div class="dashboard-bar-track">
                        <div class="dashboard-bar-fill" style="width: <?= (int) $w ?>%;"></div>
                    </div>
                    <span class="dashboard-bar-count"><?= (int) $n ?></span>
                </div>
            <?php endforeach; ?>
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
    </div>
    <?php
} else {
    ?>
    <div class="card">
        <p class="muted">No dashboard layout for this role.</p>
    </div>
    <?php
}

cmc_layout_end();
