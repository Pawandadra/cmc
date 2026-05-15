<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcFulfillmentUser;
$pdo = cmc_db();

$cid = isset($_GET['complaint_id']) ? (int) $_GET['complaint_id'] : 0;
if ($cid < 1) {
    http_response_code(404);
    exit('Not found');
}

$c = cmc_complaint_fetch($pdo, $cid);
if ($c === null || !cmc_complaint_user_can_view($user, $c)) {
    http_response_code(403);
    exit('Forbidden');
}
$cViewQs = trim((string) ($c['reference_code'] ?? '')) !== ''
    ? 'ref=' . rawurlencode((string) $c['reference_code'])
    : 'id=' . $cid;
if (($c['status'] ?? '') !== 'sde_approved') {
    cmc_flash_set('error', 'Fulfillment is only available for complaints approved by SDE.');
    cmc_redirect('complaints/view.php?' . $cViewQs);
}

$materialItems = $pdo->query(
    "SELECT id, name, item_code, unit, unit_rate, quantity
     FROM inventory_items
     WHERE resource_kind IS NULL OR resource_kind = '' OR resource_kind = 'material'
     ORDER BY name COLLATE NOCASE"
)->fetchAll();

$poolResources = $pdo->query(
    "SELECT id, name, item_code, unit, unit_rate, quantity, resource_kind
     FROM inventory_items
     WHERE resource_kind IN ('worker', 'equipment')
     ORDER BY resource_kind, name COLLATE NOCASE"
)->fetchAll();

