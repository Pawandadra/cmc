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

/** @return list<string> */
function cmc_complaint_statuses_all(): array
{
    return ['pending_hod', 'hod_rejected', 'pending_sde', 'sde_approved', 'sde_rejected'];
}

/** @return list<string> */
function cmc_complaint_statuses_for_sde_queue(): array
{
    return ['pending_sde', 'sde_approved', 'sde_rejected'];
}

function cmc_department_in_organisation(PDO $pdo, int $departmentId, int $organisationId): bool
{
    if ($departmentId < 1 || $organisationId < 1) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM departments WHERE id = ? AND organisation_id = ?');
    $st->execute([$departmentId, $organisationId]);

    return (bool) $st->fetchColumn();
}

/** Generate a new complaint ID candidate: 7 uppercase base36 chars (unix time + random). */
function cmc_complaint_new_reference_candidate(): string
{
    $pow5 = 36 ** 5;
    $pow2 = 36 ** 2;
    $tPart = (int) (time() % $pow5);
    $rPart = random_int(0, $pow2 - 1);
    $v = $tPart * $pow2 + $rPart;

    return strtoupper(str_pad(base_convert((string) $v, 10, 36), 7, '0', STR_PAD_LEFT));
}

function cmc_complaint_reference_code_exists(PDO $pdo, string $ref): bool
{
    $st = $pdo->prepare('SELECT 1 FROM complaints WHERE reference_code = ? LIMIT 1');
    $st->execute([$ref]);

    return (bool) $st->fetchColumn();
}

/** Allocate a unique complaint ID string (does not insert). */
function cmc_complaint_allocate_reference_code(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $ref = cmc_complaint_new_reference_candidate();
        if (!cmc_complaint_reference_code_exists($pdo, $ref)) {
            return $ref;
        }
    }

    throw new RuntimeException('Could not allocate a unique complaint ID.');
}

/**
 * Case-insensitive substring match on complaint ID, numeric row id, subject, raiser name, raiser email (requires join alias `rb`).
 *
 * @return array{0: string, 1: list<string>}
 */
