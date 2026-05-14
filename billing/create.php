<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcBillingUser;
$pdo = cmc_db();

$fulfillments = $pdo->query(
    'SELECT f.id, f.complaint_id, f.work_status, c.subject
     FROM complaint_fulfillments f
     JOIN complaints c ON c.id = f.complaint_id
     ORDER BY f.id DESC
     LIMIT 300'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $fid = isset($_POST['fulfillment_id']) ? (int) $_POST['fulfillment_id'] : 0;
    $wageRaw = trim((string) ($_POST['wage_adjustment'] ?? '0'));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $labels = $_POST['other_label'] ?? [];
    $amounts = $_POST['other_amount'] ?? [];

    if ($fid < 1) {
        cmc_flash_set('error', 'Select a fulfillment.');
        cmc_redirect('billing/create.php');
    }
    $okF = $pdo->prepare('SELECT id FROM complaint_fulfillments WHERE id = ?');
    $okF->execute([$fid]);
    if (!$okF->fetch()) {
        cmc_flash_set('error', 'Fulfillment not found.');
        cmc_redirect('billing/create.php');
    }
    if (!is_numeric($wageRaw)) {
        cmc_flash_set('error', 'Labour adjustment must be a number.');
        cmc_redirect('billing/create.php');
    }
    $wageAdj = (float) $wageRaw;
    if (strlen($notes) > 10000) {
        cmc_flash_set('error', 'Notes are too long.');
        cmc_redirect('billing/create.php');
    }

    /** @var list<array{label: string, amount: float}> $otherLines */
    $otherLines = [];
    if (is_array($labels) && is_array($amounts)) {
        $n = max(count($labels), count($amounts));
        for ($i = 0; $i < $n; $i++) {
            $lbl = isset($labels[$i]) ? trim((string) $labels[$i]) : '';
            $amRaw = isset($amounts[$i]) ? trim((string) $amounts[$i]) : '';
            if ($lbl === '' && $amRaw === '') {
                continue;
            }
            if ($amRaw === '' || !is_numeric($amRaw)) {
                cmc_flash_set('error', 'Enter a valid amount for each other expense line.');
                cmc_redirect('billing/create.php');
            }
            $amt = (float) $amRaw;
            if ($amt < 0) {
                cmc_flash_set('error', 'Other expense amounts must be zero or positive.');
                cmc_redirect('billing/create.php');
            }
            if ($amt < 1e-9 && $lbl === '') {
                continue;
            }
            $otherLines[] = ['label' => $lbl === '' ? 'Other expense' : $lbl, 'amount' => $amt];
        }
    }

    $pdo->beginTransaction();
    try {
        $bid = cmc_billing_create_record(
            $pdo,
            $fid,
            (int) $user['id'],
            $wageAdj,
            $otherLines,
            $notes === '' ? null : $notes
        );
        $pdo->commit();
        cmc_flash_set('success', 'Bill #' . $bid . ' saved.');
        cmc_redirect('billing/view.php?id=' . $bid);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cmc_flash_set('error', 'Could not save bill.');
        cmc_redirect('billing/create.php');
    }
}

$preFid = isset($_GET['fulfillment_id']) ? (int) $_GET['fulfillment_id'] : 0;

cmc_layout_start('Generate bill', $user);
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('billing/index.php')) ?>">← All bills</a>
</div>

<div class="card card-form">
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <label class="field">
            <span class="field-label">Fulfillment</span>
            <select class="input" name="fulfillment_id" required>
                <option value="">Select…</option>
                <?php foreach ($fulfillments as $f) : ?>
                    <option value="<?= (int) $f['id'] ?>"<?= $preFid === (int) $f['id'] ? ' selected' : '' ?>>
                        #<?= (int) $f['id'] ?> · Complaint <?= (int) $f['complaint_id'] ?> · <?= e(cmc_fulfillment_work_status_label((string) $f['work_status'])) ?> · <?= e((string) $f['subject']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span class="field-label">Labour adjustment (₹)</span>
            <input class="input" name="wage_adjustment" value="0" inputmode="decimal" step="any">
            <span class="muted small">Optional adjustment to labour totals.</span>
        </label>

        <fieldset class="field">
            <legend class="field-label">Other expenses (₹)</legend>
            <div id="other-expenses-rows" class="other-expenses-rows">
                <div class="form-row other-expense-row" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; margin-bottom:0.5rem;">
                    <label class="field grow">
                        <span class="field-label">Description</span>
                        <input class="input" name="other_label[]" maxlength="200" placeholder="">
                    </label>
                    <label class="field" style="max-width:12rem;">
                        <span class="field-label">Amount (₹)</span>
                        <input class="input" name="other_amount[]" inputmode="decimal" placeholder="" step="any">
                    </label>
                    <button type="button" class="btn btn-ghost btn-sm other-expense-remove" aria-label="Remove row">×</button>
                </div>
            </div>
            <p style="margin:0.25rem 0 0;">
                <button type="button" class="btn btn-ghost btn-sm" id="other-expense-add" title="Add row">+</button>
            </p>
            <template id="other-expense-row-template">
                <div class="form-row other-expense-row" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end; margin-bottom:0.5rem;">
                    <label class="field grow">
                        <span class="field-label">Description</span>
                        <input class="input" name="other_label[]" maxlength="200" placeholder="">
                    </label>
                    <label class="field" style="max-width:12rem;">
                        <span class="field-label">Amount (₹)</span>
                        <input class="input" name="other_amount[]" inputmode="decimal" placeholder="" step="any">
                    </label>
                    <button type="button" class="btn btn-ghost btn-sm other-expense-remove" aria-label="Remove row">×</button>
                </div>
            </template>
            <script>
            (function () {
                var container = document.getElementById('other-expenses-rows');
                var tpl = document.getElementById('other-expense-row-template');
                var addBtn = document.getElementById('other-expense-add');
                if (!container || !tpl || !addBtn) return;

                function bindRemove(row) {
                    var rm = row.querySelector('.other-expense-remove');
                    if (!rm) return;
                    rm.addEventListener('click', function () {
                        var rows = container.querySelectorAll('.other-expense-row');
                        if (rows.length <= 1) {
                            row.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
                            return;
                        }
                        row.remove();
                    });
                }

                function addRow() {
                    var frag = tpl.content.cloneNode(true);
                    var row = frag.querySelector('.other-expense-row');
                    if (!row) return;
                    container.appendChild(frag);
                    bindRemove(row);
                }

                container.querySelectorAll('.other-expense-row').forEach(bindRemove);
                addBtn.addEventListener('click', addRow);
            })();
            </script>
        </fieldset>

        <label class="field">
            <span class="field-label">Notes (optional)</span>
            <textarea class="input textarea" name="notes" rows="3" maxlength="10000"></textarea>
        </label>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Generate bill</button>
        </div>
    </form>
</div>
<?php
cmc_layout_end();
