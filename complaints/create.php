<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = cmc_require_roles(['member', 'hod']);
$cfg = $GLOBALS['cmcConfig'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    [$err, $cid] = cmc_complaint_create_from_post(cmc_db(), $user, $cfg);
    if ($err !== null) {
        cmc_flash_set('error', $err);
        cmc_redirect('complaints/create.php');
    }
    cmc_flash_set('success', 'Complaint submitted. Your HOD has been notified (in-app).');
    $st = cmc_db()->prepare('SELECT reference_code FROM complaints WHERE id = ?');
    $st->execute([$cid]);
    $newRef = (string) $st->fetchColumn();
    $q = $newRef !== '' ? ('ref=' . rawurlencode($newRef)) : ('id=' . $cid);
    cmc_redirect('complaints/view.php?' . $q);
}

cmc_layout_start('Raise complaint', $user);
?>
<div class="card card-form" style="max-width: 720px;">
    <p class="muted">Complaints are routed to your department HOD first. If they forward the case, the SDE at the cell will review it.</p>
    <form method="post" enctype="multipart/form-data" class="form-stack">
        <?= cmc_csrf_field() ?>
        <label class="field">
            <span class="field-label">Subject</span>
            <input class="input" name="subject" required maxlength="500" placeholder="Short summary">
        </label>
        <label class="field">
            <span class="field-label">Details</span>
            <textarea class="input textarea" name="details" required maxlength="20000" rows="8" placeholder="Describe the issue clearly"></textarea>
        </label>
        <label class="field">
            <span class="field-label">Location</span>
            <input class="input" name="location" required maxlength="1000" placeholder="Site, building, grid reference, etc.">
        </label>
        <label class="field">
            <span class="field-label">Contact phone (optional)</span>
            <input class="input" name="contact_phone" maxlength="40" placeholder="For follow-up if different from profile">
        </label>
        <label class="field">
            <span class="field-label">Attachments (optional)</span>
            <input class="input" type="file" name="attachments[]" multiple
                   accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,application/pdf,.pdf">
            <span class="field-hint">Images (JPEG, PNG, GIF, WebP), MP4 video, or PDF. Up to <?= (int) ($cfg['complaint_max_files'] ?? 8) ?> files; large files may take time to upload.</span>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Submit complaint</button>
            <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php
cmc_layout_end();