$fulfillment = cmc_fulfillment_by_complaint($pdo, $cid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $cmcAction = (string) ($_POST['cmc_action'] ?? 'save_plan');

    if ($cmcAction === 'assign_pool') {
        $rid = isset($_POST['pool_resource_id']) ? (int) $_POST['pool_resource_id'] : 0;
        $qtyRaw = trim((string) ($_POST['assign_qty'] ?? ''));
        if ($rid < 1 || !is_numeric($qtyRaw)) {
            cmc_flash_set('error', 'Select a resource and quantity to assign.');
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        $qty = (float) $qtyRaw;
        if ($qty <= 0 || !is_finite($qty)) {
            cmc_flash_set('error', 'Assign quantity must be a positive number.');
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        $chk = $pdo->prepare('SELECT id FROM inventory_items WHERE id = ? AND resource_kind IN (\'worker\', \'equipment\')');
        $chk->execute([$rid]);
        if (!$chk->fetch()) {
            cmc_flash_set('error', 'That resource cannot be assigned here.');
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        if (!$poolResources) {
            cmc_flash_set('error', 'Add worker or equipment resources first.');
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        $fid = cmc_fulfillment_get_or_create($pdo, $cid, (int) $user['id']);
        $err = cmc_resource_assign_pool($pdo, $fid, $rid, $qty, (int) $user['id']);
        if ($err !== null) {
            cmc_flash_set('error', $err);
        } else {
            cmc_flash_set('success', 'Assignment saved.');
        }
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }

    if ($cmcAction === 'set_status') {
        $workStatus = (string) ($_POST['work_status'] ?? '');
        $err = cmc_fulfillment_set_work_status($pdo, $cid, (int) $user['id'], $workStatus);
        if ($err !== null) {
            cmc_flash_set('error', $err);
        } else {
            cmc_flash_set('success', 'Work status updated to ' . cmc_fulfillment_work_status_label($workStatus) . '.');
        }
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }

    if ($cmcAction === 'release_pool' || $cmcAction === 'release_worker') {
        $aid = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
        if ($aid < 1) {
            cmc_flash_set('error', 'Invalid assignment.');
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        $own = $pdo->prepare(
            'SELECT 1 FROM resource_assignments a
             JOIN complaint_fulfillments f ON f.id = a.fulfillment_id
             WHERE a.id = ? AND f.complaint_id = ?'
        );
        $own->execute([$aid, $cid]);
        if (!$own->fetch()) {
            cmc_flash_set('error', 'That assignment does not belong to this complaint.');
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        $err = cmc_resource_release_assignment($pdo, $aid);
        if ($err !== null) {
            cmc_flash_set('error', $err);
        } else {
            cmc_flash_set('success', 'Assignment released.');
        }
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }

    // save_plan
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $workStatus = (string) ($_POST['work_status'] ?? 'planning');
    $itemIds = $_POST['line_item'] ?? [];
    $qtys = $_POST['line_qty'] ?? [];

    if (!in_array($workStatus, cmc_fulfillment_work_statuses(), true)) {
        cmc_flash_set('error', 'Invalid work status.');
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }
    if (strlen($notes) > 10000) {
        cmc_flash_set('error', 'Notes are too long (max 10,000 characters).');
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }

    [$parseErr, $pairs] = cmc_fulfillment_parse_lines_from_post(
        is_array($itemIds) ? $itemIds : [],
        is_array($qtys) ? $qtys : []
    );
    if ($parseErr !== null) {
        cmc_flash_set('error', $parseErr);
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }
    if ($pairs !== [] && !$materialItems) {
        cmc_flash_set('error', 'Add material resources before entering line quantities.');
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }

    $pdo->beginTransaction();
    try {
        if ($fulfillment) {
            $fid = (int) $fulfillment['id'];
            $pdo->prepare(
                'UPDATE complaint_fulfillments SET notes = ?, work_status = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([$notes === '' ? null : $notes, $workStatus, $fid]);
        } else {
            $pdo->prepare(
                'INSERT INTO complaint_fulfillments (complaint_id, sde_user_id, notes, work_status) VALUES (?, ?, ?, ?)'
            )->execute([$cid, (int) $user['id'], $notes === '' ? null : $notes, $workStatus]);
            $fid = (int) $pdo->lastInsertId();
        }

        $lineErr = cmc_fulfillment_replace_lines($pdo, $fid, $pairs);
        if ($lineErr !== null) {
            $pdo->rollBack();
            cmc_flash_set('error', $lineErr);
            cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cmc_flash_set('error', 'Could not save fulfillment.');
        cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
    }

    cmc_flash_set('success', 'Fulfillment plan saved.');
    cmc_redirect('fulfillment/work.php?complaint_id=' . $cid);
}

$fulfillment = cmc_fulfillment_by_complaint($pdo, $cid);
$existingLines = [];
$assignments = [];
if ($fulfillment) {
    $existingLines = cmc_fulfillment_lines($pdo, (int) $fulfillment['id']);
    $assignments = cmc_resource_assignments_for_fulfillment($pdo, (int) $fulfillment['id']);
}

$linesJson = [];
foreach ($existingLines as $ln) {
    $linesJson[] = [
        'inventory_item_id' => (int) $ln['inventory_item_id'],
        'quantity' => (float) $ln['quantity'],
    ];
}
$invJson = [];
foreach ($materialItems as $it) {
    $label = (string) $it['name'];
    if (!empty($it['item_code'])) {
        $label .= ' · ' . (string) $it['item_code'];
    }
    $label .= ' (' . (string) $it['unit'] . ') · ₹' . cmc_resource_format_money((float) ($it['unit_rate'] ?? 0)) . '/u';
    $invJson[] = [
        'id' => (int) $it['id'],
        'label' => $label,
    ];
}
$fulfillmentDataJson = json_encode(
    ['materials' => $invJson, 'inventory' => $invJson, 'lines' => $linesJson],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP
);
if ($fulfillmentDataJson === false) {
    $fulfillmentDataJson = '{"materials":[],"inventory":[],"lines":[]}';
}

$fidForBilling = $fulfillment ? (int) $fulfillment['id'] : 0;
$currentWorkStatus = (string) ($fulfillment['work_status'] ?? 'planning');
$workStatusTerminal = in_array($currentWorkStatus, ['completed', 'cancelled'], true);

$refLabel = trim((string) ($c['reference_code'] ?? '')) !== '' ? (string) $c['reference_code'] : (string) $cid;

cmc_layout_start('Fulfillment · ' . $refLabel, $user);
$fulfillmentJs = cmc_url('assets/js/fulfillment-lines.js');
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('fulfillment/index.php')) ?>">← Fulfillment list</a>
    <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/view.php?' . $cViewQs)) ?>">Complaint <code><?= e($refLabel) ?></code></a>
    <?php if ($fidForBilling > 0) : ?>
        <a class="btn btn-ghost" href="<?= e(cmc_url('billing/create.php?fulfillment_id=' . $fidForBilling)) ?>">Create bill</a>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 1rem;">
    <h2 class="card-title"><?= e((string) $c['subject']) ?></h2>
    <dl class="dl-grid">
        <dt>Complaint ID</dt>
        <dd class="muted"><code><?= e((string) ($c['reference_code'] ?? '')) ?></code></dd>
        <dt>Raised by</dt>
        <dd><?= e((string) $c['raised_by_name']) ?> <span class="muted">(<?= e((string) $c['raised_by_email']) ?>)</span></dd>
        <dt>Organisation / department</dt>
        <dd><?= e((string) $c['organisation_name']) ?> · <?= e((string) $c['department_name']) ?></dd>
        <dt>Site location</dt>
        <dd><?= nl2br(e((string) $c['location'])) ?></dd>
    </dl>
</div>

<div class="card fulfillment-work-status-card">
    <div class="fulfillment-work-status-head">
        <div>
            <h3 class="subsection-title" style="margin:0">Work status</h3>
            <p class="muted small" style="margin:0.35rem 0 0">Current: <span class="pill pill-soft"><?= e(cmc_fulfillment_work_status_label($currentWorkStatus)) ?></span></p>
        </div>
        <?php if ($workStatusTerminal) : ?>
            <p class="muted small" style="margin:0">This job is closed. Reopen it to continue planning or assignments.</p>
        <?php endif; ?>
    </div>
    <div class="fulfillment-work-status-actions">
        <?php if ($currentWorkStatus !== 'in_progress') : ?>
            <form method="post" class="inline-form">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="in_progress">
                <button class="btn btn-primary" type="submit">Start work</button>
            </form>
        <?php endif; ?>
        <?php if ($currentWorkStatus !== 'completed') : ?>
            <form method="post" class="inline-form">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="completed">
                <button class="btn btn-primary" type="submit">Mark complete</button>
            </form>
        <?php endif; ?>
        <?php if ($currentWorkStatus !== 'on_hold' && !$workStatusTerminal) : ?>
            <form method="post" class="inline-form">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="on_hold">
                <button class="btn btn-ghost" type="submit">Put on hold</button>
            </form>
        <?php endif; ?>
        <?php if ($currentWorkStatus !== 'planning' && !$workStatusTerminal) : ?>
            <form method="post" class="inline-form">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="planning">
                <button class="btn btn-ghost" type="submit">Back to planning</button>
            </form>
        <?php endif; ?>
        <?php if ($workStatusTerminal && $currentWorkStatus === 'completed') : ?>
            <form method="post" class="inline-form">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="in_progress">
                <button class="btn btn-ghost" type="submit">Reopen (in progress)</button>
            </form>
        <?php elseif ($workStatusTerminal && $currentWorkStatus === 'cancelled') : ?>
            <form method="post" class="inline-form">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="planning">
                <button class="btn btn-ghost" type="submit">Reopen (planning)</button>
            </form>
        <?php elseif ($currentWorkStatus !== 'cancelled') : ?>
            <form method="post" class="inline-form" data-confirm="Cancel this fulfillment work?">
                <?= cmc_csrf_field() ?>
                <input type="hidden" name="cmc_action" value="set_status">
                <input type="hidden" name="work_status" value="cancelled">
                <button class="btn btn-ghost" type="submit">Cancel work</button>
            </form>
        <?php endif; ?>
    </div>
    <p class="muted small" style="margin:0.75rem 0 0">You can also change status when saving the materials plan below.</p>
</div>

<div class="card card-form" id="fulfillment-lines-root">
    <h3 class="subheading" style="margin-top:0">Materials</h3>
    <p class="muted small">Usage lines and rates are defined under <a href="<?= e(cmc_url('resources/index.php')) ?>">Resources</a>.</p>

    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <input type="hidden" name="cmc_action" value="save_plan">

        <?php if (!$materialItems) : ?>
            <div class="flash flash-error">No material resources yet. Add them under Resources.</div>
        <?php else : ?>
            <script type="application/json" id="fulfillment-lines-data"><?= $fulfillmentDataJson ?></script>

            <template id="line-row-template">
                <div class="form-row fulfillment-line-row" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; margin-bottom:0.5rem;">
                    <label class="field grow" style="min-width:220px;">
                        <span class="field-label">Item</span>
                        <select class="input" name="line_item[]">
                            <option value="">Select item…</option>
                            <?php foreach ($materialItems as $it) : ?>
                                <?php
                                $lbl = (string) $it['name'];
                                if (!empty($it['item_code'])) {
                                    $lbl .= ' · ' . (string) $it['item_code'];
                                }
                                $lbl .= ' (' . (string) $it['unit'] . ') · ₹' . cmc_resource_format_money((float) ($it['unit_rate'] ?? 0)) . '/u';
                                ?>
                                <option value="<?= (int) $it['id'] ?>"><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field" style="min-width:120px;">
                        <span class="field-label">Quantity</span>
                        <input class="input" type="number" name="line_qty[]" step="any" min="0" placeholder="Qty">
                    </label>
                    <button type="button" class="btn btn-ghost btn-sm remove-fulfillment-line" style="margin-bottom:0.15rem;">Remove</button>
                </div>
            </template>

            <div id="line-rows-container" class="fulfillment-lines-block"></div>
            <p><button type="button" class="btn btn-ghost" id="add-fulfillment-line">+ Add line</button></p>
            <script src="<?= e($fulfillmentJs) ?>" defer></script>
        <?php endif; ?>

        <label class="field">
            <span class="field-label">Notes</span>
            <textarea class="input textarea" name="notes" rows="4" maxlength="10000" placeholder="Site constraints, vehicle numbers, follow-up…"><?= e((string) ($fulfillment['notes'] ?? '')) ?></textarea>
        </label>
        <label class="field">
            <span class="field-label">Work status</span>
            <select class="input" name="work_status">
                <?php foreach (cmc_fulfillment_work_statuses() as $st) : ?>
                    <option value="<?= e($st) ?>"<?= (($fulfillment['work_status'] ?? 'planning') === $st) ? ' selected' : '' ?>><?= e(cmc_fulfillment_work_status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $fulfillment ? 'Save plan' : 'Create fulfillment plan' ?></button>
        </div>
    </form>
</div>

<div class="card card-form">
    <h3 class="subheading" style="margin-top:0">Workers and equipment</h3>
    <p class="muted small">Workers are assigned by headcount for billing (no stock deduction). Equipment uses available pool quantity from Resources.</p>

    <?php if (!$poolResources) : ?>
        <p class="muted">No worker or equipment resources yet. Add them under Resources.</p>
    <?php else : ?>
        <form method="post" class="form-stack" style="margin-bottom:1rem;">
            <?= cmc_csrf_field() ?>
            <input type="hidden" name="cmc_action" value="assign_pool">
            <div class="form-row">
                <label class="field grow">
                    <span class="field-label">Pool</span>
                    <select class="input" name="pool_resource_id" required>
                        <option value="">Select…</option>
                        <?php
                        $lastKind = '';
                        foreach ($poolResources as $w) :
                            $wk = (string) ($w['resource_kind'] ?? '');
                            if ($wk !== $lastKind) :
                                if ($lastKind !== '') {
                                    echo '</optgroup>';
                                }
                                $lastKind = $wk;
                                $og = $wk === 'equipment' ? 'Equipment' : 'Workers';
                                echo '<optgroup label="' . e($og) . '">';
                            endif;
                            $assignable = cmc_resource_pool_assignable($pdo, $w);
                            if ($wk === 'worker') {
                                $onHand = (float) ($w['quantity'] ?? 0);
                                if ($onHand <= 1e-9) {
                                    $availLabel = 'headcount (no pool cap)';
                                } else {
                                    $availLabel = cmc_resource_format_qty($assignable) . ' of '
                                        . cmc_resource_format_qty($onHand) . ' assignable';
                                }
                            } else {
                                $availLabel = cmc_resource_format_qty($assignable) . ' available';
                            }
                            ?>
                            <option value="<?= (int) $w['id'] ?>">
                                <?= e((string) $w['name']) ?> — <?= e($availLabel) ?> <?= e((string) $w['unit']) ?>
                                (₹<?= e(cmc_resource_format_money((float) ($w['unit_rate'] ?? 0))) ?>/u)
                            </option>
                        <?php endforeach; ?>
                        <?php if ($lastKind !== '') {
                            echo '</optgroup>';
                        } ?>
                    </select>
                </label>
                <label class="field" style="min-width:8rem;">
                    <span class="field-label">Quantity</span>
                    <input class="input" name="assign_qty" inputmode="decimal" required placeholder="e.g. 2">
                </label>
                <div class="form-actions" style="align-self:flex-end;">
                    <button class="btn btn-primary" type="submit">Assign</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($assignments) : ?>
        <div class="table-wrap" style="border:0; box-shadow:none; padding:0;">
            <table class="table table-compact">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Pool</th>
                        <th>Assigned</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Released</th>
                        <th class="th-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $a) : ?>
                        <tr>
                            <td><span class="pill pill-soft"><?= e(cmc_resource_kind_label((string) ($a['resource_kind'] ?? 'material'))) ?></span></td>
                            <td><?= e((string) $a['resource_name']) ?> <span class="muted">(<?= e((string) $a['resource_unit']) ?>)</span></td>
                            <td><?= e(cmc_resource_format_qty((float) $a['assigned_quantity'])) ?></td>
                            <td><?= e((string) $a['status']) ?></td>
                            <td class="muted"><?= e((string) $a['created_at']) ?></td>
                            <td class="muted"><?= $a['released_at'] !== null ? e((string) $a['released_at']) : '—' ?></td>
                            <td class="td-actions">
                                <?php if (($a['status'] ?? '') === 'active') : ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Release this assignment?');">
                                        <?= cmc_csrf_field() ?>
                                        <input type="hidden" name="cmc_action" value="release_pool">
                                        <input type="hidden" name="assignment_id" value="<?= (int) $a['id'] ?>">
                                        <button class="btn btn-sm btn-ghost" type="submit">Release</button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($fulfillment) : ?>
        <p class="muted">No assignments yet.</p>
    <?php endif; ?>
</div>

<?php if ($fulfillment && $existingLines) : ?>
    <div class="card">
        <h3 class="subheading" style="margin-top:0">Saved lines</h3>
        <div class="table-wrap" style="border:0; box-shadow:none; padding:0;">
            <table class="table table-compact">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th>Rate / unit</th>
                        <th>Qty</th>
                        <th>Line cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($existingLines as $ln) : ?>
                        <?php
                        $rate = (float) ($ln['unit_rate'] ?? 0);
                        $q = (float) $ln['quantity'];
                        $lineCost = $rate * $q;
                        ?>
                        <tr>
                            <td><?= e((string) $ln['item_name']) ?></td>
                            <td><?= e((string) $ln['item_unit']) ?></td>
                            <td><?= e(cmc_resource_format_money($rate)) ?></td>
                            <td><?= e(cmc_resource_format_qty($q)) ?></td>
                            <td><?= e(cmc_resource_format_money($lineCost)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
cmc_layout_end();
