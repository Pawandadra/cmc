<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = cmc_require_login();
$pdo = cmc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
    cmc_csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    $keep = [];
    foreach (['q', 'status', 'org_id', 'dept_id'] as $k) {
        if (!isset($_GET[$k]) || $_GET[$k] === '' || $_GET[$k] === null) {
            continue;
        }
        $keep[$k] = $_GET[$k];
    }
    $redirPath = 'complaints/index.php' . ($keep !== [] ? '?' . http_build_query($keep) : '');

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
        cmc_redirect($redirPath);
    }
    if ($action === 'bulk_delete') {
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
        cmc_redirect($redirPath);
    }
    cmc_flash_set('error', 'Unknown action.');
    cmc_redirect($redirPath);
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$orgFilter = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
$deptFilter = isset($_GET['dept_id']) ? (int) $_GET['dept_id'] : 0;

$statusChoices = match ($user['role']) {
    'sde' => cmc_complaint_statuses_for_sde_queue(),
    default => cmc_complaint_statuses_all(),
};
if ($statusFilter !== '' && !in_array($statusFilter, $statusChoices, true)) {
    $statusFilter = '';
}

if ($orgFilter > 0) {
    $chk = $pdo->prepare('SELECT 1 FROM organisations WHERE id = ?');
    $chk->execute([$orgFilter]);
    if (!$chk->fetch()) {
        $orgFilter = 0;
    }
}
if ($deptFilter > 0) {
    $chk = $pdo->prepare('SELECT 1 FROM departments WHERE id = ?');
    $chk->execute([$deptFilter]);
    if (!$chk->fetch()) {
        $deptFilter = 0;
    }
}
if ($deptFilter > 0 && $orgFilter > 0 && !cmc_department_in_organisation($pdo, $deptFilter, $orgFilter)) {
    $deptFilter = 0;
}

$heading = 'Complaints';
$where = [];
$params = [];

$select = 'SELECT c.id, c.reference_code, c.subject, c.status, c.created_at, c.updated_at,
       rb.full_name AS raised_by_name, rb.email AS raised_by_email,
       d.name AS department_name, o.name AS organisation_name';

$from = 'FROM complaints c
     JOIN users rb ON rb.id = c.raised_by_user_id
     JOIN departments d ON d.id = c.department_id
     JOIN organisations o ON o.id = c.organisation_id';

if ($user['role'] === 'member') {
    $heading = 'My complaints';
    $where[] = 'c.raised_by_user_id = ?';
    $params[] = (int) $user['id'];
} elseif ($user['role'] === 'hod') {
    $heading = 'Department complaints';
    $where[] = 'c.department_id = ?';
    $params[] = (int) $user['department_id'];
} elseif ($user['role'] === 'sde') {
    $heading = 'Complaints (cell queue)';
    if ($statusFilter !== '') {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
    } else {
        $where[] = "c.status IN ('pending_sde', 'sde_approved', 'sde_rejected')";
    }
} elseif ($user['role'] === 'admin') {
    // no department / raiser scope
} else {
    http_response_code(403);
    exit('Forbidden');
}

if (in_array($user['role'], ['member', 'hod', 'admin'], true) && $statusFilter !== '') {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}

if (in_array($user['role'], ['admin', 'sde'], true)) {
    if ($orgFilter > 0) {
        $where[] = 'c.organisation_id = ?';
        $params[] = $orgFilter;
    }
    if ($deptFilter > 0) {
        $where[] = 'c.department_id = ?';
        $params[] = $deptFilter;
    }
}

[$searchSql, $searchParams] = cmc_complaint_search_fragment($q);
$where[] = '(' . $searchSql . ')';
$params = array_merge($params, $searchParams);

$order = $user['role'] === 'sde'
    ? "ORDER BY CASE WHEN c.status = 'pending_sde' THEN 0 ELSE 1 END, c.updated_at DESC"
    : 'ORDER BY c.id DESC';

$sql = $select . ' ' . $from . ' WHERE ' . implode(' AND ', $where) . ' ' . $order . ' LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$filterOrgs = [];
$filterDepts = [];
if (in_array($user['role'], ['admin', 'sde'], true)) {
    $filterOrgs = $pdo->query('SELECT id, name FROM organisations ORDER BY name COLLATE NOCASE')->fetchAll();
    $filterDepts = $pdo->query(
        'SELECT id, name, organisation_id FROM departments ORDER BY organisation_id, name COLLATE NOCASE'
    )->fetchAll();
}

$listUrl = cmc_url('complaints/index.php');
$hasFilters = $q !== '' || $statusFilter !== '' || $orgFilter > 0 || $deptFilter > 0;

$listQueryParams = [];
if ($q !== '') {
    $listQueryParams['q'] = $q;
}
if ($statusFilter !== '') {
    $listQueryParams['status'] = $statusFilter;
}
if ($orgFilter > 0) {
    $listQueryParams['org_id'] = $orgFilter;
}
if ($deptFilter > 0) {
    $listQueryParams['dept_id'] = $deptFilter;
}
$listUrlWithQuery = $listUrl . ($listQueryParams !== [] ? '?' . http_build_query($listQueryParams) : '');

cmc_layout_start($heading, $user);
?>
<?php if (in_array($user['role'], ['member', 'hod'], true)) : ?>
    <div class="toolbar">
        <a class="btn btn-primary" href="<?= e(cmc_url('complaints/create.php')) ?>">Raise complaint</a>
    </div>
<?php endif; ?>

