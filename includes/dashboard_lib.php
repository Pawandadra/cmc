<?php

declare(strict_types=1);

/**
 * Workflow counts for complaints matching a single equality filter on complaints.
 *
 * @param 'raised_by_user_id'|'department_id' $column
 * @return array{
 *   total: int,
 *   by_status: array<string, int>,
 *   fulfilled_completed: int,
 *   approved_with_open_fulfillment: int
 * }
 */
function cmc_dashboard_complaint_workflow_stats(PDO $pdo, string $column, int $id): array
{
    if ($column !== 'raised_by_user_id' && $column !== 'department_id') {
        throw new InvalidArgumentException('Invalid scope column.');
    }

    $byStatus = array_fill_keys(cmc_complaint_statuses_all(), 0);
    $st = $pdo->prepare("SELECT status, COUNT(*) AS n FROM complaints WHERE {$column} = ? GROUP BY status");
    $st->execute([$id]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $s = (string) ($row['status'] ?? '');
        if (isset($byStatus[$s])) {
            $byStatus[$s] = (int) $row['n'];
        }
    }

    $total = array_sum($byStatus);

    $fulfilled = 0;
    $exF = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaint_fulfillments'")->fetch();
    if ($exF) {
        $q = "SELECT COUNT(DISTINCT c.id) FROM complaints c
              INNER JOIN complaint_fulfillments f ON f.complaint_id = c.id
              WHERE c.{$column} = ? AND f.work_status = 'completed'";
        $st = $pdo->prepare($q);
        $st->execute([$id]);
        $fulfilled = (int) $st->fetchColumn();

        $q2 = "SELECT COUNT(DISTINCT c.id) FROM complaints c
               LEFT JOIN complaint_fulfillments f ON f.complaint_id = c.id
               WHERE c.{$column} = ? AND c.status = 'sde_approved'
                 AND (f.id IS NULL OR f.work_status NOT IN ('completed', 'cancelled'))";
        $st2 = $pdo->prepare($q2);
        $st2->execute([$id]);
        $openFul = (int) $st2->fetchColumn();
    } else {
        $openFul = (int) $byStatus['sde_approved'];
    }

    return [
        'total' => $total,
        'by_status' => $byStatus,
        'fulfilled_completed' => $fulfilled,
        'approved_with_open_fulfillment' => $exF ? $openFul : (int) $byStatus['sde_approved'],
    ];
}

/**
 * For SDE queue: complaints in cell-visible statuses.
 *
 * @return array{total: int, by_status: array<string, int>}
 */
function cmc_dashboard_sde_queue_stats(PDO $pdo): array
{
    $byStatus = [
        'pending_sde' => 0,
        'sde_approved' => 0,
        'sde_rejected' => 0,
    ];
    $st = $pdo->query(
        "SELECT status, COUNT(*) AS n FROM complaints
         WHERE status IN ('pending_sde', 'sde_approved', 'sde_rejected')
         GROUP BY status"
    );
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $s = (string) ($row['status'] ?? '');
        if (isset($byStatus[$s])) {
            $byStatus[$s] = (int) $row['n'];
        }
    }

    return [
        'total' => array_sum($byStatus),
        'by_status' => $byStatus,
    ];
}

/**
 * @return array{total: int, by_status: array<string, int>}
 */
function cmc_dashboard_admin_complaint_stats(PDO $pdo): array
{
    $byStatus = array_fill_keys(cmc_complaint_statuses_all(), 0);
    $st = $pdo->query('SELECT status, COUNT(*) AS n FROM complaints GROUP BY status');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $s = (string) ($row['status'] ?? '');
        if (isset($byStatus[$s])) {
            $byStatus[$s] = (int) $row['n'];
        }
    }

    return [
        'total' => array_sum($byStatus),
        'by_status' => $byStatus,
    ];
}

/**
 * Fulfillment work_status counts for complaints in scope that are SDE-approved.
 *
 * @param 'raised_by_user_id'|'department_id' $column
 * @return array<string, int>
 */
function cmc_dashboard_fulfillment_mix_for_scope(PDO $pdo, string $column, int $id): array
{
    if ($column !== 'raised_by_user_id' && $column !== 'department_id') {
        throw new InvalidArgumentException('Invalid scope column.');
    }
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaint_fulfillments'")->fetch();
    if (!$ex) {
        return [];
    }

    $out = array_fill_keys(['pending_setup', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'], 0);

    $sql = "SELECT CASE WHEN f.id IS NULL THEN 'pending_setup' ELSE f.work_status END AS bucket, COUNT(*) AS n
            FROM complaints c
            LEFT JOIN complaint_fulfillments f ON f.complaint_id = c.id
            WHERE c.{$column} = ? AND c.status = 'sde_approved'
            GROUP BY 1";
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $b = (string) ($row['bucket'] ?? '');
        if (isset($out[$b])) {
            $out[$b] = (int) $row['n'];
        }
    }

    $ordered = [];
    foreach (['pending_setup', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'] as $k) {
        if (($out[$k] ?? 0) > 0) {
            $ordered[$k] = $out[$k];
        }
    }

    return $ordered;
}

/**
 * @param array{sql: string, params: list<mixed>} $where complaints alias `c`
 * @return list<array<string, mixed>>
 */
function cmc_dashboard_recent_complaints(PDO $pdo, string $whereSql, array $whereParams, int $limit = 8): array
{
    $limit = max(1, min(25, $limit));
    $sql = "SELECT c.id, c.reference_code, c.subject, c.status, c.created_at, c.updated_at,
                   rb.full_name AS raised_by_name,
                   d.name AS department_name
            FROM complaints c
            JOIN users rb ON rb.id = c.raised_by_user_id
            JOIN departments d ON d.id = c.department_id
            WHERE {$whereSql}
            ORDER BY c.updated_at DESC, c.id DESC
            LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($whereParams);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
