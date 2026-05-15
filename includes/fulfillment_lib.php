<?php

declare(strict_types=1);

function cmc_fulfillment_work_status_label(string $s): string
{
    return match ($s) {
        'planning' => 'Planning',
        'in_progress' => 'In progress',
        'on_hold' => 'On hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => $s,
    };
}

/** @return array<string, mixed>|null */
function cmc_fulfillment_by_complaint(PDO $pdo, int $complaintId): ?array
{
    $st = $pdo->prepare('SELECT * FROM complaint_fulfillments WHERE complaint_id = ? LIMIT 1');
    $st->execute([$complaintId]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Create a fulfillment row for this complaint if missing; return fulfillment id. */
function cmc_fulfillment_get_or_create(PDO $pdo, int $complaintId, int $sdeUserId): int
{
    $f = cmc_fulfillment_by_complaint($pdo, $complaintId);
    if ($f) {
        return (int) $f['id'];
    }
    $pdo->prepare(
        'INSERT INTO complaint_fulfillments (complaint_id, sde_user_id, notes, work_status) VALUES (?, ?, NULL, ?)'
    )->execute([$complaintId, $sdeUserId, 'planning']);

    return (int) $pdo->lastInsertId();
}

/** @return list<string> */
function cmc_fulfillment_work_statuses(): array
{
    return ['planning', 'in_progress', 'on_hold', 'completed', 'cancelled'];
}

function cmc_fulfillment_work_status_locked(string $status): bool
{
    return $status === 'completed';
}

/** @return string|null error message */
function cmc_fulfillment_set_work_status(PDO $pdo, int $complaintId, int $sdeUserId, string $workStatus): ?string
{
    if (!in_array($workStatus, cmc_fulfillment_work_statuses(), true)) {
        return 'Invalid work status.';
    }
    $existing = cmc_fulfillment_by_complaint($pdo, $complaintId);
    if ($existing !== null && cmc_fulfillment_work_status_locked((string) ($existing['work_status'] ?? ''))) {
        return 'This fulfillment is complete and cannot be reopened or changed.';
    }
    $fid = cmc_fulfillment_get_or_create($pdo, $complaintId, $sdeUserId);
    $pdo->prepare(
        'UPDATE complaint_fulfillments SET work_status = ?, updated_at = datetime(\'now\') WHERE id = ?'
    )->execute([$workStatus, $fid]);

    return null;
}

/** @return list<array<string, mixed>> */
function cmc_fulfillment_lines(PDO $pdo, int $fulfillmentId): array
{
    $st = $pdo->prepare(
        'SELECT l.id, l.inventory_item_id, l.quantity, i.name AS item_name, i.unit AS item_unit, i.item_code, i.unit_rate
         FROM complaint_fulfillment_lines l
         JOIN inventory_items i ON i.id = l.inventory_item_id
         WHERE l.fulfillment_id = ?
         ORDER BY i.name COLLATE NOCASE'
    );
    $st->execute([$fulfillmentId]);
    return $st->fetchAll();
}

/**
 * @param array<int, float> $itemQuantities inventory_item_id => quantity (> 0)
 * @return string|null error message
 */
function cmc_fulfillment_replace_lines(PDO $pdo, int $fulfillmentId, array $itemQuantities): ?string
{
    foreach ($itemQuantities as $itemId => $qty) {
        if ($itemId < 1 || $qty <= 0 || !is_finite($qty)) {
            return 'Each resource line needs a valid item and a quantity greater than zero.';
        }
        $st = $pdo->prepare('SELECT resource_kind FROM inventory_items WHERE id = ?');
        $st->execute([$itemId]);
        $row = $st->fetch();
        if (!$row) {
            return 'One of the selected resources no longer exists.';
        }
        $kind = (string) ($row['resource_kind'] ?? 'material');
        if ($kind !== 'material') {
            return 'Fulfillment lines are for materials only. Use assignments for equipment and workers.';
        }
    }

    try {
        // Caller (e.g. fulfillment/work.php) owns the transaction; do not nest beginTransaction here.
        $pdo->prepare('DELETE FROM complaint_fulfillment_lines WHERE fulfillment_id = ?')->execute([$fulfillmentId]);
        $ins = $pdo->prepare(
            'INSERT INTO complaint_fulfillment_lines (fulfillment_id, inventory_item_id, quantity) VALUES (?, ?, ?)'
        );
        foreach ($itemQuantities as $itemId => $qty) {
            $ins->execute([$fulfillmentId, $itemId, $qty]);
        }
        return null;
    } catch (Throwable $e) {
        return 'Could not save resource lines (duplicate item?).';
    }
}

/**
 * @param array<mixed> $itemIds POST line_item[]
 * @param array<mixed> $qtys POST line_qty[]
 * @return array{0: ?string, 1: array<int, float>}
 */
function cmc_fulfillment_parse_lines_from_post(array $itemIds, array $qtys): array
{
    if (!is_array($itemIds) || !is_array($qtys)) {
        return ['Invalid form data.', []];
    }
    $n = max(count($itemIds), count($qtys));
    /** @var array<int, float> $out */
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $iid = isset($itemIds[$i]) ? (int) $itemIds[$i] : 0;
        $qraw = isset($qtys[$i]) ? trim((string) $qtys[$i]) : '';
        if ($iid < 1 && $qraw === '') {
            continue;
        }
        if ($iid < 1) {
            return ['Select a resource and quantity for each line.', []];
        }
        if ($qraw === '' || !is_numeric($qraw)) {
            return ['Enter a quantity for each selected resource.', []];
        }
        $q = (float) $qraw;
        if ($q <= 0 || !is_finite($q) || $q > 1e12) {
            return ['Quantities must be positive numbers.', []];
        }
        $out[$iid] = ($out[$iid] ?? 0) + $q;
    }
    return [null, $out];
}
