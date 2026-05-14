<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cmc_url(string $path): string
{
    $path = ltrim($path, '/');
    $base = rtrim((string) ($GLOBALS['cmcConfig']['base_url'] ?? ''), '/');
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
