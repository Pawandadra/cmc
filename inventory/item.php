<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    cmc_redirect('resources/index.php');
}

cmc_redirect('resources/item.php?id=' . $id);
