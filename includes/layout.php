<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $user
 * @param list<array{href: string, label: string}>|null $navExtra
 */
function cmc_layout_start(string $title, array $user, ?array $navExtra = null): void
{
    /** @var array $cmcConfig */
    $cmcConfig = $GLOBALS['cmcConfig'];
    $app = e($cmcConfig['app_name']);
    $fullTitle = e($title) . ' · ' . $app;
    $css = cmc_url('assets/css/style.css');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $fullTitle ?></title>
    <link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body class="theme-erp">
    <div class="app-shell">
        <aside class="sidebar">
            <div class="brand"><span class="brand-mark"></span><span><?= $app ?></span></div>
            <nav class="nav">
                <a class="nav-item" href="<?= e(cmc_url('dashboard.php')) ?>">Dashboard</a>
                <?php if (in_array($user['role'], ['member', 'hod', 'sde', 'admin'], true)) : ?>
                    <div class="nav-group-label">Complaints</div>
                    <?php if (in_array($user['role'], ['member', 'hod'], true)) : ?>
                        <a class="nav-item" href="<?= e(cmc_url('complaints/create.php')) ?>">Raise complaint</a>
                    <?php endif; ?>
                    <a class="nav-item" href="<?= e(cmc_url('complaints/index.php')) ?>">Complaint list</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'sde') : ?>
                    <div class="nav-group-label">Approved complaints</div>
                    <a class="nav-item" href="<?= e(cmc_url('fulfillment/index.php')) ?>">Fulfillment work</a>
                    <div class="nav-group-label">Resources &amp; Billing</div>
                    <a class="nav-item" href="<?= e(cmc_url('resources/index.php')) ?>">Resources</a>
                    <a class="nav-item" href="<?= e(cmc_url('billing/index.php')) ?>">Billing</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin') : ?>
                    <div class="nav-group-label">Administration</div>
                    <a class="nav-item" href="<?= e(cmc_url('admin/complaints.php')) ?>">Complaints</a>
                    <a class="nav-item" href="<?= e(cmc_url('admin/organisations.php')) ?>">Organisations</a>
                    <a class="nav-item" href="<?= e(cmc_url('admin/departments.php')) ?>">Departments</a>
                    <a class="nav-item" href="<?= e(cmc_url('admin/users.php')) ?>">Users</a>
                <?php endif; ?>
                <?php if ($navExtra) {
                    foreach ($navExtra as $item) {
                        echo '<a class="nav-item" href="' . e($item['href']) . '">' . e($item['label']) . '</a>';
                    }
                } ?>
            </nav>
            <div class="sidebar-footer">
                <div class="user-chip">
                    <span class="user-name"><?= e($user['full_name']) ?></span>
                    <span class="user-role"><?= e(strtoupper($user['role'])) ?></span>
                </div>
                <a class="btn btn-ghost btn-sm" href="<?= e(cmc_url('logout.php')) ?>">Sign out</a>
            </div>
        </aside>
        <main class="main">
            <header class="main-header">
                <h1 class="page-title"><?= e($title) ?></h1>
            </header>
            <div class="main-body">
    <?php
    $flash = cmc_flash_take();
    if ($flash && isset($flash['message'], $flash['type'])) {
        $cls = $flash['type'] === 'error' ? 'flash flash-error' : 'flash flash-success';
        echo '<div class="' . e($cls) . '">' . e((string) $flash['message']) . '</div>';
    }
}

function cmc_layout_end(): void
{
    $js = cmc_url('assets/js/app.js');
    ?>
            </div>
        </main>
    </div>
    <script src="<?= e($js) ?>" defer></script>
</body>
</html>
    <?php
}

function cmc_flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function cmc_flash_take(): ?array
{
    if (empty($_SESSION['_flash'])) {
        return null;
    }
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return is_array($f) ? $f : null;
}
