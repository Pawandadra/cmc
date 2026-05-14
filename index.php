<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (cmc_user_count() === 0) {
    cmc_redirect('install.php');
}

if (cmc_user() !== null) {
    cmc_redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (!cmc_login($email, $password)) {
        $error = 'Invalid email or password.';
    } else {
        cmc_redirect('dashboard.php');
    }
}

$cmcConfig = $GLOBALS['cmcConfig'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e($cmcConfig['app_name']) ?></title>
    <link rel="stylesheet" href="<?= e(cmc_url('assets/css/style.css')) ?>">
</head>
<body class="theme-erp login-page">
    <div class="login-card">
        <div class="brand brand-lg"><span class="brand-mark"></span><?= e($cmcConfig['app_name']) ?></div>
        <p class="muted">Construction &amp; Management Cell</p>
        <?php if ($error !== '') : ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="form-stack">
            <?= cmc_csrf_field() ?>
            <label class="field">
                <span class="field-label">Email</span>
                <input class="input" type="email" name="email" required autocomplete="username">
            </label>
            <label class="field">
                <span class="field-label">Password</span>
                <input class="input" type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
