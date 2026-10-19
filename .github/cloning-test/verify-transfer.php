<?php

// Verify that the cloning:run transfer worked correctly.
// Usage: php verify-transfer.php <driver> <host> <port> <database> <username> <password>
//
// Checks:
//   1. Target DB has 3 users (same count as source)
//   2. Email addresses in target are different from source (anonymized)
//   3. Passwords in target are sha256 hashes (64 hex chars), not originals
//   4. Target DB has 3 orders
//   5. users.id values are valid UUIDs (key remapping applied)
//   6. orders.user_id values all reference a valid users.id (referential integrity)
//   7. Target DB has 3 bookings (generated columns did not break the insert)
//   8. bookings.internal_note is NULL everywhere ('exclude' strategy)
//   9. bookings.guest_label never holds the original (PII-derived) value: it is
//      either recomputed by the target from the anonymized guest_name or NULL
//      when the generation expression was not replicated
//  10. bookings.day is either NULL or the date part of starts_at
//  11. sessions exists on the target but holds no rows ('schema' strategy)

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
    } elseif ($driver === 'sqlsrv') {
        $dsn = "sqlsrv:Server=$host,$port;Database=$database;TrustServerCertificate=1";
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

    // 5. users.id values are valid UUIDs (key remapping applied)
    $userIds = $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    $allUuids = true;
    foreach ($userIds as $id) {
        if (in_array((string) $id, ['1', '2', '3'], true)) {
            fail("users.id '$id' was not remapped (still original integer value)");
            $allUuids = false;
        } elseif (! preg_match($uuidPattern, (string) $id)) {
            fail("users.id '$id' is not a valid UUID");
            $allUuids = false;
        }
    }
    if ($allUuids) {
        pass('All users.id values are valid UUIDs (key remapping applied)');
    }

    // 6. orders.user_id references a valid users.id (referential integrity)
    $orderUserIds = $pdo->query('SELECT DISTINCT user_id FROM orders')->fetchAll(PDO::FETCH_COLUMN);
    $userIdSet = array_flip(array_map('strval', $userIds));
    $allReferenced = true;
    foreach ($orderUserIds as $fk) {
        if (! isset($userIdSet[(string) $fk])) {
            fail("orders.user_id '$fk' does not reference a valid users.id");
            $allReferenced = false;
        }
    }
    if ($allReferenced) {
        pass('All orders.user_id values reference a valid remapped users.id (referential integrity)');
    }

    // 7. Booking count
    $bookings = $pdo->query('SELECT id, guest_name, starts_at, day, guest_label, internal_note FROM bookings')->fetchAll(PDO::FETCH_ASSOC);
    if (count($bookings) !== 3) {
        fail('Expected 3 bookings, got ' . count($bookings));
    } else {
        pass('Booking count is 3 (generated columns did not break the insert)');
    }

    // 8. Excluded column stays empty
    $notes = array_filter($bookings, static fn (array $b): bool => $b['internal_note'] !== null);
    if ($notes !== []) {
        fail('bookings.internal_note was copied despite the exclude strategy');
    } else {
        pass('bookings.internal_note is NULL everywhere (exclude strategy)');
    }

    // 9. PII-derived generated column never leaks the original value
    $originalLabels = ['ALICE SMITH', 'BOB JONES', 'CHARLIE BROWN'];
    $labelsOk = true;
    foreach ($bookings as $b) {
        $label = $b['guest_label'];
        if ($label === null) {
            continue;
        }
        if (in_array((string) $label, $originalLabels, true)) {
            fail("bookings.guest_label '$label' leaks the original guest name");
            $labelsOk = false;
        } elseif ((string) $label !== strtoupper((string) $b['guest_name'])) {
            fail("bookings.guest_label '$label' does not match the anonymized guest_name");
            $labelsOk = false;
        }
    }
    if ($labelsOk) {
        pass('bookings.guest_label is recomputed or NULL, never the original value');
    }

    // 10. Generated date column is computed or empty
    $daysOk = true;
    foreach ($bookings as $b) {
        if ($b['day'] === null) {
            continue;
        }
        if (substr((string) $b['day'], 0, 10) !== substr((string) $b['starts_at'], 0, 10)) {
            fail("bookings.day '{$b['day']}' does not match starts_at '{$b['starts_at']}'");
            $daysOk = false;
        }
    }
    if ($daysOk) {
        pass('bookings.day is recomputed or NULL');
    }

    // 11. Schema-only table is created without rows
    $sessionCount = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    if ($sessionCount !== 0) {
        fail("Expected 0 sessions ('schema' strategy), got $sessionCount");
    } else {
        pass("sessions exists without rows ('schema' strategy)");
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
