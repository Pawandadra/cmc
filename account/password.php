<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = cmc_require_login();
$pdo = cmc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    $err = cmc_user_change_own_password($pdo, (int) $user['id'], $current, $new, $confirm);
    if ($err !== null) {
        cmc_flash_set('error', $err);
    } else {
        cmc_flash_set('success', 'Your password has been updated.');
    }
    cmc_redirect('account/password.php');
}

cmc_layout_start('Change password', $user);
?>
<div class="card card-form" style="max-width: 480px;">
    <p class="muted">Use a strong password you do not use elsewhere. Minimum 10 characters.</p>
    <form method="post" class="form-stack">
        <?= cmc_csrf_field() ?>
        <label class="field">
            <span class="field-label">Current password</span>
            <input class="input" type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label class="field">
            <span class="field-label">New password</span>
            <input class="input" type="password" name="new_password" required autocomplete="new-password" minlength="10">
        </label>
        <label class="field">
            <span class="field-label">Confirm new password</span>
            <input class="input" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="10">
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update password</button>
            <a class="btn btn-ghost" href="<?= e(cmc_url('dashboard.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php
cmc_layout_end();
