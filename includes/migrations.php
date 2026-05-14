<?php

declare(strict_types=1);

/**
 * Incremental migrations for existing SQLite files (schema.sql only runs on first create).
 */
function cmc_run_migrations(PDO $pdo): void
{
    // User-table rebuild must run before complaint tables reference `users`.
    cmc_migration_sdc_to_sde_users($pdo);
    cmc_migration_complaints_tables($pdo);
    cmc_migration_complaints_reference_code($pdo);
    cmc_migration_inventory_tables($pdo);
    cmc_migration_inventory_drop_location_column($pdo);
    cmc_migration_complaint_fulfillment_tables($pdo);
    cmc_migration_complaint_fulfillment_lines_table($pdo);
    cmc_migration_complaint_fulfillments_drop_text_columns($pdo);
    cmc_migration_resources_columns($pdo);
    cmc_migration_resource_assignments_table($pdo);
    cmc_migration_internal_billing_tables($pdo);
    cmc_migration_internal_bills_equipment_subtotal($pdo);
    cmc_migration_internal_bill_lines_equipment_line_type($pdo);
}

function cmc_migration_complaint_fulfillment_tables(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaint_fulfillments'")->fetch();
    if ($row) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE complaint_fulfillments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    complaint_id INTEGER NOT NULL UNIQUE REFERENCES complaints(id) ON DELETE CASCADE,
    sde_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    notes TEXT,
    work_status TEXT NOT NULL DEFAULT 'planning' CHECK (work_status IN ('planning', 'in_progress', 'on_hold', 'completed', 'cancelled')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_complaint_fulfillments_status ON complaint_fulfillments (work_status);
CREATE INDEX idx_complaint_fulfillments_complaint ON complaint_fulfillments (complaint_id);
SQL
    );
}

function cmc_migration_complaint_fulfillment_lines_table(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaint_fulfillment_lines'")->fetch();
    if ($row) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE complaint_fulfillment_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL REFERENCES complaint_fulfillments(id) ON DELETE CASCADE,
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
    quantity REAL NOT NULL CHECK (quantity > 0),
    UNIQUE (fulfillment_id, inventory_item_id)
);

CREATE INDEX idx_complaint_fulfillment_lines_f ON complaint_fulfillment_lines (fulfillment_id);
SQL
    );
}

function cmc_migration_complaint_fulfillments_drop_text_columns(PDO $pdo): void
{
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaint_fulfillments'")->fetch();
    if (!$ex) {
        return;
    }
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(complaint_fulfillments)') as $col) {
        $cols[] = (string) ($col['name'] ?? '');
    }
    foreach (['resource_allocation', 'worker_allocation'] as $drop) {
        if (!in_array($drop, $cols, true)) {
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE complaint_fulfillments DROP COLUMN ' . $drop);
        } catch (Throwable $e) {
            // SQLite < 3.35 or other restriction
        }
    }
}

function cmc_migration_inventory_tables(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='inventory_items'")->fetch();
    if ($row) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE inventory_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    item_code TEXT UNIQUE,
    description TEXT,
    unit TEXT NOT NULL DEFAULT 'ea',
    quantity REAL NOT NULL DEFAULT 0 CHECK (quantity >= -1e-9),
    resource_kind TEXT NOT NULL DEFAULT 'material',
    unit_rate REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    quantity_delta REAL NOT NULL,
    balance_after REAL NOT NULL CHECK (balance_after >= -1e-9),
    note TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_inventory_movements_item ON inventory_movements (item_id);
CREATE INDEX idx_inventory_items_name ON inventory_items (name);
SQL
    );
}

function cmc_migration_inventory_drop_location_column(PDO $pdo): void
{
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='inventory_items'")->fetch();
    if (!$ex) {
        return;
    }
    $hasLoc = false;
    foreach ($pdo->query('PRAGMA table_info(inventory_items)') as $col) {
        if (($col['name'] ?? '') === 'location') {
            $hasLoc = true;
            break;
        }
    }
    if (!$hasLoc) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE inventory_items DROP COLUMN location');
    } catch (Throwable $e) {
        // SQLite < 3.35: column remains unused.
    }
}

