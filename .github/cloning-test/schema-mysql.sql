-- Demo schema for cloning:run CI tests (MySQL / MariaDB)

CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    password VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS orders (
    id INT NOT NULL,
    user_id INT NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_address TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
