-- Demo schema for cloning:run CI tests (SQL Server)
-- users.id and orders.user_id are VARCHAR(36) to accommodate UUID key remapping.

-- sqlcmd defaults to QUOTED_IDENTIFIER OFF, but tables with PERSISTED
-- computed columns (bookings.guest_label) require it ON.
SET QUOTED_IDENTIFIER ON;
GO

CREATE TABLE users (
    id VARCHAR(36) NOT NULL,
    email VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    password VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    created_at DATETIME2 DEFAULT GETDATE(),
    PRIMARY KEY (id)
);
GO

CREATE TABLE orders (
    id INT NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_address NVARCHAR(MAX),
    status VARCHAR(50) DEFAULT 'pending',
    created_at DATETIME2 DEFAULT GETDATE(),
    PRIMARY KEY (id)
);
GO

INSERT INTO users (id, email, first_name, last_name, password, phone, city) VALUES
('1', 'alice@example.com', 'Alice', 'Smith', 'password123', '+1-555-0101', 'Berlin'),
('2', 'bob@example.com', 'Bob', 'Jones', 'secret456', '+1-555-0102', 'London'),
('3', 'charlie@example.com', 'Charlie', 'Brown', 'pass789', '+1-555-0103', 'Paris');
GO

INSERT INTO orders (id, user_id, total, shipping_address, status) VALUES
(1, '1', 99.99, '123 Main St, Springfield, IL 62701', 'shipped'),
(2, '2', 149.50, '456 Oak Ave, Portland, OR 97201', 'pending'),
(3, '1', 29.99, '789 Pine Rd, Austin, TX 73301', 'delivered');
GO

-- Computed columns (issue #189): `day` must not be copied, `guest_label`
-- derives from PII and must never carry the original value.
-- `internal_note` is left out via the 'exclude' column strategy.
CREATE TABLE bookings (
    id INT NOT NULL,
    guest_name VARCHAR(100) NOT NULL,
    starts_at DATETIME2 NOT NULL,
    day AS CAST(starts_at AS DATE),
    guest_label AS UPPER(guest_name) PERSISTED,
    internal_note VARCHAR(255),
    PRIMARY KEY (id)
);
GO

INSERT INTO bookings (id, guest_name, starts_at, internal_note) VALUES
(1, 'Alice Smith', '2026-01-15 10:00:00', 'VIP, allergic to nuts'),
(2, 'Bob Jones', '2026-02-20 14:30:00', 'pays cash'),
(3, 'Charlie Brown', '2026-03-05 09:15:00', 'call before arrival');
GO
