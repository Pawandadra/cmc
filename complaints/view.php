<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = cmc_require_login();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    http_response_code(404);
    exit('Not found');
}

$pdo = cmc_db();
$c = cmc_complaint_fetch($pdo, $id);
if ($c === null || !cmc_complaint_user_can_view($user, $c)) {
    http_response_code(403);
    exit('Forbidden');
}

$canHod = cmc_complaint_hod_can_act($user, $c);
$canSde = cmc_complaint_sde_can_act($user, $c);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $action = (string) ($_POST['workflow_action'] ?? '');
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if (strlen($comment) > 5000) {
        cmc_flash_set('error', 'Comment is too long.');
        cmc_redirect('complaints/view.php?id=' . $id);
    }
    $commentNull = $comment === '' ? null : $comment;

    $ok = false;
    $pdo->beginTransaction();
    try {
        if ($action === 'hod_forward' && $canHod) {
            $st = $pdo->prepare('UPDATE complaints SET status = \'pending_sde\', updated_at = datetime(\'now\') WHERE id = ? AND status = \'pending_hod\'');
            $st->execute([$id]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('stale');
            }
            $pdo->prepare(
                'INSERT INTO complaint_events (complaint_id, actor_user_id, event_type, comment) VALUES (?, ?, \'hod_forwarded\', ?)'
            )->execute([$id, (int) $user['id'], $commentNull]);
            $pdo->commit();
            cmc_flash_set('success', 'Complaint forwarded to SDE.');
            $ok = true;
        } elseif ($action === 'hod_reject' && $canHod) {
            $st = $pdo->prepare('UPDATE complaints SET status = \'hod_rejected\', updated_at = datetime(\'now\') WHERE id = ? AND status = \'pending_hod\'');
            $st->execute([$id]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('stale');
            }
            $pdo->prepare(
                'INSERT INTO complaint_events (complaint_id, actor_user_id, event_type, comment) VALUES (?, ?, \'hod_rejected\', ?)'
            )->execute([$id, (int) $user['id'], $commentNull]);
            $pdo->commit();
            cmc_flash_set('success', 'Complaint rejected.');
            $ok = true;
        } elseif ($action === 'sde_approve' && $canSde) {
            $st = $pdo->prepare('UPDATE complaints SET status = \'sde_approved\', updated_at = datetime(\'now\') WHERE id = ? AND status = \'pending_sde\'');
            $st->execute([$id]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('stale');
            }
            $pdo->prepare(
                'INSERT INTO complaint_events (complaint_id, actor_user_id, event_type, comment) VALUES (?, ?, \'sde_approved\', ?)'
            )->execute([$id, (int) $user['id'], $commentNull]);
            $pdo->commit();
            cmc_flash_set('success', 'Complaint approved.');
            $ok = true;
        } elseif ($action === 'sde_reject' && $canSde) {
            $st = $pdo->prepare('UPDATE complaints SET status = \'sde_rejected\', updated_at = datetime(\'now\') WHERE id = ? AND status = \'pending_sde\'');
            $st->execute([$id]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('stale');
            }
            $pdo->prepare(
                'INSERT INTO complaint_events (complaint_id, actor_user_id, event_type, comment) VALUES (?, ?, \'sde_rejected\', ?)'
            )->execute([$id, (int) $user['id'], $commentNull]);
            $pdo->commit();
            cmc_flash_set('success', 'Complaint rejected.');
            $ok = true;
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        cmc_flash_set('error', 'Could not apply the action. It may have already been processed—refresh the page.');
        cmc_redirect('complaints/view.php?id=' . $id);
    }

    if (!$ok) {
        cmc_flash_set('error', 'That action is not available.');
        cmc_redirect('complaints/view.php?id=' . $id);
    }

    cmc_redirect('complaints/view.php?id=' . $id);
}

// Re-fetch after potential redirect skip
$c = cmc_complaint_fetch($pdo, $id);
if ($c === null) {
    http_response_code(404);
    exit('Not found');
}
$canHod = cmc_complaint_hod_can_act($user, $c);
$canSde = cmc_complaint_sde_can_act($user, $c);

$fulfillment = null;
if ($user['role'] === 'sde' && ($c['status'] ?? '') === 'sde_approved') {
    $fulfillment = cmc_fulfillment_by_complaint($pdo, $id);
}

$events = cmc_complaint_events($pdo, $id);
$attachments = cmc_complaint_attachments($pdo, $id);

