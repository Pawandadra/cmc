<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/** @var array $cmcConfig */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "CMC requires the PHP PDO SQLite driver (pdo_sqlite).\nExample (Debian/Ubuntu): sudo apt install php-sqlite3\n";
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_name($cmcConfig['session_name']);
    session_start();
}

$dataDir = dirname($cmcConfig['db_path']);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0700, true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/complaint_lib.php';
require_once __DIR__ . '/resources_lib.php';
require_once __DIR__ . '/billing_lib.php';
require_once __DIR__ . '/fulfillment_lib.php';

cmc_db_init($cmcConfig['db_path']);
