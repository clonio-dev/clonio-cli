-- Demo schema for cloning:run CI tests (SQLite)

CREATE TABLE IF NOT EXISTS users (
    id INTEGER NOT NULL,
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
    user_id INTEGER NOT NULL,
    total REAL NOT NULL DEFAULT 0.00,
    shipping_address TEXT,
    status TEXT DEFAULT 'pending',
    created_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (id)
);

INSERT INTO users (id, email, first_name, last_name, password, phone, city) VALUES
(1, 'alice@example.com', 'Alice', 'Smith', 'password123', '+1-555-0101', 'Berlin'),
(2, 'bob@example.com', 'Bob', 'Jones', 'secret456', '+1-555-0102', 'London'),
(3, 'charlie@example.com', 'Charlie', 'Brown', 'pass789', '+1-555-0103', 'Paris');

INSERT INTO orders (id, user_id, total, shipping_address, status) VALUES
(1, 1, 99.99, '123 Main St, Springfield, IL 62701', 'shipped'),
(2, 2, 149.50, '456 Oak Ave, Portland, OR 97201', 'pending'),
(3, 1, 29.99, '789 Pine Rd, Austin, TX 73301', 'delivered');