<div class="card card-form" style="margin-bottom: 1rem;">
    <form method="get" action="<?= e($listUrl) ?>" class="form-stack">
        <div class="form-row" style="flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <label class="field grow" style="min-width: 200px;">
                <span class="field-label">Search</span>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Complaint ID, subject, name, email" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">Status</span>
                <select class="input" name="status">
                    <option value="">All</option>
                    <?php foreach ($statusChoices as $stc) : ?>
                        <option value="<?= e($stc) ?>"<?= $statusFilter === $stc ? ' selected' : '' ?>><?= e(cmc_complaint_status_label($stc)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (in_array($user['role'], ['admin', 'sde'], true)) : ?>
                <label class="field">
                    <span class="field-label">Organisation</span>
                    <select class="input" name="org_id" id="complaint-filter-org">
                        <option value="">All</option>
                        <?php foreach ($filterOrgs as $o) : ?>
                            <option value="<?= (int) $o['id'] ?>"<?= $orgFilter === (int) $o['id'] ? ' selected' : '' ?>><?= e((string) $o['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">Department</span>
                    <select class="input" name="dept_id" id="complaint-filter-dept">
                        <option value="">All</option>
                        <?php foreach ($filterDepts as $d) : ?>
                            <option value="<?= (int) $d['id'] ?>"
                                data-org="<?= (int) $d['organisation_id'] ?>"
                                <?= $deptFilter === (int) $d['id'] ? ' selected' : '' ?>
                                <?= $orgFilter > 0 && (int) $d['organisation_id'] !== $orgFilter ? ' hidden' : '' ?>>
                                <?= e((string) $d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <div class="form-actions" style="margin-bottom: 0.15rem;">
                <button class="btn btn-primary" type="submit">Apply</button>
                <?php if ($hasFilters) : ?>
                    <a class="btn btn-ghost" href="<?= e($listUrl) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php if (in_array($user['role'], ['admin', 'sde'], true)) : ?>
    <script>
    (function () {
        var org = document.getElementById('complaint-filter-org');
        var dept = document.getElementById('complaint-filter-dept');
        if (!org || !dept) return;
        function sync() {
            var oid = org.value ? String(org.value) : '';
            var opts = dept.querySelectorAll('option[data-org]');
            var sel = dept.value;
            opts.forEach(function (o) {
                o.hidden = oid !== '' && o.getAttribute('data-org') !== oid;
            });
            if (dept.selectedOptions.length && dept.selectedOptions[0].hidden) {
                dept.value = '';
            }
        }
        org.addEventListener('change', sync);
        sync();
    })();
    </script>
<?php endif; ?>

<?php if ($user['role'] === 'admin') : ?>
    <p class="muted small" style="margin-bottom: 0.75rem;">As an administrator you can permanently delete complaints (including timeline, attachments, fulfillment, and linked bills). This cannot be undone.</p>
    <form method="post" action="<?= e($listUrlWithQuery) ?>" id="bulk-delete-form" class="card card-form" style="margin-bottom: 1rem;" data-confirm="Delete all selected complaints? This cannot be undone.">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="action" value="bulk_delete">
        <div class="form-row" style="align-items: center; gap: 0.75rem;">
            <button class="btn btn-danger" type="submit">Delete selected</button>
            <span class="muted small">Select rows below, then confirm.</span>
        </div>
    </form>
<?php endif; ?>

<div class="table-wrap card">
    <table class="table">
        <thead>
            <tr>
                <?php if ($user['role'] === 'admin') : ?>
                    <th style="width: 2.5rem;">
                        <input type="checkbox" id="complaint-select-all" title="Select all" aria-label="Select all">
                    </th>
                <?php endif; ?>
                <th>Complaint ID</th>
                <th>Subject</th>
                <th>Raised by</th>
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
                <tr><td colspan="99" class="muted">No complaints match your filters.</td></tr>
            <?php else : ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <?php if ($user['role'] === 'admin') : ?>
                            <td>
                                <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulk-delete-form" aria-label="Select complaint">
                            </td>
                        <?php endif; ?>
                        <td class="muted"><code><?= e((string) ($r['reference_code'] ?? '')) ?></code></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td>
                            <?= e((string) ($r['raised_by_name'] ?? '')) ?>
                            <?php if (!empty($r['raised_by_email'])) : ?>
                                <span class="muted small"><br><?= e((string) $r['raised_by_email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($user['role'] === 'sde' || $user['role'] === 'admin') : ?>
                            <td><?= e((string) $r['organisation_name']) ?></td>
                            <td><?= e((string) $r['department_name']) ?></td>
                        <?php endif; ?>
                        <td><?= e(cmc_complaint_status_label((string) $r['status'])) ?></td>
                        <td class="muted"><?= e((string) $r['updated_at']) ?></td>
                        <td class="td-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(cmc_url('complaints/view.php?' . (trim((string) ($r['reference_code'] ?? '')) !== '' ? 'ref=' . rawurlencode((string) $r['reference_code']) : 'id=' . (int) $r['id']))) ?>">Open</a>
                            <?php if ($user['role'] === 'admin') : ?>
                                <form method="post" action="<?= e($listUrlWithQuery) ?>" class="inline-form" data-confirm="Permanently delete this complaint and all related records?">
                                    <?= cmc_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($user['role'] === 'admin') : ?>
    <script>
    (function () {
        var all = document.getElementById('complaint-select-all');
        if (!all) return;
        all.addEventListener('change', function () {
            document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
                cb.checked = all.checked;
            });
        });
    })();
    </script>
<?php endif; ?>
<?php
cmc_layout_end();
