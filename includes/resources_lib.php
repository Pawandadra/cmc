<?php

declare(strict_types=1);

/** @return array<string, string> */
function cmc_resource_unit_choices(): array
{
    return [
        'ea' => 'Each (ea)',
        'pcs' => 'Pieces (pcs)',
        'nos' => 'Numbers (nos)',
        'kg' => 'Kilogram (kg)',
        'g' => 'Gram (g)',
        't' => 'Metric tonne (t)',
        'm' => 'Metre (m)',
        'cm' => 'Centimetre (cm)',
        'mm' => 'Millimetre (mm)',
        'sqm' => 'Square metre (m²)',
        'cum' => 'Cubic metre (m³)',
        'L' => 'Litre (L)',
        'ml' => 'Millilitre (ml)',
        'box' => 'Box',
        'bag' => 'Bag',
        'roll' => 'Roll',
        'drum' => 'Drum / barrel',
        'pair' => 'Pair',
        'set' => 'Set',
        'bundle' => 'Bundle',
        'day' => 'Day (day)',
        'hr' => 'Hour (hr)',
        'shift' => 'Shift (shift)',
    ];
}

/** @return array<string, string> */
function cmc_resource_unit_options_for_form(?string $currentUnit): array
{
    $choices = cmc_resource_unit_choices();
    $cur = trim((string) $currentUnit);
    if ($cur !== '' && !array_key_exists($cur, $choices)) {
        return [$cur => $cur . ' (existing)'] + $choices;
    }
    return $choices;
}

function cmc_resource_unit_is_allowed(string $posted, ?string $existingStored = null): bool
{
    if (array_key_exists($posted, cmc_resource_unit_choices())) {
        return true;
    }
    return $existingStored !== null && $posted === $existingStored;
}

function cmc_resource_kind_label(string $k): string
{
    return match ($k) {
        'worker' => 'Workers',
        'equipment' => 'Equipment',
        'material' => 'Material',
        default => 'Material',
    };
}

function cmc_resource_kind_is_pool(string $k): bool
{
    return $k === 'worker' || $k === 'equipment';
}

