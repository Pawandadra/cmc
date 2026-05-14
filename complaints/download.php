<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = cmc_require_login();
$aid = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($aid < 1) {
    http_response_code(404);
    exit('Not found');
}

$pdo = cmc_db();
$st = $pdo->prepare(
    'SELECT a.stored_name, a.original_name, a.mime_type, a.size_bytes, a.complaint_id
     FROM complaint_attachments a
     WHERE a.id = ?'
);
$st->execute([$aid]);
$a = $st->fetch();
if (!$a) {
    http_response_code(404);
    exit('Not found');
}

$c = cmc_complaint_fetch($pdo, (int) $a['complaint_id']);
if ($c === null || !cmc_complaint_user_can_view($user, $c)) {
    http_response_code(403);
    exit('Forbidden');
}

$stored = (string) $a['stored_name'];
if ($stored === '' || str_contains($stored, '..') || str_contains($stored, '/')) {
    http_response_code(500);
    exit('Invalid file reference');
}

$path = cmc_complaint_upload_base() . '/c' . (int) $a['complaint_id'] . '/' . $stored;
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing');
}

$mime = (string) $a['mime_type'];
$orig = (string) $a['original_name'];
$asciiName = preg_replace('/[^\x20-\x7E]+/', '_', $orig);
if ($asciiName === '' || $asciiName === '.' || $asciiName === '..') {
    $asciiName = 'attachment';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) (int) $a['size_bytes']);
header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\'], '', $asciiName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
