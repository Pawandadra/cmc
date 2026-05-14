<?php

declare(strict_types=1);

/** @return array<string, string> mime => label */
function cmc_complaint_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'JPEG image',
        'image/png' => 'PNG image',
        'image/gif' => 'GIF image',
        'image/webp' => 'WebP image',
        'video/mp4' => 'MP4 video',
        'application/pdf' => 'PDF document',
    ];
}

function cmc_complaint_status_label(string $status): string
{
    return match ($status) {
        'pending_hod' => 'Awaiting HOD',
        'hod_rejected' => 'Rejected by HOD',
        'pending_sde' => 'Awaiting SDE',
        'sde_approved' => 'Approved by SDE',
        'sde_rejected' => 'Rejected by SDE',
        default => $status,
    };
}

function cmc_complaint_priority_label(string $p): string
{
    return match ($p) {
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        default => $p,
    };
}

/** @param array<string, mixed> $u */
/** @param array<string, mixed> $c complaint row */
function cmc_complaint_user_can_view(array $u, array $c): bool
{
    if ($u['role'] === 'admin') {
        return true;
    }
    if ((int) $c['raised_by_user_id'] === (int) $u['id']) {
        return true;
    }
    if ($u['role'] === 'hod'
        && isset($u['department_id'], $c['department_id'])
        && (int) $u['department_id'] === (int) $c['department_id']) {
        return true;
    }
    if ($u['role'] === 'sde' && in_array($c['status'], ['pending_sde', 'sde_approved', 'sde_rejected'], true)) {
        return true;
    }
    return false;
}

/** @param array<string, mixed> $u */
/** @param array<string, mixed> $c */
function cmc_complaint_hod_can_act(array $u, array $c): bool
{
    return $u['role'] === 'hod'
        && (int) $u['department_id'] === (int) $c['department_id']
        && $c['status'] === 'pending_hod';
}

/** @param array<string, mixed> $u */
/** @param array<string, mixed> $c */
function cmc_complaint_sde_can_act(array $u, array $c): bool
{
    return $u['role'] === 'sde' && $c['status'] === 'pending_sde';
}

function cmc_complaint_upload_base(): string
{
    $base = (string) ($GLOBALS['cmcConfig']['base_path'] ?? dirname(__DIR__));
    return $base . '/data/uploads';
}

/**
 * @param array<string, mixed> $u current user
 * @return array{0: string|null, 1: int} [error or null, new complaint id]
 */
