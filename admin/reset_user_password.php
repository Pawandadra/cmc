<?php

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
$user = $cmcAdminUser;
$pdo = cmc_db();

$targetId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($targetId < 1) {
    cmc_flash_set('error', 'Invalid user.');
    cmc_redirect('admin/users.php');
}

$st = $pdo->prepare(
    'SELECT u.id, u.email, u.full_name, u.role,
            o.name AS organisation_name, d.name AS department_name
     FROM users u
     LEFT JOIN organisations o ON o.id = u.organisation_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.id = ?'
);
$st->execute([$targetId]);
$target = $st->fetch();
if (!$target) {
    cmc_flash_set('error', 'User not found.');
    cmc_redirect('admin/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    $err = cmc_admin_set_user_password($pdo, $targetId, $new, $confirm);
    if ($err !== null) {
        cmc_flash_set('error', $err);
        cmc_redirect('admin/reset_user_password.php?id=' . $targetId);
    }
    cmc_flash_set('success', 'Password updated for ' . (string) $target['email'] . '.');
    cmc_redirect('admin/users.php');
}

cmc_layout_start('Reset password', $user);
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(cmc_url('admin/users.php')) ?>">← All users</a>
</div>

<div class="card card-form" style="max-width: 520px;">
    <h2 class="card-title"><?= e((string) $target['full_name']) ?></h2>
    <p class="muted"><?= e((string) $target['email']) ?> · <span class="pill"><?= e(strtoupper((string) $target['role'])) ?></span></p>
    <?php if (!empty($target['organisation_name'])) : ?>
        <p class="muted small"><?= e((string) $target['organisation_name']) ?><?= !empty($target['department_name']) ? ' · ' . e((string) $target['department_name']) : '' ?></p>
    <?php endif; ?>
    <p class="muted">Set a new password for this account. Minimum 10 characters. The user should change it after signing in if you use a temporary password.</p>
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <label class="field">
            <span class="field-label">New password</span>
            <input class="input" type="password" name="new_password" required autocomplete="new-password" minlength="10">
        </label>
        <label class="field">
            <span class="field-label">Confirm new password</span>
            <input class="input" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="10">
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save new password</button>
            <a class="btn btn-ghost" href="<?= e(cmc_url('admin/users.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php
cmc_layout_end();
