CREATE TABLE organisations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (organisation_id, name)
);

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

CREATE UNIQUE INDEX idx_one_hod_per_department
ON users (department_id)
WHERE role = 'hod';

CREATE INDEX idx_users_org ON users (organisation_id);
CREATE INDEX idx_users_dept ON users (department_id);
CREATE INDEX idx_departments_org ON departments (organisation_id);

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

CREATE TABLE complaint_fulfillment_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fulfillment_id INTEGER NOT NULL REFERENCES complaint_fulfillments(id) ON DELETE CASCADE,
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
    quantity REAL NOT NULL CHECK (quantity > 0),
    UNIQUE (fulfillment_id, inventory_item_id)
);

CREATE INDEX idx_complaint_fulfillment_lines_f ON complaint_fulfillment_lines (fulfillment_id);

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