function cmc_complaint_create_from_post(PDO $pdo, array $u, array $cfg): array
{
    if (!in_array($u['role'], ['member', 'hod'], true)) {
        return ['Only department members or HODs can raise complaints.', 0];
    }
    $orgId = (int) ($u['organisation_id'] ?? 0);
    $deptId = (int) ($u['department_id'] ?? 0);
    if ($orgId < 1 || $deptId < 1) {
        return ['Your account is not linked to a department.', 0];
    }

    $subject = trim((string) ($_POST['subject'] ?? ''));
    $details = trim((string) ($_POST['details'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $phone = trim((string) ($_POST['contact_phone'] ?? ''));

    if ($subject === '' || strlen($subject) > 500) {
        return ['Subject is required (max 500 characters).', 0];
    }
    if ($details === '' || strlen($details) > 20000) {
        return ['Details are required (max 20,000 characters).', 0];
    }
    if ($location === '' || strlen($location) > 1000) {
        return ['Location is required (max 1,000 characters).', 0];
    }
    if ($phone !== '' && strlen($phone) > 40) {
        return ['Contact phone is too long.', 0];
    }

    $phoneVal = $phone === '' ? null : $phone;

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO complaints (organisation_id, department_id, raised_by_user_id, subject, details, location, contact_phone, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending_hod\')'
        );
        $st->execute([
            $orgId,
            $deptId,
            (int) $u['id'],
            $subject,
            $details,
            $location,
            $phoneVal,
        ]);
        $cid = (int) $pdo->lastInsertId();

        $ev = $pdo->prepare(
            'INSERT INTO complaint_events (complaint_id, actor_user_id, event_type, comment) VALUES (?, ?, \'submitted\', NULL)'
        );
        $ev->execute([$cid, (int) $u['id']]);

        $err = cmc_complaint_save_uploads($pdo, $cid, $cfg);
        if ($err !== null) {
            $pdo->rollBack();
            return [$err, 0];
        }

        $pdo->commit();
        return [null, $cid];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['Could not save the complaint. Please try again.', 0];
    }
}

/**
 * @param array<string, mixed> $cfg cmcConfig
 * @return string|null error message
 */
function cmc_complaint_save_uploads(PDO $pdo, int $complaintId, array $cfg): ?string
{
    $maxTotal = (int) ($cfg['complaint_max_upload_bytes'] ?? 40 * 1024 * 1024);
    $maxFile = (int) ($cfg['complaint_max_file_bytes'] ?? 20 * 1024 * 1024);
    $maxCount = (int) ($cfg['complaint_max_files'] ?? 8);
    $allowed = cmc_complaint_allowed_mimes();

    if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments']['name'])) {
        return null;
    }

    $names = $_FILES['attachments']['name'];
    $tmps = $_FILES['attachments']['tmp_name'];
    $errs = $_FILES['attachments']['error'];
    $sizes = $_FILES['attachments']['size'];

    $n = count($names);
    if ($n > $maxCount) {
        return 'Too many files (maximum ' . $maxCount . ').';
    }

    $total = 0;
    $dir = cmc_complaint_upload_base() . '/c' . $complaintId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        return 'Could not create upload directory.';
    }

    $ins = $pdo->prepare(
        'INSERT INTO complaint_attachments (complaint_id, stored_name, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?)'
    );

    for ($i = 0; $i < $n; $i++) {
        $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            return 'One of the uploads failed to transfer.';
        }
        $tmp = (string) ($tmps[$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'Invalid upload.';
        }
        $size = (int) ($sizes[$i] ?? 0);
        if ($size < 1 || $size > $maxFile) {
            return 'Each file must be under ' . round($maxFile / 1024 / 1024) . ' MB.';
        }
        $total += $size;
        if ($total > $maxTotal) {
            return 'Total attachments exceed the allowed size.';
        }

        $mime = false;
        if (class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($tmp);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($tmp);
        }
        if ($mime === false || $mime === '' || !isset($allowed[$mime])) {
            return 'Only images (JPEG, PNG, GIF, WebP), MP4 video, or PDF files are allowed.';
        }

        $orig = (string) ($names[$i] ?? 'file');
        $orig = basename(str_replace(["\0"], '', $orig));
        if ($orig === '' || $orig === '.' || $orig === '..') {
            $orig = 'file';
        }
        if (strlen($orig) > 200) {
            $orig = substr($orig, 0, 200);
        }

        $stored = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $orig);
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            return 'Could not store an attachment.';
        }

        $ins->execute([$complaintId, $stored, $orig, $mime, $size]);
    }

    return null;
}

/** @return list<array<string, mixed>> */
function cmc_complaint_events(PDO $pdo, int $complaintId): array
{
    $st = $pdo->prepare(
        'SELECT e.*, u.full_name AS actor_name, u.email AS actor_email
         FROM complaint_events e
         JOIN users u ON u.id = e.actor_user_id
         WHERE e.complaint_id = ?
         ORDER BY e.id ASC'
    );
    $st->execute([$complaintId]);
    return $st->fetchAll();
}

/** @return list<array<string, mixed>> */
function cmc_complaint_attachments(PDO $pdo, int $complaintId): array
{
    $st = $pdo->prepare('SELECT * FROM complaint_attachments WHERE complaint_id = ? ORDER BY id ASC');
    $st->execute([$complaintId]);
    return $st->fetchAll();
}

/** @return array<string, mixed>|null */
function cmc_complaint_event_label(string $type): string
{
    return match ($type) {
        'submitted' => 'Submitted',
        'hod_forwarded' => 'Forwarded to SDE by HOD',
        'hod_rejected' => 'Rejected by HOD',
        'sde_approved' => 'Approved by SDE',
        'sde_rejected' => 'Rejected by SDE',
        default => $type,
    };
}

/** @return array<string, mixed>|null */
function cmc_complaint_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT c.*, o.name AS organisation_name, d.name AS department_name,
                rb.full_name AS raised_by_name, rb.email AS raised_by_email
         FROM complaints c
         JOIN organisations o ON o.id = c.organisation_id
         JOIN departments d ON d.id = c.department_id
         JOIN users rb ON rb.id = c.raised_by_user_id
         WHERE c.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}