function cmc_migration_complaints_tables(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaints'")->fetch();
    if ($row) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE complaints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE RESTRICT,
    raised_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    subject TEXT NOT NULL,
    details TEXT NOT NULL,
    location TEXT NOT NULL,
    priority TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high')),
    contact_phone TEXT,
    status TEXT NOT NULL CHECK (status IN ('pending_hod', 'hod_rejected', 'pending_sde', 'sde_approved', 'sde_rejected')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE complaint_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    complaint_id INTEGER NOT NULL REFERENCES complaints(id) ON DELETE CASCADE,
    stored_name TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE complaint_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    complaint_id INTEGER NOT NULL REFERENCES complaints(id) ON DELETE CASCADE,
    actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    event_type TEXT NOT NULL CHECK (event_type IN ('submitted', 'hod_forwarded', 'hod_rejected', 'sde_approved', 'sde_rejected')),
    comment TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_complaints_dept_status ON complaints (department_id, status);
CREATE INDEX idx_complaints_raiser ON complaints (raised_by_user_id);
CREATE INDEX idx_complaints_status ON complaints (status);
CREATE INDEX idx_complaint_events_complaint ON complaint_events (complaint_id);
SQL
    );
}

function cmc_migration_complaints_reference_code(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='complaints'")->fetch();
    if (!$row) {
        return;
    }
    $cols = $pdo->query('PRAGMA table_info(complaints)')->fetchAll(PDO::FETCH_ASSOC);
    $hasRef = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'reference_code') {
            $hasRef = true;
            break;
        }
    }
    if (!$hasRef) {
        $pdo->exec('ALTER TABLE complaints ADD COLUMN reference_code TEXT');
    }

    $pdo->beginTransaction();
    try {
        $ids = $pdo->query(
            "SELECT id FROM complaints WHERE reference_code IS NULL OR TRIM(COALESCE(reference_code, '')) = ''"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $rid) {
            $rid = (int) $rid;
            $code = cmc_complaint_allocate_reference_code($pdo);
            $pdo->prepare('UPDATE complaints SET reference_code = ? WHERE id = ?')->execute([$code, $rid]);
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_complaints_reference_code ON complaints (reference_code)');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function cmc_migration_sdc_to_sde_users(PDO $pdo): void
{
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!is_string($sql) || !str_contains($sql, "'sdc'")) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('ALTER TABLE users RENAME TO users_old');

        $pdo->exec(
            <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'sde', 'hod', 'member')),
    organisation_id INTEGER REFERENCES organisations(id) ON DELETE RESTRICT,
    department_id INTEGER REFERENCES departments(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (
        (role = 'admin' AND organisation_id IS NULL AND department_id IS NULL)
        OR (role = 'sde' AND organisation_id IS NULL AND department_id IS NULL)
        OR (role IN ('hod', 'member') AND organisation_id IS NOT NULL AND department_id IS NOT NULL)
    )
);
SQL
        );

        $pdo->exec(
            "INSERT INTO users (id, email, password_hash, full_name, role, organisation_id, department_id, created_at)
             SELECT id, email, password_hash, full_name,
                    CASE WHEN role = 'sdc' THEN 'sde' ELSE role END,
                    organisation_id, department_id, created_at
             FROM users_old"
        );

        $pdo->exec('DROP TABLE users_old');

        $pdo->exec(
            <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_one_hod_per_department
ON users (department_id)
WHERE role = 'hod';

CREATE INDEX IF NOT EXISTS idx_users_org ON users (organisation_id);
CREATE INDEX IF NOT EXISTS idx_users_dept ON users (department_id);
SQL
        );

        $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM users')->fetchColumn();
        if ($maxId > 0) {
            $pdo->exec('DELETE FROM sqlite_sequence WHERE name = \'users\'');
            $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (\'users\', ?)')->execute([$maxId]);
        }
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function cmc_migration_resources_columns(PDO $pdo): void
{
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='inventory_items'")->fetch();
    if (!$ex) {
        return;
    }
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(inventory_items)') as $col) {
        $cols[] = (string) ($col['name'] ?? '');
    }
    if (!in_array('resource_kind', $cols, true)) {
        $pdo->exec("ALTER TABLE inventory_items ADD COLUMN resource_kind TEXT NOT NULL DEFAULT 'material'");
    }
    if (!in_array('unit_rate', $cols, true)) {
        $pdo->exec('ALTER TABLE inventory_items ADD COLUMN unit_rate REAL NOT NULL DEFAULT 0');
    }
}