cmc_layout_start('Complaint #' . $id, $user);
?>
<div class="detail-grid">
    <section class="card">
        <div class="detail-meta">
            <span class="pill"><?= e(cmc_complaint_status_label((string) $c['status'])) ?></span>
        </div>
        <h2 class="card-title"><?= e((string) $c['subject']) ?></h2>
        <dl class="dl-grid">
            <dt>Complaint ID</dt>
            <dd class="muted"><?= (int) $id ?></dd>
            <dt>Raised by</dt>
            <dd><?= e((string) $c['raised_by_name']) ?> <span class="muted">(<?= e((string) $c['raised_by_email']) ?>)</span></dd>
            <dt>Organisation</dt>
            <dd><?= e((string) $c['organisation_name']) ?></dd>
            <dt>Department</dt>
            <dd><?= e((string) $c['department_name']) ?></dd>
            <dt>Location</dt>
            <dd><?= nl2br(e((string) $c['location'])) ?></dd>
            <?php if (!empty($c['contact_phone'])) : ?>
                <dt>Contact phone</dt>
                <dd><?= e((string) $c['contact_phone']) ?></dd>
            <?php endif; ?>
            <dt>Submitted</dt>
            <dd class="muted"><?= e((string) $c['created_at']) ?></dd>
        </dl>
        <h3 class="subheading">Details</h3>
        <div class="detail-body"><?= nl2br(e((string) $c['details'])) ?></div>

        <?php if ($attachments) : ?>
            <h3 class="subheading">Attachments</h3>
            <ul class="attach-list">
                <?php foreach ($attachments as $a) : ?>
                    <li>
                        <a href="<?= e(cmc_url('complaints/download.php?id=' . (int) $a['id'])) ?>"><?= e((string) $a['original_name']) ?></a>
                        <span class="muted"> · <?= (int) $a['size_bytes'] ?> bytes</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($user['role'] === 'sde' && ($c['status'] ?? '') === 'sde_approved') : ?>
            <h3 class="subheading">Fulfillment</h3>
            <p class="muted small">Plan materials, assignments, and status here. Billing is tied to this fulfillment.</p>
            <?php if ($fulfillment) : ?>
                <p>Status: <span class="pill"><?= e(cmc_fulfillment_work_status_label((string) $fulfillment['work_status'])) ?></span>
                    <span class="muted"> · Updated <?= e((string) $fulfillment['updated_at']) ?></span></p>
            <?php else : ?>
                <p class="muted">No fulfillment plan saved yet for this complaint.</p>
            <?php endif; ?>
            <p><a class="btn btn-primary" href="<?= e(cmc_url('fulfillment/work.php?complaint_id=' . $id)) ?>"><?= $fulfillment ? 'Open fulfillment' : 'Start fulfillment' ?></a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('fulfillment/index.php')) ?>">Fulfillment work list</a></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3 class="card-title">Timeline</h3>
        <ul class="timeline">
            <?php foreach ($events as $ev) : ?>
                <li class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-body">
                        <div class="timeline-title"><?= e(cmc_complaint_event_label((string) $ev['event_type'])) ?></div>
                        <div class="muted small">
                            <?= e((string) $ev['actor_name']) ?> · <?= e((string) $ev['created_at']) ?>
                        </div>
                        <?php if (!empty($ev['comment'])) : ?>
                            <div class="timeline-comment"><?= nl2br(e((string) $ev['comment'])) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($canHod) : ?>
            <h3 class="subheading">HOD decision</h3>
            <form method="post" class="form-stack" id="hod-workflow-form">
                <?= cmc_csrf_field() ?>
                <label class="field">
                    <span class="field-label">Comment (optional for forward; recommended if rejecting)</span>
                    <textarea class="input textarea" name="comment" rows="3" maxlength="5000" placeholder="Notes for the record"></textarea>
                </label>
                <div class="form-actions">
                    <button class="btn btn-primary" name="workflow_action" value="hod_forward" type="submit">Forward to SDE</button>
                    <button class="btn btn-danger" name="workflow_action" value="hod_reject" type="submit" id="hod-reject-submit">Reject</button>
                </div>
            </form>
            <script>
            document.getElementById("hod-reject-submit")?.addEventListener("click", function (e) {
                if (!confirm("Reject this complaint?")) e.preventDefault();
            });
            </script>
        <?php elseif ($canSde) : ?>
            <h3 class="subheading">SDE decision</h3>
            <p class="muted small">You are reviewing a complaint forwarded from the department HOD. The submitter is shown above.</p>
            <form method="post" class="form-stack" id="sde-workflow-form">
                <?= cmc_csrf_field() ?>
                <label class="field">
                    <span class="field-label">Comment (optional)</span>
                    <textarea class="input textarea" name="comment" rows="3" maxlength="5000"></textarea>
                </label>
                <div class="form-actions">
                    <button class="btn btn-primary" name="workflow_action" value="sde_approve" type="submit">Approve</button>
                    <button class="btn btn-danger" name="workflow_action" value="sde_reject" type="submit" id="sde-reject-submit">Reject</button>
                </div>
            </form>
            <script>
            document.getElementById("sde-reject-submit")?.addEventListener("click", function (e) {
                if (!confirm("Reject this complaint?")) e.preventDefault();
            });
            </script>
        <?php endif; ?>
    </section>
</div>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">Back to list</a>
</div>
<?php
cmc_layout_end();
