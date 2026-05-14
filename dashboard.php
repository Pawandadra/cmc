<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = cmc_require_login();
$title = 'Dashboard';

cmc_layout_start($title, $user);
?>
<div class="grid-2">
    <section class="card">
        <h2 class="card-title">Overview</h2>
        <p class="muted">Signed in as <strong><?= e($user['full_name']) ?></strong> (<?= e($user['email']) ?>).</p>
        <?php if ($user['role'] === 'admin') : ?>
            <p>Use the sidebar to manage <strong>organisations</strong>, <strong>departments</strong>, and <strong>users</strong> (SDE, HOD, department members).</p>
            <p class="muted">You can open the <a href="<?= e(cmc_url('complaints/index.php')) ?>">complaint list</a> to oversee all cases.</p>
        <?php elseif ($user['role'] === 'sde') : ?>
            <p>You review complaints that department HODs <strong>forward</strong> to the cell. You do not browse department or HOD directories; each case shows who raised it.</p>
            <p><a class="btn btn-primary" href="<?= e(cmc_url('complaints/index.php')) ?>">Open complaint queue</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('fulfillment/index.php')) ?>">Fulfillment work</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('resources/index.php')) ?>">Resources</a>
                <a class="btn btn-ghost" href="<?= e(cmc_url('billing/index.php')) ?>">Billing</a></p>
            <p class="muted">After you approve a case, use <strong>Fulfillment work</strong>, <strong>Resources</strong>, and <strong>Billing</strong> as needed.</p>
        <?php elseif ($user['role'] === 'hod') : ?>
            <p>Department: <strong><?= e((string) $user['department_name']) ?></strong> · Organisation: <strong><?= e((string) $user['organisation_name']) ?></strong></p>
            <p>Review new complaints from your department, then forward them to the SDE or reject with a short note.</p>
            <p><a class="btn btn-primary" href="<?= e(cmc_url('complaints/index.php')) ?>">Department complaints</a></p>
        <?php else : ?>
            <p>Organisation: <strong><?= e((string) $user['organisation_name']) ?></strong> · Department: <strong><?= e((string) $user['department_name']) ?></strong></p>
            <p><a class="btn btn-primary" href="<?= e(cmc_url('complaints/create.php')) ?>">Raise a complaint</a>
                &nbsp; <a class="btn btn-ghost" href="<?= e(cmc_url('complaints/index.php')) ?>">My complaints</a></p>
        <?php endif; ?>
    </section>
</div>
<?php
cmc_layout_end();