function cmc_resource_format_qty(float $q): string
{
    if (abs($q - round($q)) < 1e-9) {
        return (string) (int) round($q);
    }
    $s = sprintf('%.4f', $q);
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

function cmc_resource_format_money(float $v): string
{
    return number_format($v, 2, '.', ',');
}

/** @return array<string, mixed>|null */
function cmc_resource_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** @return list<array<string, mixed>> */
function cmc_resource_movements(PDO $pdo, int $itemId, int $limit = 100): array
{
    $lim = max(1, min(500, $limit));
    $st = $pdo->prepare(
        "SELECT m.*, u.full_name AS actor_name
         FROM inventory_movements m
         JOIN users u ON u.id = m.actor_user_id
         WHERE m.item_id = ?
         ORDER BY m.id DESC
         LIMIT {$lim}"
    );
    $st->execute([$itemId]);
    return $st->fetchAll();
}

/** @return string|null */
function cmc_resource_apply_delta(PDO $pdo, int $itemId, int $actorId, float $delta, string $note): ?string
{
    if (abs($delta) < 1e-12) {
        return 'Enter a non-zero quantity change.';
    }
    if (strlen($note) < 3) {
        return 'Add a short note (at least 3 characters) explaining the change.';
    }
    if (strlen($note) > 2000) {
        return 'Note is too long.';
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT quantity, resource_kind FROM inventory_items WHERE id = ?');
        $st->execute([$itemId]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->rollBack();
            return 'Resource not found.';
        }
        $current = (float) $row['quantity'];
        $next = $current + $delta;
        if ($next < -1e-9) {
            $pdo->rollBack();
            return 'Available quantity cannot go below zero.';
        }

        $up = $pdo->prepare('UPDATE inventory_items SET quantity = ?, updated_at = datetime(\'now\') WHERE id = ?');
        $up->execute([$next, $itemId]);

        $pdo->prepare(
            'INSERT INTO inventory_movements (item_id, actor_user_id, quantity_delta, balance_after, note)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$itemId, $actorId, $delta, $next, $note]);

        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 'Could not update quantity.';
    }
}

/** @return string|null error message */
function cmc_resource_assign_pool(PDO $pdo, int $fulfillmentId, int $resourceId, float $qty, int $actorId): ?string
{
    if ($qty <= 0 || !is_finite($qty)) {
        return 'Enter a positive quantity to assign.';
    }

    $w = cmc_resource_fetch($pdo, $resourceId);
    $kind = (string) ($w['resource_kind'] ?? 'material');
    if (!$w || !cmc_resource_kind_is_pool($kind)) {
        return 'Choose a worker or equipment resource.';
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'UPDATE inventory_items SET quantity = quantity - ?, updated_at = datetime(\'now\')
             WHERE id = ? AND resource_kind IN (\'worker\', \'equipment\') AND quantity + 1e-9 >= ?'
        );
        $st->execute([$qty, $resourceId, $qty]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return 'Insufficient quantity available.';
        }

        $ex = $pdo->prepare(
            'SELECT id, assigned_quantity FROM resource_assignments
             WHERE fulfillment_id = ? AND resource_id = ? AND status = \'active\' LIMIT 1'
        );
        $ex->execute([$fulfillmentId, $resourceId]);
        $row = $ex->fetch();
        if ($row) {
            $pdo->prepare(
                'UPDATE resource_assignments SET assigned_quantity = assigned_quantity + ? WHERE id = ?'
            )->execute([$qty, (int) $row['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO resource_assignments (fulfillment_id, resource_id, assigned_quantity, status)
                 VALUES (?, ?, ?, \'active\')'
            )->execute([$fulfillmentId, $resourceId, $qty]);
        }

        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 'Could not create assignment.';
    }
}

/** @deprecated use cmc_resource_assign_pool */
function cmc_resource_assign_worker(PDO $pdo, int $fulfillmentId, int $workerResourceId, float $qty, int $actorId): ?string
{
    return cmc_resource_assign_pool($pdo, $fulfillmentId, $workerResourceId, $qty, $actorId);
}

/** @return string|null */
function cmc_resource_release_assignment(PDO $pdo, int $assignmentId): ?string
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, resource_id, assigned_quantity, status FROM resource_assignments WHERE id = ?'
        );
        $st->execute([$assignmentId]);
        $a = $st->fetch();
        if (!$a || ($a['status'] ?? '') !== 'active') {
            $pdo->rollBack();
            return 'Assignment not found or already released.';
        }
        $qty = (float) $a['assigned_quantity'];
        $rid = (int) $a['resource_id'];

        $pdo->prepare(
            'UPDATE resource_assignments SET status = \'released\', released_at = datetime(\'now\') WHERE id = ?'
        )->execute([$assignmentId]);

        $pdo->prepare(
            'UPDATE inventory_items SET quantity = quantity + ?, updated_at = datetime(\'now\') WHERE id = ?'
        )->execute([$qty, $rid]);

        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 'Could not release assignment.';
    }
}

/** @return list<array<string, mixed>> */
function cmc_resource_assignments_for_fulfillment(PDO $pdo, int $fulfillmentId): array
{
    $st = $pdo->prepare(
        'SELECT a.*, r.name AS resource_name, r.unit AS resource_unit, r.resource_kind AS resource_kind
         FROM resource_assignments a
         JOIN inventory_items r ON r.id = a.resource_id
         WHERE a.fulfillment_id = ?
         ORDER BY a.status ASC, a.id DESC'
    );
    $st->execute([$fulfillmentId]);
    return $st->fetchAll();
}

/** @deprecated use cmc_resource_format_qty */
function cmc_inventory_format_qty(float $q): string
{
    return cmc_resource_format_qty($q);
}
