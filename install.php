<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (cmc_user_count() > 0) {
    cmc_flash_set('error', 'Application is already initialised.');
    cmc_redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cmc_csrf_validate();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password_confirm'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email.';
    } elseif ($name === '') {
        $error = 'Enter the administrator name.';
    } elseif (strlen($pass) < 10) {
        $error = 'Use a password of at least 10 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = cmc_db()->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, organisation_id, department_id)
             VALUES (?, ?, ?, \'admin\', NULL, NULL)'
        );
        $st->execute([$email, $hash, $name]);
        cmc_login($email, $pass);
        cmc_flash_set('success', 'Welcome — your administrator account is ready.');
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
    <title>Set up · <?= e($cmcConfig['app_name']) ?></title>
    <link rel="stylesheet" href="<?= e(cmc_url('assets/css/style.css')) ?>">
</head>
<body class="theme-erp login-page">
    <div class="login-card login-card-wide">
        <div class="brand brand-lg"><span class="brand-mark"></span><?= e($cmcConfig['app_name']) ?></div>
        <h2 class="h2-tight">Create the first administrator</h2>
        <p class="muted">This account can create organisations, departments, SDC users, and HODs. This screen only appears once.</p>
        <?php if ($error !== '') : ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="form-stack">
            <?= cmc_csrf_field() ?>
            <label class="field">
                <span class="field-label">Full name</span>
                <input class="input" name="full_name" required autocomplete="name">
            </label>
            <label class="field">
                <span class="field-label">Email</span>
                <input class="input" type="email" name="email" required autocomplete="username">
            </label>
            <label class="field">
                <span class="field-label">Password</span>
                <input class="input" type="password" name="password" required autocomplete="new-password" minlength="10">
            </label>
            <label class="field">
                <span class="field-label">Confirm password</span>
                <input class="input" type="password" name="password_confirm" required autocomplete="new-password" minlength="10">
            </label>
            <button class="btn btn-primary btn-block" type="submit">Finish setup</button>
        </form>
    </div>
</body>
</html>
