<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

cmc_logout();
cmc_redirect('index.php');
