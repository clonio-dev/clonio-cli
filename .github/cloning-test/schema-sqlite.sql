-- Demo schema for cloning:run CI tests (SQLite)
-- users.id and orders.user_id are TEXT to accommodate UUID key remapping.

CREATE TABLE IF NOT EXISTS users (
    id TEXT NOT NULL,
    email TEXT NOT NULL,
    first_name TEXT,
    last_name TEXT,
    password TEXT,
    phone TEXT,
    city TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS orders (
    id INTEGER NOT NULL,
    user_id TEXT NOT NULL,
    total REAL NOT NULL DEFAULT 0.00,
    shipping_address TEXT,
    status TEXT DEFAULT 'pending',
    created_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (id)
);

INSERT INTO users (id, email, first_name, last_name, password, phone, city) VALUES
('1', 'alice@example.com', 'Alice', 'Smith', 'password123', '+1-555-0101', 'Berlin'),
('2', 'bob@example.com', 'Bob', 'Jones', 'secret456', '+1-555-0102', 'London'),
('3', 'charlie@example.com', 'Charlie', 'Brown', 'pass789', '+1-555-0103', 'Paris');

INSERT INTO orders (id, user_id, total, shipping_address, status) VALUES
(1, '1', 99.99, '123 Main St, Springfield, IL 62701', 'shipped'),
(2, '2', 149.50, '456 Oak Ave, Portland, OR 97201', 'pending'),
(3, '1', 29.99, '789 Pine Rd, Austin, TX 73301', 'delivered');

-- Generated columns (issue #189): `day` must not be copied, `guest_label`
-- derives from PII and must never carry the original value.
-- `internal_note` is left out via the 'exclude' column strategy.
CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER NOT NULL,
    guest_name TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    day TEXT GENERATED ALWAYS AS (date(starts_at)) VIRTUAL,
    guest_label TEXT GENERATED ALWAYS AS (upper(guest_name)) STORED,
    internal_note TEXT,
    PRIMARY KEY (id)
);

INSERT INTO bookings (id, guest_name, starts_at, internal_note) VALUES
(1, 'Alice Smith', '2026-01-15 10:00:00', 'VIP, allergic to nuts'),
(2, 'Bob Jones', '2026-02-20 14:30:00', 'pays cash'),
(3, 'Charlie Brown', '2026-03-05 09:15:00', 'call before arrival');

-- Issue #157: `sessions` uses the 'schema' row strategy. The table is created
-- on the target, but none of its rows are copied.
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(64) NOT NULL,
    user_email VARCHAR(255),
    payload VARCHAR(255) NOT NULL,
    last_activity INT NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO sessions (id, user_email, payload, last_activity) VALUES
('sess-1', 'alice@example.com', 'csrf=abc', 1760000000),
('sess-2', 'bob@example.com', 'csrf=def', 1760000100);