function cmc_complaint_search_fragment(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['1', []];
    }
    $needle = mb_strtolower($q, 'UTF-8');
    $sql = '(
        INSTR(LOWER(COALESCE(c.reference_code, \'\')), ?) > 0
        OR INSTR(LOWER(CAST(c.id AS TEXT)), ?) > 0
        OR INSTR(LOWER(c.subject), ?) > 0
        OR INSTR(LOWER(rb.full_name), ?) > 0
        OR INSTR(LOWER(COALESCE(rb.email, \'\')), ?) > 0
    )';

    return [$sql, [$needle, $needle, $needle, $needle, $needle]];
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

/** Raiser may withdraw a complaint before the department HOD has acted (still awaiting HOD). */
function cmc_complaint_raiser_may_delete_pending_hod(array $u, array $c): bool
{
    return (int) ($c['raised_by_user_id'] ?? 0) === (int) ($u['id'] ?? 0)
        && ($c['status'] ?? '') === 'pending_hod';
}

/** Admin may delete any complaint; raiser may delete own only while {@see cmc_complaint_raiser_may_delete_pending_hod}. */
function cmc_complaint_admin_or_raiser_may_delete(array $u, array $c): bool
{
    if (($u['role'] ?? '') === 'admin') {
        return true;
    }

    return cmc_complaint_raiser_may_delete_pending_hod($u, $c);
}

/**
 * Deletes complaint if the user is allowed (admin, or raiser while still pending HOD). Verifies view access first.
 *
 * @param array<string, mixed> $u
 * @return string|null error message, or null on success
 */
function cmc_complaint_delete_if_allowed(PDO $pdo, array $u, int $complaintId): ?string
{
    $row = cmc_complaint_fetch($pdo, $complaintId);
    if ($row === null) {
        return 'Complaint not found.';
    }
    if (!cmc_complaint_user_can_view($u, $row)) {
        return 'You cannot access that complaint.';
    }
    if (!cmc_complaint_admin_or_raiser_may_delete($u, $row)) {
        return 'You cannot delete this complaint. You may withdraw it only while it is still awaiting your department HOD (before they forward or reject it).';
    }

    return cmc_complaint_admin_delete($pdo, $complaintId);
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

function cmc_complaint_delete_upload_directory(int $complaintId): void
{
    if ($complaintId < 1) {
        return;
    }
    $dir = cmc_complaint_upload_base() . '/c' . $complaintId;
    if (!is_dir($dir)) {
        return;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    } catch (Throwable $e) {
        // Best-effort cleanup; DB row is already gone.
    }
}

/**
 * Remove a complaint row and dependent DB rows. Caller must wrap in a transaction.
 * Deletes internal bills tied to fulfillments first (FK RESTRICT), then the complaint (CASCADE).
 *
 * @return string|null error message, or null on success
 */
function cmc_complaint_admin_delete_in_transaction(PDO $pdo, int $complaintId): ?string
{
    if ($complaintId < 1) {
        return 'Invalid complaint.';
    }
    $ex = $pdo->prepare('SELECT 1 FROM complaints WHERE id = ?');
    $ex->execute([$complaintId]);
    if (!$ex->fetch()) {
        return 'Complaint not found.';
    }

    $hasBillsTable = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'internal_bills' LIMIT 1"
    )->fetchColumn();

    $st = $pdo->prepare('SELECT id FROM complaint_fulfillments WHERE complaint_id = ?');
    $st->execute([$complaintId]);
    /** @var list<int> $fids */
    $fids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if ($fids !== [] && $hasBillsTable) {
        $ph = implode(',', array_fill(0, count($fids), '?'));
        $pdo->prepare("DELETE FROM internal_bills WHERE fulfillment_id IN ($ph)")->execute($fids);
    }

    $del = $pdo->prepare('DELETE FROM complaints WHERE id = ?');
    $del->execute([$complaintId]);
    $n = (int) $pdo->query('SELECT changes()')->fetchColumn();
    if ($n !== 1) {
        return 'Complaint could not be removed.';
    }

    return null;
}

/** @return string|null error message, or null on success */
function cmc_complaint_admin_delete(PDO $pdo, int $complaintId): ?string
{
    $pdo->beginTransaction();
    try {
        $err = cmc_complaint_admin_delete_in_transaction($pdo, $complaintId);
        if ($err !== null) {
            $pdo->rollBack();

            return $err;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Could not delete complaint.';
    }

    cmc_complaint_delete_upload_directory($complaintId);

    return null;
}

/**
 * @param list<mixed> $ids
 * @return array{deleted: int, error: string|null}
 */
function cmc_complaint_admin_bulk_delete(PDO $pdo, array $ids): array
{
    /** @var list<int> $unique */
    $unique = [];
    foreach ($ids as $v) {
        $n = (int) $v;
        if ($n > 0) {
            $unique[$n] = $n;
        }
    }
    $unique = array_values($unique);
    if ($unique === []) {
        return ['deleted' => 0, 'error' => 'No complaints selected.'];
    }
    if (count($unique) > 200) {
        return ['deleted' => 0, 'error' => 'Too many complaints (maximum 200 per request).'];
    }

    $pdo->beginTransaction();
    try {
        foreach ($unique as $id) {
            $err = cmc_complaint_admin_delete_in_transaction($pdo, $id);
            if ($err !== null) {
                throw new RuntimeException($err);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'deleted' => 0,
            'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not delete complaints.',
        ];
    }

    foreach ($unique as $id) {
        cmc_complaint_delete_upload_directory($id);
    }

    return ['deleted' => count($unique), 'error' => null];
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
        $cid = 0;
        for ($insTry = 0; $insTry < 12; $insTry++) {
            $ref = cmc_complaint_allocate_reference_code($pdo);
            try {
                $st = $pdo->prepare(
                    'INSERT INTO complaints (reference_code, organisation_id, department_id, raised_by_user_id, subject, details, location, contact_phone, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending_hod\')'
                );
                $st->execute([
                    $ref,
                    $orgId,
                    $deptId,
                    (int) $u['id'],
                    $subject,
                    $details,
                    $location,
                    $phoneVal,
                ]);
                $cid = (int) $pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'UNIQUE constraint failed')
                    && str_contains($msg, 'reference_code')) {
                    continue;
                }
                throw $e;
            }
        }
        if ($cid < 1) {
            $pdo->rollBack();
            return ['Could not save the complaint. Please try again.', 0];
        }

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

/** @return array<string, mixed>|null */
function cmc_complaint_fetch_by_reference(PDO $pdo, string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT c.*, o.name AS organisation_name, d.name AS department_name,
                rb.full_name AS raised_by_name, rb.email AS raised_by_email
         FROM complaints c
         JOIN organisations o ON o.id = c.organisation_id
         JOIN departments d ON d.id = c.department_id
         JOIN users rb ON rb.id = c.raised_by_user_id
         WHERE UPPER(c.reference_code) = UPPER(?)'
    );
    $st->execute([$ref]);
    $row = $st->fetch();
    return $row ?: null;
}
