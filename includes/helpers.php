<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL path prefix (e.g. '' or '/cmc'). No trailing slash.
 * Prefer config `base_url`; if unset, infer from SCRIPT_NAME so subfolder deploy works without config.local.php.
 */
function cmc_base_path(): string
{
    $base = rtrim((string) ($GLOBALS['cmcConfig']['base_url'] ?? ''), '/');
    if ($base !== '') {
        return $base;
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '' || $script === '/') {
        return '';
    }
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.' || $dir === '') {
        return '';
    }

    return rtrim($dir, '/');
}

function cmc_url(string $path): string
{
    $path = ltrim($path, '/');
    $base = cmc_base_path();

    return ($base === '' ? '' : $base) . '/' . $path;
}

function cmc_redirect(string $path): never
{
    if ($path !== '' && $path[0] === '/') {
        $url = $path;
    } else {
        $url = cmc_url($path);
    }
    header('Location: ' . $url);
    exit;
}
