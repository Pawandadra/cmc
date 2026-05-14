<?php

declare(strict_types=1);

function cmc_csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function cmc_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(cmc_csrf_token()) . '">';
}

function cmc_csrf_validate(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['_csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Invalid session token. Please refresh and try again.');
    }
}
