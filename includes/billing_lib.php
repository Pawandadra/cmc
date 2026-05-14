<?php

declare(strict_types=1);

/** @return array{material: float, labour: float, equipment: float} */
function cmc_billing_totals_for_fulfillment(PDO $pdo, int $fulfillmentId): array
{
    $mat = 0.0;
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(l.quantity * COALESCE(i.unit_rate, 0)), 0) AS s
         FROM complaint_fulfillment_lines l
         JOIN inventory_items i ON i.id = l.inventory_item_id
         WHERE l.fulfillment_id = ? AND (i.resource_kind IS NULL OR i.resource_kind = \'\' OR i.resource_kind = \'material\')'
    );
    $st->execute([$fulfillmentId]);
    $mat = (float) $st->fetchColumn();

    $lab = 0.0;
    $st2 = $pdo->prepare(
        'SELECT COALESCE(SUM(a.assigned_quantity * COALESCE(i.unit_rate, 0)), 0) AS s
         FROM resource_assignments a
         JOIN inventory_items i ON i.id = a.resource_id
         WHERE a.fulfillment_id = ? AND a.status = \'released\' AND i.resource_kind = \'worker\''
    );
    $st2->execute([$fulfillmentId]);
    $lab = (float) $st2->fetchColumn();

    $equip = 0.0;
    $st3 = $pdo->prepare(
        'SELECT COALESCE(SUM(a.assigned_quantity * COALESCE(i.unit_rate, 0)), 0) AS s
         FROM resource_assignments a
         JOIN inventory_items i ON i.id = a.resource_id
         WHERE a.fulfillment_id = ? AND a.status = \'released\' AND i.resource_kind = \'equipment\''
    );
    $st3->execute([$fulfillmentId]);
    $equip = (float) $st3->fetchColumn();

    return ['material' => $mat, 'labour' => $lab, 'equipment' => $equip];
}

/**
 * @param list<array{label: string, amount: float}> $otherLines
 * @return int bill id
 */
function cmc_billing_create_record(
    PDO $pdo,
    int $fulfillmentId,
    int $actorUserId,
    float $wageAdjustment,
    array $otherLines,
    ?string $notes
): int {
    $t = cmc_billing_totals_for_fulfillment($pdo, $fulfillmentId);
    $otherSum = 0.0;
    foreach ($otherLines as $ol) {
        $otherSum += max(0.0, (float) ($ol['amount'] ?? 0));
    }
    $grand = $t['material'] + $t['labour'] + $t['equipment'] + $wageAdjustment + $otherSum;

    $pdo->prepare(
        'INSERT INTO internal_bills (fulfillment_id, material_subtotal, labour_subtotal, equipment_subtotal, wage_adjustment, other_expenses, grand_total, notes, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $fulfillmentId,
        $t['material'],
        $t['labour'],
        $t['equipment'],
        $wageAdjustment,
        $otherSum,
        $grand,
        $notes === null || $notes === '' ? null : $notes,
        $actorUserId,
    ]);
    $bid = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        'INSERT INTO internal_bill_lines (bill_id, line_type, label, quantity, unit_rate, line_total, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ord = 0;

    $stL = $pdo->prepare(
        'SELECT l.quantity, i.name, i.unit_rate, i.unit
         FROM complaint_fulfillment_lines l
         JOIN inventory_items i ON i.id = l.inventory_item_id
         WHERE l.fulfillment_id = ? AND (i.resource_kind IS NULL OR i.resource_kind = \'\' OR i.resource_kind = \'material\')'
    );
    $stL->execute([$fulfillmentId]);
    foreach ($stL->fetchAll() as $row) {
        $q = (float) $row['quantity'];
        $rate = (float) ($row['unit_rate'] ?? 0);
        $total = $q * $rate;
        $lbl = (string) $row['name'] . ' (' . (string) $row['unit'] . ')';
        $ins->execute([$bid, 'material', $lbl, $q, $rate, $total, $ord++]);
    }

    $stA = $pdo->prepare(
        'SELECT a.assigned_quantity, i.name, i.unit_rate, i.unit
         FROM resource_assignments a
         JOIN inventory_items i ON i.id = a.resource_id
         WHERE a.fulfillment_id = ? AND a.status = \'released\' AND i.resource_kind = \'worker\''
    );
    $stA->execute([$fulfillmentId]);
    foreach ($stA->fetchAll() as $row) {
        $q = (float) $row['assigned_quantity'];
        $rate = (float) ($row['unit_rate'] ?? 0);
        $total = $q * $rate;
        $lbl = (string) $row['name'] . ' · ' . (string) $row['unit'];
        $ins->execute([$bid, 'labour', $lbl, $q, $rate, $total, $ord++]);
    }

    $stE = $pdo->prepare(
        'SELECT a.assigned_quantity, i.name, i.unit_rate, i.unit
         FROM resource_assignments a
         JOIN inventory_items i ON i.id = a.resource_id
         WHERE a.fulfillment_id = ? AND a.status = \'released\' AND i.resource_kind = \'equipment\''
    );
    $stE->execute([$fulfillmentId]);
    foreach ($stE->fetchAll() as $row) {
        $q = (float) $row['assigned_quantity'];
        $rate = (float) ($row['unit_rate'] ?? 0);
        $total = $q * $rate;
        $lbl = (string) $row['name'] . ' · ' . (string) $row['unit'];
        $ins->execute([$bid, 'equipment', $lbl, $q, $rate, $total, $ord++]);
    }

    if (abs($wageAdjustment) > 1e-9) {
        $lbl = $wageAdjustment >= 0 ? 'Wage / labour adjustment (+)' : 'Wage / labour adjustment (−)';
        $ins->execute([$bid, 'labour', $lbl, 1, $wageAdjustment, $wageAdjustment, $ord++]);
    }

    foreach ($otherLines as $ol) {
        $amt = max(0.0, (float) ($ol['amount'] ?? 0));
        if ($amt < 1e-9) {
            continue;
        }
        $lbl = trim((string) ($ol['label'] ?? 'Other expense'));
        if ($lbl === '') {
            $lbl = 'Other expense';
        }
        $ins->execute([$bid, 'other', $lbl, 1, $amt, $amt, $ord++]);
    }

    return $bid;
}

/**
 * Delete an internal bill and its line items (SDE). Fulfillment row is unchanged.
 *
 * @return string|null error message, or null on success
 */
function cmc_billing_delete_internal_bill(PDO $pdo, int $billId): ?string
{
    if ($billId < 1) {
        return 'Invalid bill.';
    }
    $ex = $pdo->prepare('SELECT 1 FROM internal_bills WHERE id = ?');
    $ex->execute([$billId]);
    if (!$ex->fetch()) {
        return 'Bill not found.';
    }

    $pdo->prepare('DELETE FROM internal_bills WHERE id = ?')->execute([$billId]);
    $n = (int) $pdo->query('SELECT changes()')->fetchColumn();
    if ($n !== 1) {
        return 'Could not delete bill.';
    }

    return null;
}
