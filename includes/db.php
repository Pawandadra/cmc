<?php

declare(strict_types=1);

function cmc_db_init(string $dbPath): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $needSchema = !is_file($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $GLOBALS['cmc_pdo'] = $pdo;

    if ($needSchema) {
        cmc_run_schema($pdo);
    }

    require_once __DIR__ . '/migrations.php';
    cmc_run_migrations($pdo);
}

function cmc_db(): PDO
{
    if (!isset($GLOBALS['cmc_pdo']) || !$GLOBALS['cmc_pdo'] instanceof PDO) {
        throw new RuntimeException('Database not initialised.');
    }
    return $GLOBALS['cmc_pdo'];
}

function cmc_run_schema(PDO $pdo): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('schema.sql missing.');
    }
    $pdo->exec($sql);
}