function cmc_migration_resource_assignments_table(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='resource_assignments'")->fetch();
    if ($row) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE resource_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL REFERENCES complaint_fulfillments(id) ON DELETE CASCADE,
    resource_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
    assigned_quantity REAL NOT NULL CHECK (assigned_quantity > 0),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'released')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    released_at TEXT
);

CREATE UNIQUE INDEX idx_resource_assign_one_active ON resource_assignments (fulfillment_id, resource_id)
WHERE status = 'active';

CREATE INDEX idx_resource_assign_fulfillment ON resource_assignments (fulfillment_id);
SQL
    );
}

function cmc_migration_internal_billing_tables(PDO $pdo): void
{
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='internal_bills'")->fetch();
    if ($row) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE internal_bills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL REFERENCES complaint_fulfillments(id) ON DELETE RESTRICT,
    material_subtotal REAL NOT NULL DEFAULT 0,
    labour_subtotal REAL NOT NULL DEFAULT 0,
    equipment_subtotal REAL NOT NULL DEFAULT 0,
    wage_adjustment REAL NOT NULL DEFAULT 0,
    other_expenses REAL NOT NULL DEFAULT 0,
    grand_total REAL NOT NULL DEFAULT 0,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    created_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_internal_bills_fulfillment ON internal_bills (fulfillment_id);

CREATE TABLE internal_bill_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bill_id INTEGER NOT NULL REFERENCES internal_bills(id) ON DELETE CASCADE,
    line_type TEXT NOT NULL CHECK (line_type IN ('material', 'labour', 'equipment', 'other')),
    label TEXT NOT NULL,
    quantity REAL NOT NULL DEFAULT 1,
    unit_rate REAL NOT NULL DEFAULT 0,
    line_total REAL NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_internal_bill_lines_bill ON internal_bill_lines (bill_id);
SQL
    );
}

function cmc_migration_internal_bills_equipment_subtotal(PDO $pdo): void
{
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='internal_bills'")->fetch();
    if (!$ex) {
        return;
    }
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(internal_bills)') as $col) {
        $cols[] = (string) ($col['name'] ?? '');
    }
    if (!in_array('equipment_subtotal', $cols, true)) {
        $pdo->exec('ALTER TABLE internal_bills ADD COLUMN equipment_subtotal REAL NOT NULL DEFAULT 0');
    }
}

function cmc_migration_internal_bill_lines_equipment_line_type(PDO $pdo): void
{
    $ex = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='internal_bill_lines'")->fetch();
    if (!$ex) {
        return;
    }
    $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='internal_bill_lines'")->fetchColumn();
    if ($sql !== '' && str_contains($sql, "'equipment'")) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE internal_bill_lines RENAME TO internal_bill_lines_old');
        $pdo->exec(
            <<<'SQL'
CREATE TABLE internal_bill_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bill_id INTEGER NOT NULL REFERENCES internal_bills(id) ON DELETE CASCADE,
    line_type TEXT NOT NULL CHECK (line_type IN ('material', 'labour', 'equipment', 'other')),
    label TEXT NOT NULL,
    quantity REAL NOT NULL DEFAULT 1,
    unit_rate REAL NOT NULL DEFAULT 0,
    line_total REAL NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0
);
SQL
        );
        $pdo->exec(
            'INSERT INTO internal_bill_lines (id, bill_id, line_type, label, quantity, unit_rate, line_total, sort_order)
             SELECT id, bill_id, line_type, label, quantity, unit_rate, line_total, sort_order FROM internal_bill_lines_old'
        );
        $pdo->exec('DROP TABLE internal_bill_lines_old');
        $pdo->exec('CREATE INDEX idx_internal_bill_lines_bill ON internal_bill_lines (bill_id)');
        $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM internal_bill_lines')->fetchColumn();
        if ($maxId > 0) {
            $pdo->exec('DELETE FROM sqlite_sequence WHERE name = \'internal_bill_lines\'');
            $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (\'internal_bill_lines\', ?)')->execute([$maxId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
