<?php

// Verify that the cloning:run transfer worked correctly.
// Usage: php verify-transfer.php <driver> <host> <port> <database> <username> <password>
//
// Checks:
//   1. Target DB has 3 users (same count as source)
//   2. Email addresses in target are different from source (anonymized)
//   3. Passwords in target are sha256 hashes (64 hex chars), not originals
//   4. Target DB has 3 orders

$driver   = $argv[1] ?? 'mysql';
$host     = $argv[2] ?? '127.0.0.1';
$port     = $argv[3] ?? '3306';
$database = $argv[4] ?? 'clonio_target';
$username = $argv[5] ?? 'root';
$password = $argv[6] ?? 'secret';

$originalEmails    = ['alice@example.com', 'bob@example.com', 'charlie@example.com'];
$originalPasswords = ['password123', 'secret456', 'pass789'];

$failed = false;

function pass(string $msg): void
{
    echo "PASS: $msg\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed = true;
    echo "FAIL: $msg\n";
}

try {
    if ($driver === 'sqlite') {
        $dsn = "sqlite:$database";
        $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif ($driver === 'pgsql') {
        $dsn = "pgsql:host=$host;port=$port;dbname=$database";
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    // 1. User count
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count !== 3) {
        fail("Expected 3 users, got $count");
    } else {
        pass('User count is 3');
    }

    // 2. Emails are anonymized
    $emails = $pdo->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $allAnonymized = true;
    foreach ($emails as $email) {
        if (in_array($email, $originalEmails, true)) {
            fail("Email '$email' was not anonymized");
            $allAnonymized = false;
        }
    }
    if ($allAnonymized) {
        pass('All emails are anonymized');
    }

    // 3. Passwords are sha256 hashes
    $passwords = $pdo->query('SELECT password FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $allHashed = true;
    foreach ($passwords as $pwd) {
        if (in_array($pwd, $originalPasswords, true)) {
            fail("Password was not hashed: '$pwd'");
            $allHashed = false;
        } elseif (! preg_match('/^[0-9a-f]{64}$/', (string) $pwd)) {
            fail("Password '$pwd' is not a valid sha256 hash");
            $allHashed = false;
        }
    }
    if ($allHashed) {
        pass('All passwords are properly hashed (sha256)');
    }

    // 4. Order count
    $orderCount = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    if ($orderCount !== 3) {
        fail("Expected 3 orders, got $orderCount");
    } else {
        pass('Order count is 3');
    }

} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

if ($failed) {
    echo "\nSOME CHECKS FAILED\n";
    exit(1);
}

echo "\nALL CHECKS PASSED\n";
exit(0);
