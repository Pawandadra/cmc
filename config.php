<?php

declare(strict_types=1);

/**
 * Optional: create config.local.php returning an array to override keys.
 */
$cmcConfig = [
    'app_name' => 'CMC',
    'base_path' => __DIR__,
    'db_path' => __DIR__ . '/data/app.sqlite',
    'session_name' => 'cmc_sess',
    /** No trailing slash; set when app lives in a subfolder, e.g. /cmc */
    'base_url' => '',
    /** Total upload size cap per complaint (bytes). Individual files capped in code. */
    'complaint_max_upload_bytes' => 40 * 1024 * 1024,
    /** Max single file size (bytes) */
    'complaint_max_file_bytes' => 20 * 1024 * 1024,
    /** Max number of attachment files per complaint */
    'complaint_max_files' => 8,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $cmcConfig = array_merge($cmcConfig, $local);
    }
}

$GLOBALS['cmcConfig'] = $cmcConfig;
