# Connection TLS (Secure Transport) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every network connection (`mysql`, `mariadb`, `pgsql`, `sqlsrv`) gets an optional `ssl` block (`disable` / `require` / `verify` plus CA and client-certificate paths). Clonio can then connect to servers that enforce TLS (issue #149), verify server identity on request, and default new connections to `require`.

**Architecture:**
- A readonly `SslConfig` DTO (mode enum plus path fields and the validation rules) hangs off `ConnectionData`.
- `DatabaseConnectionService::buildConfig()` maps it to driver config:
  - PDO options for MySQL/MariaDB.
  - `sslmode`/`sslrootcert` for PostgreSQL.
  - `encrypt`/`trust_server_certificate` for SQL Server.
- `CertificateFiles` resolves paths against cwd/`$HOME`.
- `ConnectionErrorHint` turns opaque driver errors into actionable messages inside `open()`.
- `connection:add`/`connection:update` share one `PromptsForSsl` trait.
- `connection:list` and `connection:test` display the mode.

**Tech Stack:** PHP 8.5 (`Pdo\Mysql` constants), Laravel Zero 12, Pest 4 + Mockery, PHPStan max (Larastan), Pint, Rector, GitHub Actions + Docker + openssl + jq for the end-to-end TLS matrix (bash scripts), Pergament for the clonio-docs site.

**Spec:** `specs/PRD-connection-tls.md` (v0.4). Read it before starting. Section numbers below (§x) refer to it.

## Global Constraints

- Allowed values of `ssl.mode`: `disable`, `require`, `verify`. There is no `prefer`, no `verify-ca`, no `verify-full` in the Clonio vocabulary.
- An absent `ssl` key means "driver default". Existing `clonio.json` files must behave exactly as before, and `toArray()` must not add an `ssl` key for them.
- New connections default to `require`, and `--no-interaction` stores `{"mode": "require"}`.
- `ca` is allowed only with `verify`. `cert`/`key` are allowed only with `require`/`verify`, and only together. Certificate paths are not supported for `sqlsrv`.
- MySQL `require` = `Pdo\Mysql::ATTR_SSL_CA => ''` plus `Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false`. `VERIFY_SERVER_CERT` is always set explicitly.
- Paths are stored exactly as entered. Resolution order: `~/` → `$HOME`, absolute → as-is, relative → `Storage::disk('local')->path()`. Never use `base_path()`.
- Only paths are stored. Never print or log certificate or key contents.
- Exit codes: Success 0, ConfigError 2, ConnectionError 3, ValidationError 4, IoError 5.
- Option values are declared with `=` (`{--ssl-mode=}`), and `ask()`/`choice()` results are type-checked before use (no raw `mixed`).
- `composer test` must pass: PHPStan level max, type coverage ≥ 90 %, `pest --parallel --coverage --min=85`, Pint + Rector dry-run.
- The TLS matrix (§10.1) is blocking for every driver, SQL Server included. When a cell fails, fix the code (for example a missing hint pattern) or, after the user decides, fix spec + cases file + docs together. Never loosen an assertion or let a row accept two outcomes.
- User documentation lives in the separate repo `/Users/rok/workspace/clonio-dev/clonio-docs` (branch `149-connection-tls`). This plan's in-repo `docs/commands/*` pages (Task 8) are the CLI reference and are kept too.
- Code, comments, commits and docs are in English. Commit only when the user asks. Each task ends with a *proposed* commit command.

## Review Focus

1. **Docker + `verify`:** inside the container `127.0.0.1` is rewritten to `host.docker.internal`, which is not in the server certificate. The verification hint must name the host actually dialled, not the configured one. The test is in Task 4.
2. **Existing `clonio.json` without `ssl`:** it must round-trip without gaining an `ssl` key, and `buildConfig()` must not add `options`/`sslmode`/`encrypt`. The tests are in Task 1 and Task 3.
3. **Stock PostgreSQL plus the new default `require`:** libpq fails with `server does not support SSL`. The user must see the "may not support TLS — use disable" hint. The test is in Task 4.
4. **Relative cert path while running from another directory:** Clonio must fail before PDO with `Certificate file not found: <absolute resolved path>`, not with mysqlnd's generic `[2002]`. The test is in Task 3.
5. **Legacy SQL Server `trust_server_certificate: true`:**
   - Without `ssl`, it behaves as today.
   - With `ssl`, `ssl` wins.
   - `connection:update` preselects `require` and drops the legacy flag.

   The tests are in Task 3 and Task 6.

---

## File Structure

**New files:**
- `app/Enums/SslMode.php`: the mode enum with prompt labels.
- `app/Data/SslConfig.php`: the readonly DTO. Holds `fromArray`/`toArray` and the static rules `violations(DatabaseConnectionType)`.
- `app/Services/Database/Tls/CertificateFiles.php`: the filesystem side of certs. Resolves paths, lists unreadable files, and checks key permissions.
- `app/Services/Database/Tls/ConnectionErrorHint.php`: maps a driver exception to an actionable hint.
- `app/Commands/Connection/Concerns/PromptsForSsl.php`: prompt and validation helpers shared by add and update.
- `tests/Unit/Data/SslConfigTest.php`
- `tests/Unit/Services/Database/Tls/CertificateFilesTest.php`
- `tests/Unit/Services/Database/Tls/ConnectionErrorHintTest.php`
- `.github/tls/make-certs.sh`, `start-server.sh`, `run-matrix.sh`: throwaway PKI, one server per posture, case runner.
- `.github/tls/cases/{mysql,pgsql,sqlsrv}.cases`: the §10.1 expectation tables as data.
- `.github/workflows/connection-tls-matrix.yml`: 15 blocking jobs (driver × posture).

**clonio-docs repo** (`/Users/rok/workspace/clonio-dev/clonio-docs`):
- Create: `content/docs/1-connections/04-transport-security.md`
- Modify: `content/docs/1-connections/01-managing-connections.md`, `02-supported-databases.md`, `content/docs/5-reference/02-command-reference.md`, `04-troubleshooting.md`, `content/docs/3-running-clones/03-ci-cd.md`

**Modified files:**
- `app/Data/ConnectionData.php`: new `?SslConfig $ssl` field.
- `app/Services/Database/DatabaseConnectionService.php`: TLS mapping, file check, hint in `open()`, `negotiatedCipher()`.
- `app/Commands/Connection/AddCommand.php`: `--ssl-*` options and the prompt, which replaces the sqlsrv trust prompt.
- `app/Commands/Connection/UpdateCommand.php`: SSL prompts, legacy migration, diff rows.
- `app/Commands/Connection/ListCommand.php`: `TLS` column.
- `app/Commands/Connection/TestCommand.php`: goes through `open()`, shows the mode, adds the `-v` cipher line.
- `resources/schema/clonio.schema.json`: `ssl` and `trust_server_certificate` in the network branch.
- `.github/workflows/connection-test.yml`: postgres gets `--ssl-mode=disable`.
- `.github/workflows/cloning-run-test.yml`: both pgsql connections get `--ssl-mode=disable`.
- `docs/commands/connection-add.md`, `connection-update.md`, `connection-list.md`, `connection-test.md`
- Tests: `tests/Unit/Data/ConnectionDataTest.php`, `tests/Unit/Services/Database/DatabaseConnectionServiceTest.php`, `tests/Feature/Commands/Connection/{Add,Update,List,Test}CommandTest.php`

---

### Task 1: TLS config model (`SslMode`, `SslConfig`, `ConnectionData.ssl`)

**Files:**
- Create: `app/Enums/SslMode.php`
- Create: `app/Data/SslConfig.php`
- Modify: `app/Data/ConnectionData.php`
- Test: `tests/Unit/Data/SslConfigTest.php` (new), `tests/Unit/Data/ConnectionDataTest.php`

**Interfaces:**
- Produces:
  - `enum SslMode: string { Require='require'; Verify='verify'; Disable='disable' }` with `label(): string` and `static values(): list<string>`.
  - `final readonly class SslConfig(SslMode $mode, ?string $ca = null, ?string $cert = null, ?string $key = null)` with:
    - `static fromArray(mixed $data): ?self`, which throws `InvalidArgumentException`.
    - `toArray(): array{mode: string, ca?: string, cert?: string, key?: string}`.
    - `hasCertificateFiles(): bool`.
    - `violations(DatabaseConnectionType $type): list<string>`.
  - `ConnectionData::$ssl` (`?SslConfig`, last constructor param, default `null`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Data/SslConfigTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;

it('returns null when no ssl block is given', function (): void {
    expect(SslConfig::fromArray(null))->toBeNull();
});

it('parses mode and paths', function (): void {
    $ssl = SslConfig::fromArray(['mode' => 'verify', 'ca' => 'certs/ca.pem', 'cert' => 'c.pem', 'key' => 'k.pem']);

    expect($ssl)->toBeInstanceOf(SslConfig::class)
        ->and($ssl?->mode)->toBe(SslMode::Verify)
        ->and($ssl?->ca)->toBe('certs/ca.pem')
        ->and($ssl?->cert)->toBe('c.pem')
        ->and($ssl?->key)->toBe('k.pem');
});

it('treats empty path strings as absent', function (): void {
    $ssl = SslConfig::fromArray(['mode' => 'require', 'ca' => '']);

    expect($ssl?->ca)->toBeNull();
});

it('rejects an unknown mode and lists the valid ones', function (): void {
    SslConfig::fromArray(['mode' => 'prefer']);
})->throws(InvalidArgumentException::class, 'Invalid ssl mode "prefer". Valid modes: require, verify, disable.');

it('rejects a missing mode', function (): void {
    SslConfig::fromArray(['ca' => 'certs/ca.pem']);
})->throws(InvalidArgumentException::class, 'Invalid ssl mode');

it('rejects a plain string instead of an object (web-app style "ssl": "require")', function (): void {
    SslConfig::fromArray('require');
})->throws(InvalidArgumentException::class, 'ssl must be an object with a "mode" key.');

it('serialises only the fields that are set', function (): void {
    expect((new SslConfig(SslMode::Require))->toArray())->toBe(['mode' => 'require'])
        ->and((new SslConfig(SslMode::Verify, 'ca.pem'))->toArray())->toBe(['mode' => 'verify', 'ca' => 'ca.pem']);
});

it('has no violations for valid configurations', function (DatabaseConnectionType $type, SslConfig $ssl): void {
    expect($ssl->violations($type))->toBe([]);
})->with([
    'mysql require' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Require)],
    'mysql require mtls' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Require, null, 'c.pem', 'k.pem')],
    'mariadb verify with ca' => [DatabaseConnectionType::MariaDB, new SslConfig(SslMode::Verify, 'ca.pem')],
    'pgsql verify without ca' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Verify)],
    'sqlsrv verify' => [DatabaseConnectionType::SqlServer, new SslConfig(SslMode::Verify)],
    'pgsql disable' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Disable)],
]);

it('reports each rule violation', function (DatabaseConnectionType $type, SslConfig $ssl, string $error): void {
    expect($ssl->violations($type))->toContain($error);
})->with([
    'sqlite' => [DatabaseConnectionType::Sqlite, new SslConfig(SslMode::Require), 'ssl is only supported for network connections'],
    'dump' => [DatabaseConnectionType::Dump, new SslConfig(SslMode::Require), 'ssl is only supported for network connections'],
    'files with disable' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Disable, null, 'c.pem', 'k.pem'), 'certificate files require mode require or verify'],
    'ca with require' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Require, 'ca.pem'), 'a CA certificate is only used with mode verify'],
    'files on sqlsrv' => [DatabaseConnectionType::SqlServer, new SslConfig(SslMode::Verify, 'ca.pem'), 'sqlsrv uses the system trust store; certificate paths are not supported'],
    'mysql verify without ca' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Verify), 'mode verify requires a CA certificate for MySQL/MariaDB'],
    'cert without key' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Require, null, 'c.pem'), 'cert and key must be set together'],
    'key without cert' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Require, null, null, 'k.pem'), 'cert and key must be set together'],
]);

it('labels modes for prompts', function (): void {
    expect(SslMode::Require->label())->toBe('Require (encrypted, not verified)')
        ->and(SslMode::Verify->label())->toBe('Verify (encrypted + certificate check)')
        ->and(SslMode::Disable->label())->toBe('Disable (plaintext)')
        ->and(SslMode::values())->toBe(['require', 'verify', 'disable']);
});
```

Append to `tests/Unit/Data/ConnectionDataTest.php` (it already imports `ConnectionData` and `DatabaseConnectionType`; add `use App\Data\SslConfig;` and `use App\Enums\SslMode;` to the import block):

```php
it('round-trips an ssl block', function (): void {
    $data = [
        'type' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'app', 'username' => 'u',
        'password' => 'encrypted:x', 'is_production' => true,
        'ssl' => ['mode' => 'verify', 'ca' => 'certs/ca.pem'],
    ];

    $connection = ConnectionData::fromArray('prod', $data);

    expect($connection->ssl)->toEqual(new SslConfig(SslMode::Verify, 'certs/ca.pem'))
        ->and($connection->toArray()['ssl'])->toBe(['mode' => 'verify', 'ca' => 'certs/ca.pem']);
});

it('does not add an ssl key to connections that never had one', function (): void {
    $data = [
        'type' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'app', 'username' => 'u',
        'password' => 'encrypted:x', 'is_production' => false,
    ];

    $connection = ConnectionData::fromArray('legacy', $data);

    expect($connection->ssl)->toBeNull()
        ->and($connection->toArray())->toBe($data);
});

it('rejects ssl on a sqlite connection when loading, naming the connection', function (): void {
    ConnectionData::fromArray('local', ['type' => 'sqlite', 'database' => 'a.db', 'password' => '', 'ssl' => ['mode' => 'require']]);
})->throws(InvalidArgumentException::class, 'Connection "local": ssl is only supported for network connections.');

it('prefixes invalid ssl blocks with the connection name', function (): void {
    ConnectionData::fromArray('prod', ['type' => 'mysql', 'password' => '', 'ssl' => ['mode' => 'bogus']]);
})->throws(InvalidArgumentException::class, 'Connection "prod": Invalid ssl mode "bogus"');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Data/SslConfigTest.php tests/Unit/Data/ConnectionDataTest.php`
Expected: FAIL with `Class "App\Data\SslConfig" not found`.

- [ ] **Step 3: Implement**

Create `app/Enums/SslMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Transport security for a network connection. Case order is the prompt order.
 */
enum SslMode: string
{
    case Require = 'require';
    case Verify = 'verify';
    case Disable = 'disable';

    public function label(): string
    {
        return match ($this) {
            self::Require => 'Require (encrypted, not verified)',
            self::Verify => 'Verify (encrypted + certificate check)',
            self::Disable => 'Disable (plaintext)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
```

Create `app/Data/SslConfig.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use InvalidArgumentException;

/**
 * The `ssl` block of a network connection. Paths are kept exactly as the user entered them;
 * resolution happens at connect time (see CertificateFiles).
 */
final readonly class SslConfig
{
    public function __construct(
        public SslMode $mode,
        public ?string $ca = null,
        public ?string $cert = null,
        public ?string $key = null,
    ) {}

    /**
     * @throws InvalidArgumentException when the block is not an object or the mode is unknown
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('ssl must be an object with a "mode" key.');
        }

        $rawMode = $data['mode'] ?? null;
        $mode = is_string($rawMode) ? SslMode::tryFrom($rawMode) : null;

        if (! $mode instanceof SslMode) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ssl mode "%s". Valid modes: %s.',
                is_string($rawMode) ? $rawMode : get_debug_type($rawMode),
                implode(', ', SslMode::values()),
            ));
        }

        return new self($mode, self::path($data, 'ca'), self::path($data, 'cert'), self::path($data, 'key'));
    }

    /** @return array{mode: string, ca?: string, cert?: string, key?: string} */
    public function toArray(): array
    {
        $data = ['mode' => $this->mode->value];

        if ($this->ca !== null) {
            $data['ca'] = $this->ca;
        }

        if ($this->cert !== null) {
            $data['cert'] = $this->cert;
        }

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        return $data;
    }

    public function hasCertificateFiles(): bool
    {
        return $this->ca !== null || $this->cert !== null || $this->key !== null;
    }

    /**
     * Static rules from PRD-connection-tls §7. File existence is checked separately (CertificateFiles).
     *
     * @return list<string>
     */
    public function violations(DatabaseConnectionType $type): array
    {
        if (! $type->requiresNetworkConfig()) {
            return ['ssl is only supported for network connections'];
        }

        $errors = [];

        if ($this->mode === SslMode::Disable && $this->hasCertificateFiles()) {
            $errors[] = 'certificate files require mode require or verify';
        }

        if ($this->mode === SslMode::Require && $this->ca !== null) {
            $errors[] = 'a CA certificate is only used with mode verify';
        }

        if ($type === DatabaseConnectionType::SqlServer && $this->hasCertificateFiles()) {
            $errors[] = 'sqlsrv uses the system trust store; certificate paths are not supported';
        }

        $isMysqlFamily = $type === DatabaseConnectionType::Mysql || $type === DatabaseConnectionType::MariaDB;

        if ($this->mode === SslMode::Verify && $this->ca === null && $isMysqlFamily) {
            $errors[] = 'mode verify requires a CA certificate for MySQL/MariaDB';
        }

        if (($this->cert === null) !== ($this->key === null)) {
            $errors[] = 'cert and key must be set together';
        }

        return $errors;
    }

    /** @param array<mixed> $data */
    private static function path(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
```

Modify `app/Data/ConnectionData.php`:

- Add imports: `use InvalidArgumentException;`.
- Add a last constructor param: `public ?SslConfig $ssl = null,` (after `dialect`). `SslConfig` is in the same namespace, so no import is needed.
- In `fromArray()`, before `return new self(`:

```php
        $resolvedType = DatabaseConnectionType::from(is_string($type) ? $type : '');

        try {
            $ssl = SslConfig::fromArray($data['ssl'] ?? null);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new InvalidArgumentException(sprintf('Connection "%s": %s', $name, $invalidArgumentException->getMessage()), 0, $invalidArgumentException);
        }

        if ($ssl instanceof SslConfig && ! $resolvedType->requiresNetworkConfig()) {
            throw new InvalidArgumentException(sprintf('Connection "%s": ssl is only supported for network connections.', $name));
        }
```

  Then use `type: $resolvedType,` and add `ssl: $ssl,` as the last named argument.
- In `toArray()`, after the `trust_server_certificate` block and before `return $data;`:

```php
        if ($this->ssl instanceof SslConfig) {
            $data['ssl'] = $this->ssl->toArray();
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Data/`
Expected: PASS, including all existing `ConnectionDataTest`/`ConnectionDataDumpTest` cases.

- [ ] **Step 5: Static checks**

Run: `./vendor/bin/phpstan analyse app/Data app/Enums/SslMode.php --memory-limit=1G`
Expected: `[OK] No errors`

- [ ] **Step 6: Commit (proposed)**

```bash
git add app/Enums/SslMode.php app/Data/SslConfig.php app/Data/ConnectionData.php tests/Unit/Data/SslConfigTest.php tests/Unit/Data/ConnectionDataTest.php
git commit -m "feat(connection): add ssl config model for connection transport security"
```

---

### Task 2: Certificate path resolution (`CertificateFiles`)

**Files:**
- Create: `app/Services/Database/Tls/CertificateFiles.php`
- Test: `tests/Unit/Services/Database/Tls/CertificateFilesTest.php`

**Interfaces:**
- Consumes: `SslConfig` (Task 1).
- Produces: `class CertificateFiles` (not final, so tests can mock it) with:
  - `resolve(string $path): string`
  - `unreadable(SslConfig $ssl): list<string>` (resolved paths)
  - `isKeyExposed(string $path): bool`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Data\SslConfig;
use App\Enums\SslMode;
use App\Services\Database\Tls\CertificateFiles;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->originalHome = getenv('HOME');
    putenv('HOME=/home/tester');
});

afterEach(function (): void {
    putenv('HOME='.($this->originalHome === false ? '' : $this->originalHome));
});

it('resolves relative paths against the working-directory disk, not the install path', function (): void {
    expect((new CertificateFiles)->resolve('certs/ca.pem'))
        ->toBe(Storage::disk('local')->path('certs/ca.pem'));
});

it('resolves ~/ against HOME', function (): void {
    expect((new CertificateFiles)->resolve('~/certs/ca.pem'))->toBe('/home/tester/certs/ca.pem');
});

it('keeps absolute paths unchanged', function (): void {
    expect((new CertificateFiles)->resolve('/etc/ssl/ca.pem'))->toBe('/etc/ssl/ca.pem')
        ->and((new CertificateFiles)->resolve('C:\\certs\\ca.pem'))->toBe('C:\\certs\\ca.pem');
});

it('lists every configured file that does not exist, as resolved path', function (): void {
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $missing = (new CertificateFiles)->unreadable(new SslConfig(SslMode::Verify, 'certs/ca.pem', 'certs/c.pem', 'certs/k.pem'));

    expect($missing)->toBe([
        Storage::disk('local')->path('certs/c.pem'),
        Storage::disk('local')->path('certs/k.pem'),
    ]);
});

it('returns nothing when no files are configured', function (): void {
    expect((new CertificateFiles)->unreadable(new SslConfig(SslMode::Require)))->toBe([]);
});

it('flags group- or world-readable keys', function (): void {
    Storage::disk('local')->put('certs/k.pem', 'x');
    $path = Storage::disk('local')->path('certs/k.pem');

    chmod($path, 0644);
    expect((new CertificateFiles)->isKeyExposed('certs/k.pem'))->toBeTrue();

    chmod($path, 0600);
    expect((new CertificateFiles)->isKeyExposed('certs/k.pem'))->toBeFalse();
})->skipOnWindows();

it('does not flag a key that does not exist', function (): void {
    expect((new CertificateFiles)->isKeyExposed('certs/missing.pem'))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Database/Tls/CertificateFilesTest.php`
Expected: FAIL with `Class "App\Services\Database\Tls\CertificateFiles" not found`.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services\Database\Tls;

use App\Data\SslConfig;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves certificate paths from clonio.json. PHAR/SPC binaries are read-only archives,
 * so relative paths resolve against the working directory (local disk), never base_path().
 */
class CertificateFiles
{
    public function resolve(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');

            return is_string($home) && $home !== '' ? rtrim($home, '/').substr($path, 1) : $path;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return Storage::disk('local')->path($path);
    }

    /**
     * Resolved paths of configured certificate files that are missing or unreadable.
     *
     * @return list<string>
     */
    public function unreadable(SslConfig $ssl): array
    {
        $unreadable = [];

        foreach ([$ssl->ca, $ssl->cert, $ssl->key] as $path) {
            if ($path === null) {
                continue;
            }

            $resolved = $this->resolve($path);

            if (! is_file($resolved) || ! is_readable($resolved)) {
                $unreadable[] = $resolved;
            }
        }

        return $unreadable;
    }

    /** True when a private key is readable by group or others (POSIX only). */
    public function isKeyExposed(string $path): bool
    {
        $resolved = $this->resolve($path);

        if (PHP_OS_FAMILY === 'Windows' || ! is_file($resolved)) {
            return false;
        }

        $permissions = fileperms($resolved);

        return $permissions !== false && ($permissions & 0o077) !== 0;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Database/Tls/CertificateFilesTest.php`
Expected: PASS

- [ ] **Step 5: Commit (proposed)**

```bash
git add app/Services/Database/Tls/CertificateFiles.php tests/Unit/Services/Database/Tls/CertificateFilesTest.php
git commit -m "feat(connection): resolve certificate paths against cwd and HOME"
```

---

### Task 3: Driver mapping in `buildConfig()`

**Files:**
- Modify: `app/Services/Database/DatabaseConnectionService.php`
- Test: `tests/Unit/Services/Database/DatabaseConnectionServiceTest.php`

**Interfaces:**
- Consumes: `SslConfig`, `SslMode` (Task 1); `CertificateFiles::resolve()` and `unreadable()` (Task 2).
- Produces:
  - Constructor `__construct(?bool $inDocker = null, ?CertificateFiles $certificates = null)`.
  - `buildConfig()` throws `RuntimeException` for invalid ssl or missing files.
  - Public `resolvedHost(ConnectionData $connection): ?string`, used by Task 4.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Services/Database/DatabaseConnectionServiceTest.php`:

- Add imports: `use App\Data\SslConfig;`, `use App\Enums\SslMode;`, `use Illuminate\Support\Facades\Storage;`, `use Pdo\Mysql;`.
- Extend the helper:

```php
function makeConnection(
    DatabaseConnectionType $type,
    string $password = 'secret',
    ?string $schema = null,
    ?string $host = null,
    ?SslConfig $ssl = null,
    bool $trustServerCertificate = false,
): ConnectionData {
    return new ConnectionData(
        name: 'test',
        type: $type,
        host: $type === DatabaseConnectionType::Sqlite ? null : ($host ?? '127.0.0.1'),
        port: $type->defaultPort(),
        database: $type === DatabaseConnectionType::Sqlite ? '/tmp/test.db' : 'mydb',
        schema: $schema,
        username: $type === DatabaseConnectionType::Sqlite ? null : 'root',
        password: $password,
        isProduction: false,
        trustServerCertificate: $trustServerCertificate,
        ssl: $ssl,
    );
}
```

Append:

```php
// ── TLS mapping ──────────────────────────────────────────────────────────────

it('adds no TLS keys when ssl is unset', function (DatabaseConnectionType $type): void {
    $config = (new DatabaseConnectionService(inDocker: false))->buildConfig(makeConnection($type), 'secret');

    expect($config)->not->toHaveKeys(['options', 'sslmode', 'sslrootcert', 'encrypt', 'trust_server_certificate']);
})->with([DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB, DatabaseConnectionType::PostgreSQL, DatabaseConnectionType::SqlServer]);

it('maps mysql require to an empty CA with verification off', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Require)), 'secret');

    expect($config['options'])->toBe([Mysql::ATTR_SSL_CA => '', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]);
});

it('maps mariadb verify to the resolved CA with verification on', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::MariaDB, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem')), 'secret');

    expect($config['options'])->toBe([
        Mysql::ATTR_SSL_CA => Storage::disk('local')->path('certs/ca.pem'),
        Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
    ]);
});

it('adds client certificate options for mysql mutual TLS', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Require, null, 'c.pem', 'k.pem')), 'secret');

    expect($config['options'])->toMatchArray([
        Mysql::ATTR_SSL_CERT => Storage::disk('local')->path('c.pem'),
        Mysql::ATTR_SSL_KEY => Storage::disk('local')->path('k.pem'),
    ]);
});

it('passes no options for mysql disable', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Disable)), 'secret');

    expect($config)->not->toHaveKey('options');
});

it('maps pgsql modes to sslmode', function (SslMode $mode, string $sslmode): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig($mode)), 'secret');

    expect($config['sslmode'])->toBe($sslmode);
})->with([
    [SslMode::Disable, 'disable'],
    [SslMode::Require, 'require'],
    [SslMode::Verify, 'verify-full'],
]);

it('uses the resolved CA as sslrootcert for pgsql verify', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem')), 'secret');

    expect($config['sslrootcert'])->toBe(Storage::disk('local')->path('certs/ca.pem'));
});

it('falls back to the system trust store for pgsql verify without a CA', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify)), 'secret');

    expect($config['sslrootcert'])->toBe('system');
});

it('never sets sslrootcert for pgsql require', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Require)), 'secret');

    expect($config)->not->toHaveKey('sslrootcert');
});

it('maps sqlsrv modes to encrypt and trust_server_certificate strings', function (SslMode $mode, array $expected): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::SqlServer, ssl: new SslConfig($mode)), 'secret');

    expect($config)->toMatchArray($expected);
})->with([
    'disable' => [SslMode::Disable, ['encrypt' => 'no']],
    'require' => [SslMode::Require, ['encrypt' => 'yes', 'trust_server_certificate' => 'yes']],
    'verify' => [SslMode::Verify, ['encrypt' => 'yes', 'trust_server_certificate' => 'no']],
]);

it('keeps the legacy sqlsrv trust flag when ssl is unset', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::SqlServer, trustServerCertificate: true), 'secret');

    expect($config['trust_server_certificate'])->toBeTrue()
        ->and($config)->not->toHaveKey('encrypt');
});

it('lets ssl win over the legacy sqlsrv trust flag', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::SqlServer, ssl: new SslConfig(SslMode::Verify), trustServerCertificate: true), 'secret');

    expect($config['trust_server_certificate'])->toBe('no');
});

it('fails before connecting when a certificate file is missing, naming the resolved path', function (): void {
    Storage::fake('local');

    (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem')), 'secret');
})->throws(RuntimeException::class, 'Certificate file not found: '.Storage::disk('local')->path('certs/ca.pem'));
```

The last test's `->throws()` message is evaluated before `Storage::fake()`. So write that test with an explicit `expect(fn () => ...)->toThrow(...)` inside the closure instead:

```php
it('fails before connecting when a certificate file is missing, naming the resolved path', function (): void {
    Storage::fake('local');
    $service = new DatabaseConnectionService(inDocker: false);
    $connection = makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    expect(fn (): array => $service->buildConfig($connection, 'secret'))
        ->toThrow(RuntimeException::class, 'Certificate file not found: '.Storage::disk('local')->path('certs/ca.pem'));
});

it('rejects an ssl block that breaks the rules at connect time', function (): void {
    $service = new DatabaseConnectionService(inDocker: false);
    $connection = makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Verify));

    expect(fn (): array => $service->buildConfig($connection, 'secret'))
        ->toThrow(RuntimeException::class, 'Invalid ssl configuration for connection "test": mode verify requires a CA certificate for MySQL/MariaDB');
});

it('exposes the host actually dialled', function (): void {
    $connection = makeConnection(DatabaseConnectionType::Mysql, host: '127.0.0.1');

    expect((new DatabaseConnectionService(inDocker: true))->resolvedHost($connection))->toBe('host.docker.internal')
        ->and((new DatabaseConnectionService(inDocker: false))->resolvedHost($connection))->toBe('127.0.0.1');
});
```

(Use only the second version of the missing-file test; the first is shown for why.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Database/DatabaseConnectionServiceTest.php`
Expected: FAIL. The first failure is `Unknown named parameter $ssl`, or missing `options` keys.

- [ ] **Step 3: Implement**

In `app/Services/Database/DatabaseConnectionService.php`:

Add imports:

```php
use App\Data\SslConfig;
use App\Enums\SslMode;
use App\Services\Database\Tls\CertificateFiles;
use Pdo\Mysql;
```

Change the constructor:

```php
    private readonly bool $inDocker;

    private readonly CertificateFiles $certificates;

    public function __construct(?bool $inDocker = null, ?CertificateFiles $certificates = null)
    {
        // /.dockerenv is created by the Docker engine inside every container and
        // is the most portable signal across Linux, macOS, and Windows daemons.
        $this->inDocker = $inDocker ?? is_file('/.dockerenv');
        $this->certificates = $certificates ?? new CertificateFiles;
    }
```

In `buildConfig()`, replace the sqlsrv branch body and add the TLS call just before `return $config;`:

```php
        } elseif ($connection->type === DatabaseConnectionType::SqlServer) {
            $config['charset'] = 'utf8';
        }

        if ($connection->schema !== null) {
            $config['search_path'] = $connection->schema;
        }

        return $this->applyTls($config, $connection);
    }
```

Add the public host accessor next to `resolveHost()`:

```php
    /** The host PDO will actually dial (after the Docker loopback rewrite). */
    public function resolvedHost(ConnectionData $connection): ?string
    {
        return $this->resolveHost($connection->host);
    }
```

Add the private mapping methods:

```php
    /**
     * Translates the connection's ssl block into driver config (PRD-connection-tls §5).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the ssl block breaks a rule or a certificate file is missing
     */
    private function applyTls(array $config, ConnectionData $connection): array
    {
        $ssl = $connection->ssl;

        if (! $ssl instanceof SslConfig) {
            // Legacy flag, only honoured while no ssl block exists.
            if ($connection->type === DatabaseConnectionType::SqlServer && $connection->trustServerCertificate) {
                $config['trust_server_certificate'] = true;
            }

            return $config;
        }

        $violations = $ssl->violations($connection->type);

        if ($violations !== []) {
            throw new RuntimeException(sprintf('Invalid ssl configuration for connection "%s": %s', $connection->name, implode('; ', $violations)));
        }

        // mysqlnd reports a missing file only as the generic "[2002] Cannot connect to MySQL using SSL".
        $unreadable = $this->certificates->unreadable($ssl);

        if ($unreadable !== []) {
            throw new RuntimeException('Certificate file not found: '.$unreadable[0]);
        }

        return match ($connection->type) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => $this->applyMysqlTls($config, $ssl),
            DatabaseConnectionType::PostgreSQL => $this->applyPgsqlTls($config, $ssl),
            DatabaseConnectionType::SqlServer => $this->applySqlsrvTls($config, $ssl),
            default => $config,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyMysqlTls(array $config, SslConfig $ssl): array
    {
        if ($ssl->mode === SslMode::Disable) {
            return $config;
        }

        // mysqlnd only switches TLS on once an SSL option is present. An empty CA does that
        // without restricting ciphers. VERIFY_SERVER_CERT must always be explicit: mysqlnd
        // verifies CA and hostname by default as soon as TLS is on. Verified against
        // MySQL 8.4 and MariaDB 11 (PRD-connection-tls §5.1).
        $options = [
            Mysql::ATTR_SSL_CA => $ssl->mode === SslMode::Verify && $ssl->ca !== null ? $this->certificates->resolve($ssl->ca) : '',
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $ssl->mode === SslMode::Verify,
        ];

        if ($ssl->cert !== null && $ssl->key !== null) {
            $options[Mysql::ATTR_SSL_CERT] = $this->certificates->resolve($ssl->cert);
            $options[Mysql::ATTR_SSL_KEY] = $this->certificates->resolve($ssl->key);
        }

        $config['options'] = $options;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyPgsqlTls(array $config, SslConfig $ssl): array
    {
        $config['sslmode'] = match ($ssl->mode) {
            SslMode::Disable => 'disable',
            SslMode::Require => 'require',
            SslMode::Verify => 'verify-full',
        };

        if ($ssl->mode === SslMode::Verify) {
            // Without a CA, "system" makes libpq (>= 16) use the OS trust store instead of ~/.postgresql/root.crt.
            $config['sslrootcert'] = $ssl->ca !== null ? $this->certificates->resolve($ssl->ca) : 'system';
        }

        if ($ssl->mode !== SslMode::Disable && $ssl->cert !== null && $ssl->key !== null) {
            $config['sslcert'] = $this->certificates->resolve($ssl->cert);
            $config['sslkey'] = $this->certificates->resolve($ssl->key);
        }

        return $config;
    }

    /**
     * Values are strings: SqlServerConnector interpolates them into the DSN, where false renders empty.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applySqlsrvTls(array $config, SslConfig $ssl): array
    {
        if ($ssl->mode === SslMode::Disable) {
            $config['encrypt'] = 'no';

            return $config;
        }

        $config['encrypt'] = 'yes';
        $config['trust_server_certificate'] = $ssl->mode === SslMode::Require ? 'yes' : 'no';

        return $config;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Database/DatabaseConnectionServiceTest.php`
Expected: PASS, including the pre-existing sqlsrv `trust_server_certificate` test.

- [ ] **Step 5: Commit (proposed)**

```bash
git add app/Services/Database/DatabaseConnectionService.php tests/Unit/Services/Database/DatabaseConnectionServiceTest.php
git commit -m "feat(connection): map ssl modes to mysql, pgsql and sqlsrv driver config"
```

---

### Task 4: Connect-time hints and cipher lookup

**Files:**
- Create: `app/Services/Database/Tls/ConnectionErrorHint.php`
- Modify: `app/Services/Database/DatabaseConnectionService.php` (`open()`, new `negotiatedCipher()`)
- Test: `tests/Unit/Services/Database/Tls/ConnectionErrorHintTest.php`, `tests/Unit/Services/Database/DatabaseConnectionServiceTest.php`

**Interfaces:**
- Consumes: `SslMode`, `ConnectionData::$ssl` (Task 1); `resolvedHost()` (Task 3).
- Produces:
  - `final class ConnectionErrorHint { public static function for(Throwable $e, ConnectionData $connection, ?string $host): ?string }`.
  - `open()` rethrows `RuntimeException("<driver message>\n<hint>", 0, $original)` when a hint matches, otherwise the original exception. It purges the dynamic connection on failure.
  - `negotiatedCipher(string $connectionName, DatabaseConnectionType $type): ?string`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Database/Tls/ConnectionErrorHintTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Database\Tls\ConnectionErrorHint;

function hintConnection(DatabaseConnectionType $type, ?SslMode $mode): ConnectionData
{
    return new ConnectionData(
        name: 'prod',
        type: $type,
        host: 'db.example.com',
        port: $type->defaultPort(),
        database: 'app',
        schema: null,
        username: 'u',
        password: '',
        isProduction: false,
        ssl: $mode instanceof SslMode ? new SslConfig($mode, $mode === SslMode::Verify ? 'ca.pem' : null) : null,
    );
}

it('tells the user to enable TLS when mysql requires secure transport', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, null), 'db.example.com'))
        ->toBe('The server requires TLS. Run "clonio connection:update prod" and set transport security to "require" or "verify".');
});

it('tells the user to enable TLS when pg_hba rejects unencrypted connections', function (): void {
    $e = new PDOException('SQLSTATE[08006] [7] FATAL:  no pg_hba.conf entry for host "10.0.0.5", user "u", database "app", no encryption');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Disable), 'db.example.com'))
        ->toContain('The server requires TLS.');
});

it('names CA and dialled host when mysql verify fails', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::MariaDB, SslMode::Verify), 'host.docker.internal'))
        ->toBe('TLS handshake failed. Likely causes: the CA file did not sign the server certificate, or the host name "host.docker.internal" is not in the certificate. Use mode "require" to skip verification.');
});

it('suggests disable when mysql require handshake fails', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, SslMode::Require), 'db.example.com'))
        ->toBe('TLS handshake failed. The server may not support TLS — use mode "disable" if the connection is on a trusted network.');
});

it('suggests disable when postgres has no TLS (stock docker image + default require)', function (): void {
    $e = new PDOException('SQLSTATE[08006] [7] server does not support SSL, but SSL was required');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Require), 'localhost'))
        ->toContain('use mode "disable"');
});

it('gives the verification hint for postgres certificate errors', function (string $message): void {
    $e = new PDOException($message);

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Verify), 'db.example.com'))
        ->toContain('TLS handshake failed. Likely causes');
})->with([
    'SQLSTATE[08006] [7] SSL error: certificate verify failed',
    'SQLSTATE[08006] [7] server certificate for "x" does not match host name "db.example.com"',
    'SQLSTATE[08006] [7] root certificate file "system" does not exist',
]);

it('recognises an explicit pg_hba hostnossl reject line', function (): void {
    $e = new PDOException('SQLSTATE[08006] [7] FATAL:  pg_hba.conf rejects connection for host "172.17.0.1", user "postgres", database "clonio_test", no encryption');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Disable), 'db'))
        ->toStartWith('The server requires TLS.');
});

it('suggests disable when mysqlnd reports [2006] while TLS was requested (server without TLS)', function (SslMode $mode): void {
    $e = new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, $mode), 'db'))
        ->toStartWith('TLS handshake failed. The server may not support TLS');
})->with([SslMode::Require, SslMode::Verify]);

it('does not treat [2006] as a TLS problem when TLS was not requested', function (?SslMode $mode): void {
    $e = new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, $mode), 'db'))->toBeNull();
})->with([null, SslMode::Disable]);

it('gives the verification hint for SQL Server ODBC certificate errors', function (): void {
    $e = new PDOException('SQLSTATE[08001]: [Microsoft][ODBC Driver 18 for SQL Server]SSL Provider: [error:0A000086:SSL routines::certificate verify failed:self-signed certificate]');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::SqlServer, null), '127.0.0.1'))
        ->toStartWith('TLS handshake failed. Likely causes');
});

it('returns null for unrelated errors', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [2002] Connection refused');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, SslMode::Require), 'db'))->toBeNull();
});
```

Append to `tests/Unit/Services/Database/DatabaseConnectionServiceTest.php` (add `use Illuminate\Support\Facades\DB;`):

```php
// ── open() hints ─────────────────────────────────────────────────────────────

it('appends a hint and keeps the driver error when open() fails on a known TLS error', function (): void {
    $original = new PDOException('SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.');
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow($original);
    DB::shouldReceive('purge')->once();

    try {
        (new DatabaseConnectionService(inDocker: false))->open(makeConnection(DatabaseConnectionType::Mysql));
        $this->fail('Expected exception');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('[3159]')
            ->and($runtimeException->getMessage())->toContain('The server requires TLS.')
            ->and($runtimeException->getPrevious())->toBe($original);
    }
});

it('names the docker-rewritten host in the verify hint', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('ca.pem', 'x');
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new PDOException('SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL'));
    DB::shouldReceive('purge');

    $connection = makeConnection(DatabaseConnectionType::Mysql, host: '127.0.0.1', ssl: new SslConfig(SslMode::Verify, 'ca.pem'));

    expect(fn (): string => (new DatabaseConnectionService(inDocker: true))->open($connection))
        ->toThrow(RuntimeException::class, 'the host name "host.docker.internal" is not in the certificate');
});

it('rethrows unrelated connection errors unchanged', function (): void {
    $original = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow($original);
    DB::shouldReceive('purge');

    expect(fn (): string => (new DatabaseConnectionService(inDocker: false))->open(makeConnection(DatabaseConnectionType::Mysql)))
        ->toThrow(PDOException::class, 'Connection refused');
});

// ── negotiatedCipher ─────────────────────────────────────────────────────────

it('reads the mysql session cipher', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']);

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::Mysql))
        ->toBe('TLS_AES_256_GCM_SHA384');
});

it('reads the postgres cipher from pg_stat_ssl', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['cipher' => 'TLS_AES_128_GCM_SHA256']);

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::PostgreSQL))
        ->toBe('TLS_AES_128_GCM_SHA256');
});

it('returns null when the connection is not encrypted or the query fails', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => ''], null);

    $service = new DatabaseConnectionService(inDocker: false);

    expect($service->negotiatedCipher('c1', DatabaseConnectionType::Mysql))->toBeNull()
        ->and($service->negotiatedCipher('c1', DatabaseConnectionType::PostgreSQL))->toBeNull()
        ->and($service->negotiatedCipher('c1', DatabaseConnectionType::SqlServer))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Database/`
Expected: FAIL with `Class "App\Services\Database\Tls\ConnectionErrorHint" not found`.

- [ ] **Step 3: Implement**

Create `app/Services/Database/Tls/ConnectionErrorHint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Database\Tls;

use App\Data\ConnectionData;
use App\Enums\SslMode;
use Throwable;

/**
 * Maps opaque driver TLS errors to actionable hints (PRD-connection-tls §8).
 * mysqlnd reports every TLS failure as the same "[2002] Cannot connect to MySQL using SSL",
 * so the configured mode decides which hint applies.
 */
final class ConnectionErrorHint
{
    public static function for(Throwable $throwable, ConnectionData $connection, ?string $host): ?string
    {
        $message = $throwable->getMessage();

        $serverRequiresTls = str_contains($message, '[3159]')
            || str_contains($message, 'insecure transport are prohibited')
            // "no pg_hba.conf entry …" and "pg_hba.conf rejects connection …" (hostnossl … reject)
            || (str_contains($message, 'pg_hba.conf') && str_contains($message, 'no encryption'));

        if ($serverRequiresTls) {
            return sprintf('The server requires TLS. Run "clonio connection:update %s" and set transport security to "require" or "verify".', $connection->name);
        }

        $mysqlHandshakeFailed = str_contains($message, 'Cannot connect to MySQL using SSL');
        $verificationFailed = str_contains($message, 'certificate verify failed')
            || str_contains($message, 'does not match host name')
            || str_contains($message, 'root certificate file');

        if ($verificationFailed || ($mysqlHandshakeFailed && $connection->ssl?->mode === SslMode::Verify)) {
            return sprintf('TLS handshake failed. Likely causes: the CA file did not sign the server certificate, or the host name "%s" is not in the certificate. Use mode "require" to skip verification.', $host ?? '');
        }

        // mysqlnd against a server without TLS: "[2006] MySQL server has gone away" (Docker spike, §8).
        // Only meaningful when TLS was requested; otherwise [2006] is an ordinary dropped connection.
        $mysqlServerWithoutTls = str_contains($message, '[2006]')
            && in_array($connection->ssl?->mode, [SslMode::Require, SslMode::Verify], true);

        if ($mysqlHandshakeFailed || $mysqlServerWithoutTls || str_contains($message, 'server does not support SSL')) {
            return 'TLS handshake failed. The server may not support TLS — use mode "disable" if the connection is on a trusted network.';
        }

        return null;
    }
}
```

In `DatabaseConnectionService`, add `use App\Services\Database\Tls\ConnectionErrorHint;` and replace `open()`:

```php
    /**
     * Opens a live database connection and returns its dynamic name.
     *
     * The caller must call DB::purge($name) when done to release the connection.
     * Known TLS failures are rethrown as RuntimeException with an actionable hint
     * appended; the driver exception is kept as previous.
     *
     * @throws RuntimeException if password decryption fails, the ssl config is invalid, or a TLS hint applies
     * @throws Throwable if the database connection cannot be established
     */
    public function open(ConnectionData $connection): string
    {
        $password = $this->resolvePassword($connection);
        $name = 'clonio_'.uniqid();

        config(['database.connections.'.$name => $this->buildConfig($connection, $password)]);

        try {
            DB::connection($name)->getPdo();
        } catch (Throwable $throwable) {
            DB::purge($name);

            $hint = ConnectionErrorHint::for($throwable, $connection, $this->resolvedHost($connection));

            if ($hint === null) {
                throw $throwable;
            }

            throw new RuntimeException($throwable->getMessage().PHP_EOL.$hint, 0, $throwable);
        }

        return $name;
    }

    /**
     * The negotiated TLS cipher of an open connection, or null (unencrypted, unsupported driver, query failed).
     */
    public function negotiatedCipher(string $connectionName, DatabaseConnectionType $type): ?string
    {
        [$sql, $column] = match ($type) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => ["SHOW SESSION STATUS LIKE 'Ssl_cipher'", 'Value'],
            DatabaseConnectionType::PostgreSQL => ['SELECT cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()', 'cipher'],
            default => [null, null],
        };

        if ($sql === null) {
            return null;
        }

        try {
            $row = DB::connection($connectionName)->selectOne($sql);
        } catch (Throwable) {
            return null;
        }

        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Database/`
Expected: PASS

- [ ] **Step 5: Check that callers of `open()` still pass**

`open()` now calls `DB::purge()` on failure, and it may throw `RuntimeException` instead of `PDOException`. Check that nothing catches `PDOException`/`QueryException` around `open()`:

Run: `grep -rn -e '->open(' app | cat` and `grep -rn "catch (PDOException\|catch (QueryException" app | cat`
Expected: no `PDOException`/`QueryException` catch wraps an `open()` call. The current callers catch `Throwable`/`RuntimeException` or let it bubble.

Run: `./vendor/bin/pest --parallel`
Expected: PASS

- [ ] **Step 6: Commit (proposed)**

```bash
git add app/Services/Database/Tls/ConnectionErrorHint.php app/Services/Database/DatabaseConnectionService.php tests/Unit/Services/Database/
git commit -m "feat(connection): explain TLS connection failures with actionable hints"
```

---

### Task 5: `connection:add` — options, prompt, default `require`

**Files:**
- Create: `app/Commands/Connection/Concerns/PromptsForSsl.php`
- Modify: `app/Commands/Connection/AddCommand.php`
- Test: `tests/Feature/Commands/Connection/AddCommandTest.php`

**Interfaces:**
- Consumes: `SslMode`, `SslConfig` (Task 1); `CertificateFiles` (Task 2).
- Produces the trait `PromptsForSsl`, which Task 6 also uses:
  - `askSslMode(?SslMode $default): ?SslMode`: choice `Transport security`; `null` = Driver default.
  - `askSslFiles(DatabaseConnectionType $type, SslMode $mode, ?SslConfig $current): SslConfig`
  - `askCertificatePath(string $label, ?string $current): ?string`
  - `sslErrors(DatabaseConnectionType $type, ?SslConfig $ssl): list<string>`
  - `warnIfKeyExposed(?SslConfig $ssl): void`
- Prompt texts, which tests match exactly:
  - `Transport security`, with choices `Require (encrypted, not verified)`, `Verify (encrypted + certificate check)`, `Disable (plaintext)`, `Driver default`.
  - `CA certificate path (leave empty for none)`
  - `Use a client certificate (mutual TLS)?`
  - `Client certificate path (leave empty for none)`
  - `Client key path (leave empty for none)`
  - With a stored value: `<Label> [<value>] (Enter = keep, "none" = remove)`.

- [ ] **Step 1: Write the failing tests**

Add to the imports of `tests/Feature/Commands/Connection/AddCommandTest.php`:

```php
use App\Data\ConnectionData;
use App\Enums\SslMode;
use Illuminate\Support\Facades\Storage;
```

Add a helper below `fakeConfigReadOnly()`:

```php
/** Config mock that asserts the saved connection matches $check. */
function fakeConfigExpecting(Closure $check): ConfigService
{
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn(false);
    $mock->shouldReceive('setConnection')->once()->withArgs(
        static fn (string $name, ConnectionData $data): bool => (bool) $check($data)
    );

    return $mock;
}

/** @return array<string, string> */
function mysqlFlags(): array
{
    return [
        'name' => 'my_db', '--type' => 'mysql', '--host' => 'db.example.com', '--port' => '3306',
        '--database' => 'mydb', '--username' => 'root', '--password' => 'secret',
    ];
}
```

Append the new tests:

```php
it('defaults new network connections to require without interaction', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require && ! $d->ssl->hasCertificateFiles()
    ));

    $this->artisan('connection:add', [...mysqlFlags(), '--no-interaction' => true])
        ->assertExitCode(0);
});

it('offers the transport modes in order', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Disable
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsChoice('Transport security', 'Disable (plaintext)', [
            'Require (encrypted, not verified)',
            'Verify (encrypted + certificate check)',
            'Disable (plaintext)',
            'Driver default',
        ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('stores no ssl block when Driver default is chosen', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl === null
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('creates a verify connection fully non-interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify && $d->ssl->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'verify', '--ssl-ca' => 'certs/ca.pem', '--no-interaction' => true])
        ->assertExitCode(0);
});

it('asks for the CA when verify is chosen interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', 'certs/ca.pem')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain('CA certificate')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('asks for client certificate and key when mutual TLS is confirmed', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0600);

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->cert === 'c.pem' && $d->ssl->key === 'k.pem'
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'yes')
        ->expectsQuestion('Client certificate path (leave empty for none)', 'c.pem')
        ->expectsQuestion('Client key path (leave empty for none)', 'k.pem')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('warns when the client key is readable by others', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0644);

    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'require', '--ssl-cert' => 'c.pem', '--ssl-key' => 'k.pem', '--no-interaction' => true])
        ->expectsOutputToContain('chmod 600')
        ->assertExitCode(0);
})->skipOnWindows();

it('rejects an unknown ssl mode', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'prefer'])
        ->expectsOutputToContain("Unknown ssl mode: 'prefer'. Valid modes: require, verify, disable.")
        ->assertExitCode(4);
});

it('rejects ssl options on sqlite', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'local', '--type' => 'sqlite', '--database' => '/tmp/a.db', '--ssl-mode' => 'require'])
        ->expectsOutputToContain('ssl is only supported for network connections')
        ->assertExitCode(4);
});

it('rejects certificate paths on sqlsrv', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [
        'name' => 'mssql', '--type' => 'sqlsrv', '--host' => 'h', '--port' => '1433', '--database' => 'd',
        '--username' => 'sa', '--password' => 'p', '--ssl-mode' => 'verify', '--ssl-ca' => 'ca.pem',
    ])
        ->expectsOutputToContain('sqlsrv uses the system trust store; certificate paths are not supported')
        ->assertExitCode(4);
});

it('rejects verify without a CA on mysql', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'verify', '--no-interaction' => true])
        ->expectsOutputToContain('mode verify requires a CA certificate for MySQL/MariaDB')
        ->assertExitCode(4);
});

it('rejects a CA file that does not exist, showing the resolved path', function (): void {
    Storage::fake('local');
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'verify', '--ssl-ca' => 'certs/missing.pem'])
        ->expectsOutputToContain(Storage::disk('local')->path('certs/missing.pem'))
        ->assertExitCode(4);
});

it('treats --trust-server-certificate as a deprecated alias for require on sqlsrv', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require && $d->trustServerCertificate === false
    ));

    $this->artisan('connection:add', [
        'name' => 'mssql', '--type' => 'sqlsrv', '--host' => 'h', '--port' => '1433', '--database' => 'd',
        '--username' => 'sa', '--password' => 'p', '--trust-server-certificate' => true, '--no-interaction' => true,
    ])->assertExitCode(0);
});
```

Update the existing tests. These go through the new prompt in the "network driver, no `--ssl-mode`" case:

- `successfully adds a MySQL connection with all options provided via flags`, `cancels when the user declines the save confirmation`, `prompts interactively for every MySQL field when no flags are given`, `prompts for the schema on a PostgreSQL connection`, `shows a production warning when the connection is marked production`, `returns an IO error when persisting the connection throws`. Insert these two lines directly before `->expectsConfirmation('Is this a production connection?', …)`:

```php
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
```

  (If one of these tests passes `--production`, there is no production confirm. Insert the two lines before `->expectsConfirmation('Save this connection?', …)` instead.)
- Replace `prompts for trust server certificate on a SQL Server connection` with:

```php
it('prompts for transport security on a SQL Server connection without asking for files', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify
    ));

    $this->artisan('connection:add', [
        'name' => 'mssql_db',
        '--type' => 'sqlsrv',
        '--host' => 'localhost',
        '--port' => '1433',
        '--database' => 'mydb',
        '--username' => 'sa',
        '--password' => 'secret',
    ])
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('Transport security')
        ->assertExitCode(0);
});
```

- The SQLite, dump, and early-failure tests (name, port, type, dialect, encryption) stay unchanged.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Commands/Connection/AddCommandTest.php`
Expected: FAIL. The new tests fail on unknown options (`The "--ssl-mode" option does not exist.`), and the updated tests fail on the unexpected question `Transport security`.

- [ ] **Step 3: Implement the trait**

Create `app/Commands/Connection/Concerns/PromptsForSsl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Commands\Connection\Concerns;

use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Database\Tls\CertificateFiles;

/**
 * Transport-security prompts shared by connection:add and connection:update (PRD-connection-tls §6).
 *
 * @mixin \LaravelZero\Framework\Commands\Command
 */
trait PromptsForSsl
{
    private const string DRIVER_DEFAULT_LABEL = 'Driver default';

    /** Returns null for "Driver default" (no ssl block). */
    private function askSslMode(?SslMode $default): ?SslMode
    {
        $labels = array_map(static fn (SslMode $mode): string => $mode->label(), SslMode::cases());
        $labels[] = self::DRIVER_DEFAULT_LABEL;

        $defaultIndex = $default instanceof SslMode
            ? (int) array_search($default->label(), $labels, true)
            : count($labels) - 1;

        $answer = $this->choice('Transport security', $labels, $defaultIndex);

        foreach (SslMode::cases() as $mode) {
            if ($mode->label() === $answer) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * Asks for the files the mode and driver can use; everything else is dropped.
     */
    private function askSslFiles(DatabaseConnectionType $type, SslMode $mode, ?SslConfig $current): SslConfig
    {
        if ($mode === SslMode::Disable || $type === DatabaseConnectionType::SqlServer) {
            return new SslConfig($mode);
        }

        $ca = $mode === SslMode::Verify ? $this->askCertificatePath('CA certificate path', $current?->ca) : null;
        $cert = null;
        $key = null;

        if ($this->confirm('Use a client certificate (mutual TLS)?', $current?->cert !== null)) {
            $cert = $this->askCertificatePath('Client certificate path', $current?->cert);
            $key = $this->askCertificatePath('Client key path', $current?->key);
        }

        return new SslConfig($mode, $ca, $cert, $key);
    }

    /**
     * Enter keeps $current, "none" removes it, anything else replaces it. No default is passed
     * to ask(), otherwise an empty answer could not be told apart from "keep".
     */
    private function askCertificatePath(string $label, ?string $current): ?string
    {
        $question = $current === null
            ? $label.' (leave empty for none)'
            : sprintf('%s [%s] (Enter = keep, "none" = remove)', $label, $current);

        $answer = $this->ask($question);

        if (! is_string($answer) || trim($answer) === '') {
            return $current;
        }

        return strtolower(trim($answer)) === 'none' ? null : trim($answer);
    }

    /** @return list<string> */
    private function sslErrors(DatabaseConnectionType $type, ?SslConfig $ssl): array
    {
        if (! $ssl instanceof SslConfig) {
            return [];
        }

        $errors = $ssl->violations($type);

        if ($errors !== []) {
            return $errors;
        }

        foreach (app(CertificateFiles::class)->unreadable($ssl) as $path) {
            $errors[] = 'Certificate file not found or not readable: '.$path;
        }

        return $errors;
    }

    private function warnIfKeyExposed(?SslConfig $ssl): void
    {
        if (! $ssl instanceof SslConfig || $ssl->key === null) {
            return;
        }

        $files = app(CertificateFiles::class);

        if ($files->isKeyExposed($ssl->key)) {
            $this->warn(sprintf('Client key %s is readable by other users. Run: chmod 600 %s', $ssl->key, $files->resolve($ssl->key)));
        }
    }
}
```

- [ ] **Step 4: Wire it into `AddCommand`**

In `app/Commands/Connection/AddCommand.php`:

1. Imports: `use App\Commands\Connection\Concerns\PromptsForSsl;`, `use App\Data\SslConfig;`, `use App\Enums\SslMode;`. Add `use PromptsForSsl;` as the first line of the class body.
2. Signature: replace the last line with:

```php
        {--production : Mark this as a production connection}
        {--ssl-mode= : Transport security — disable|require|verify (network drivers only, default: require)}
        {--ssl-ca= : Path to CA certificate (PEM), used with --ssl-mode=verify}
        {--ssl-cert= : Path to client certificate (PEM, mutual TLS)}
        {--ssl-key= : Path to client private key (PEM, mutual TLS)}
        {--trust-server-certificate : Deprecated — alias for --ssl-mode=require (SQL Server)}';
```

3. Replace the whole `// --- Step 9: Trust server certificate (SQL Server only) ---` block with:

```php
        // --- Step 9: Transport security (network drivers only) ---
        $ssl = null;
        $sslModeValue = $this->stringOption('ssl-mode');
        $sslCa = $this->stringOption('ssl-ca');
        $sslCert = $this->stringOption('ssl-cert');
        $sslKey = $this->stringOption('ssl-key');
        $hasSslFileOptions = $sslCa !== null || $sslCert !== null || $sslKey !== null;

        if (! $type->requiresNetworkConfig()) {
            if ($sslModeValue !== null || $hasSslFileOptions) {
                $this->error('ssl is only supported for network connections');

                return ExitCode::ValidationError->value;
            }
        } else {
            if ($sslModeValue === null && $type === DatabaseConnectionType::SqlServer && (bool) $this->option('trust-server-certificate')) {
                $sslModeValue = SslMode::Require->value;
            }

            if ($sslModeValue !== null) {
                $mode = SslMode::tryFrom($sslModeValue);

                if (! $mode instanceof SslMode) {
                    $this->error(sprintf("Unknown ssl mode: '%s'. Valid modes: %s.", $sslModeValue, implode(', ', SslMode::values())));

                    return ExitCode::ValidationError->value;
                }

                $ssl = new SslConfig($mode, $sslCa, $sslCert, $sslKey);
            } else {
                $mode = $this->askSslMode(SslMode::Require);

                if ($mode instanceof SslMode) {
                    $ssl = $hasSslFileOptions
                        ? new SslConfig($mode, $sslCa, $sslCert, $sslKey)
                        : $this->askSslFiles($type, $mode, null);
                } elseif ($hasSslFileOptions) {
                    $this->error('certificate files require mode require or verify');

                    return ExitCode::ValidationError->value;
                }
            }

            $sslErrors = $this->sslErrors($type, $ssl);

            if ($sslErrors !== []) {
                foreach ($sslErrors as $sslError) {
                    $this->error($sslError);
                }

                return ExitCode::ValidationError->value;
            }

            $this->warnIfKeyExposed($ssl);
        }
```

4. Summary: replace the `Trust certificate` block with:

```php
        if ($type->requiresNetworkConfig()) {
            $summaryRows[] = ['Transport security', $ssl instanceof SslConfig ? $ssl->mode->label() : 'Driver default'];

            if ($ssl?->ca !== null) {
                $summaryRows[] = ['CA certificate', $ssl->ca];
            }

            if ($ssl?->cert !== null) {
                $summaryRows[] = ['Client certificate', $ssl->cert];
            }

            if ($ssl?->key !== null) {
                $summaryRows[] = ['Client key', $ssl->key];
            }
        }
```

   (If PHPStan complains about `$ssl->ca` after the nullsafe check, use `$ssl instanceof SslConfig && $ssl->ca !== null`.)
5. Constructor call: replace `trustServerCertificate: $trustServerCertificate,` with `ssl: $ssl,` (the legacy flag is no longer written by `add`).
6. Add a helper at the end of the class:

```php
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Commands/Connection/AddCommandTest.php`
Expected: PASS

- [ ] **Step 6: Commit (proposed)**

```bash
git add app/Commands/Connection/Concerns/PromptsForSsl.php app/Commands/Connection/AddCommand.php tests/Feature/Commands/Connection/AddCommandTest.php
git commit -m "feat(connection): configure transport security in connection:add, default require"
```

---

### Task 6: `connection:update` — prompts, legacy migration, diff

**Files:**
- Modify: `app/Commands/Connection/UpdateCommand.php`
- Test: `tests/Feature/Commands/Connection/UpdateCommandTest.php`

**Interfaces:**
- Consumes: the `PromptsForSsl` trait (Task 5); `SslConfig`, `SslMode` (Task 1).
- Produces: no new public API.

- [ ] **Step 1: Write the failing tests**

Add imports to `UpdateCommandTest.php`: `use App\Data\SslConfig;`, `use App\Enums\SslMode;`, `use Illuminate\Support\Facades\Storage;`.

Extend the helper:

```php
function makeUpdateConnection(string $name = 'staging', ?SslConfig $ssl = null): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'encrypted:abc123',
        isProduction: false,
        ssl: $ssl,
    );
}

/** Config mock for "update staging" that asserts the saved connection. */
function fakeUpdateConfig(ConnectionData $current, Closure $check): ConfigService
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([$current->name => $current]);
    $config->shouldReceive('getConnection')->with($current->name)->andReturn($current);
    $config->shouldReceive('hasConnection')->andReturn(true);
    $config->shouldReceive('setConnection')->once()->withArgs(
        static fn (string $name, ConnectionData $data): bool => (bool) $check($data)
    );

    return $config;
}
```

Append:

```php
it('keeps a stored CA on Enter', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl?->ca === 'certs/ca.pem'));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path [certs/ca.pem] (Enter = keep, "none" = remove)', '')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('removes a stored CA on "none" and shows it in the diff (pgsql verify without CA is valid)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = new ConnectionData(
        name: 'pg', type: DatabaseConnectionType::PostgreSQL, host: 'localhost', port: 5432, database: 'mydb',
        schema: 'public', username: 'u', password: 'encrypted:abc', isProduction: false,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'),
    );

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify && $d->ssl->ca === null));

    $this->artisan('connection:update', ['name' => 'pg'])
        ->expectsQuestion('Connection name', 'pg')
        ->expectsQuestion('Database driver', 'pgsql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '5432')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'u')
        ->expectsQuestion('Schema', 'public')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path [certs/ca.pem] (Enter = keep, "none" = remove)', 'none')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Old', 'New'], [['ssl.ca', 'certs/ca.pem', '']])
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('drops the CA when switching from verify to require', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl == new SslConfig(SslMode::Require)));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('rejects an update that leaves mysql verify without a CA', function (): void {
    $current = makeUpdateConnection();
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($current);
    $config->shouldReceive('hasConnection')->andReturn(true);
    $config->shouldNotReceive('setConnection');
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', '')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain('mode verify requires a CA certificate for MySQL/MariaDB')
        ->assertExitCode(4);
});

it('removes ssl when the type changes to sqlite', function (): void {
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Require));

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl === null));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'sqlite')
        ->expectsQuestion('Database file path', '/tmp/a.db')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});
```

Replace `prompts for trust server certificate when updating a SQL Server connection` with the legacy-migration test:

```php
it('migrates the legacy SQL Server trust flag to ssl require', function (): void {
    $mssql = new ConnectionData(
        name: 'mssql',
        type: DatabaseConnectionType::SqlServer,
        host: 'localhost',
        port: 1433,
        database: 'mydb',
        schema: null,
        username: 'sa',
        password: 'encrypted:abc123',
        isProduction: false,
        trustServerCertificate: true,
    );

    $this->app->instance(ConfigService::class, fakeUpdateConfig($mssql, static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require && $d->trustServerCertificate === false));

    $this->artisan('connection:update', ['name' => 'mssql'])
        ->expectsQuestion('Connection name', 'mssql')
        ->expectsQuestion('Database driver', 'sqlsrv')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '1433')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'sa')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsChoice('Transport security', 'Require (encrypted, not verified)', [
            'Require (encrypted, not verified)',
            'Verify (encrypted + certificate check)',
            'Disable (plaintext)',
            'Driver default',
        ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});
```

Existing tests: every test where the **new** type is a network driver needs one more line directly after `->expectsQuestion('Password (press Enter to keep current)', '…')`:

```php
        ->expectsQuestion('Transport security', 'Driver default')
```

That covers `updates a connection when found`, `cancels update when user declines save confirmation`, `preserves existing password when empty input given`, `auto-selects connection when only one exists`, `renames a connection when the name changes`, `returns an IO error when persisting the update throws`, `prompts the user to choose when multiple connections exist and no name is given`, `uses default prompts when changing the driver type to another network database`, and `fails with validation error when renaming to an existing connection name`, if it reaches the password prompt. The SQLite tests stay unchanged.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Commands/Connection/UpdateCommandTest.php`
Expected: FAIL on the unexpected question `Transport security`, or on a missing expected question.

- [ ] **Step 3: Implement**

In `app/Commands/Connection/UpdateCommand.php`:

1. Imports: `use App\Commands\Connection\Concerns\PromptsForSsl;`, `use App\Data\SslConfig;`, `use App\Enums\SslMode;`. Add `use PromptsForSsl;` in the class body.
2. In `handle()`, directly after `$updated = $this->promptForFields($current);`:

```php
        $sslErrors = $this->sslErrors($updated->type, $updated->ssl);

        if ($sslErrors !== []) {
            foreach ($sslErrors as $sslError) {
                $this->error($sslError);
            }

            return ExitCode::ValidationError->value;
        }

        $this->warnIfKeyExposed($updated->ssl);
```

3. In `promptForFields()`, replace everything from `$isProduction = …` through the trust-certificate block with:

```php
        $ssl = $newType->requiresNetworkConfig() ? $this->promptForSsl($current, $newType) : null;

        $isProduction = $this->confirm('Is this a production connection?', $current->isProduction);
```

   In the `new ConnectionData(...)` call, replace `trustServerCertificate: $trustServerCertificate,` with:

```php
            dialect: $current->dialect,
            ssl: $ssl,
```

   Pass `dialect` explicitly so `update` no longer silently drops it. `trustServerCertificate` falls back to its `false` default, which completes the migration.
4. Add the method:

```php
    /**
     * Pre-selects the stored mode. Connections without ssl keep "Driver default", except a
     * legacy sqlsrv trust flag (migrated to require) and a switch from sqlite/dump (new default require).
     */
    private function promptForSsl(ConnectionData $current, DatabaseConnectionType $newType): ?SslConfig
    {
        $currentSsl = $current->type->requiresNetworkConfig() ? $current->ssl : null;

        $defaultMode = match (true) {
            $currentSsl instanceof SslConfig => $currentSsl->mode,
            $current->trustServerCertificate, ! $current->type->requiresNetworkConfig() => SslMode::Require,
            default => null,
        };

        $mode = $this->askSslMode($defaultMode);

        if (! $mode instanceof SslMode) {
            return null;
        }

        if ($newType === DatabaseConnectionType::SqlServer && $currentSsl?->hasCertificateFiles() === true) {
            $this->info('Certificate paths are not supported for sqlsrv and were removed.');
        }

        return $this->askSslFiles($newType, $mode, $currentSsl);
    }
```

5. In `showDiff()`, add after the `trust_server_certificate` entry:

```php
            'ssl.mode' => [$old->ssl?->mode->value ?? 'default', $new->ssl?->mode->value ?? 'default'],
            'ssl.ca' => [$old->ssl?->ca ?? '', $new->ssl?->ca ?? ''],
            'ssl.cert' => [$old->ssl?->cert ?? '', $new->ssl?->cert ?? ''],
            'ssl.key' => [$old->ssl?->key ?? '', $new->ssl?->key ?? ''],
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Commands/Connection/UpdateCommandTest.php`
Expected: PASS

- [ ] **Step 5: Commit (proposed)**

```bash
git add app/Commands/Connection/UpdateCommand.php tests/Feature/Commands/Connection/UpdateCommandTest.php
git commit -m "feat(connection): edit transport security in connection:update and migrate legacy trust flag"
```

---

### Task 7: `connection:list` TLS column and `connection:test` mode, hint, and cipher

**Files:**
- Modify: `app/Commands/Connection/ListCommand.php`
- Modify: `app/Commands/Connection/TestCommand.php`
- Test: `tests/Feature/Commands/Connection/ListCommandTest.php`, `tests/Feature/Commands/Connection/TestCommandTest.php`

**Interfaces:**
- Consumes:
  - `DatabaseConnectionService::open()` (Task 4), which now carries the hint.
  - `negotiatedCipher()` (Task 4).
  - `ConnectionData::$ssl` (Task 1).

- [ ] **Step 1: Write the failing tests**

`ListCommandTest.php`: add `use App\Data\SslConfig;` and `use App\Enums\SslMode;`, then append:

```php
it('shows the transport mode per connection', function (): void {
    $verify = new ConnectionData(
        name: 'prod', type: DatabaseConnectionType::Mysql, host: 'db', port: 3306, database: 'app',
        schema: null, username: 'u', password: 'encrypted:x', isProduction: true,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'),
    );
    $legacy = new ConnectionData(
        name: 'old', type: DatabaseConnectionType::PostgreSQL, host: 'db', port: 5432, database: 'app',
        schema: 'public', username: 'u', password: 'encrypted:x', isProduction: false,
    );
    $sqlite = new ConnectionData(
        name: 'local', type: DatabaseConnectionType::Sqlite, host: null, port: null, database: 'a.db',
        schema: null, username: null, password: '', isProduction: false,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['prod' => $verify, 'old' => $legacy, 'local' => $sqlite]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:list')
        ->expectsTable(['Name', 'Driver', 'Host', 'Database', 'TLS', 'Production'], [
            ['prod', 'mysql', 'db:3306', 'app', 'verify', 'Yes'],
            ['old', 'pgsql', 'db:5432', 'app', 'default', 'No'],
            ['local', 'sqlite', '—', 'a.db', '—', 'No'],
        ])
        ->assertExitCode(0);
});
```

`TestCommandTest.php`: add `use App\Data\SslConfig;` and `use App\Enums\SslMode;`, then append:

```php
it('shows the transport mode on success', function (): void {
    $connection = new ConnectionData(
        name: 'staging', type: DatabaseConnectionType::Mysql, host: 'localhost', port: 3306, database: 'mydb',
        schema: null, username: 'root', password: 'secret', isProduction: false, ssl: new SslConfig(SslMode::Require),
    );
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('tls: require')
        ->assertExitCode(ExitCode::Success->value);
});

it('prints the negotiated cipher with -v', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeMysqlConnection('staging'));
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('selectOne')->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']);
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging', '-v' => true])
        ->expectsOutputToContain('TLS cipher: TLS_AES_256_GCM_SHA384')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows the TLS hint when the server requires secure transport', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeMysqlConnection('staging'));
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new PDOException('SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.'));
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('The server requires TLS.')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('fails with exit 3 before connecting when a certificate file is missing', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    $connection = new ConnectionData(
        name: 'staging', type: DatabaseConnectionType::Mysql, host: 'localhost', port: 3306, database: 'mydb',
        schema: null, username: 'root', password: 'secret', isProduction: false,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'),
    );
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('getPdo')->never();

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('Certificate file not found:')
        ->assertExitCode(ExitCode::ConnectionError->value);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Commands/Connection/ListCommandTest.php tests/Feature/Commands/Connection/TestCommandTest.php`
Expected: FAIL. The table headers differ, `tls:` is missing, and the missing-file test gets an uncaught `RuntimeException` because `buildConfig` currently runs outside the try.

- [ ] **Step 3: Implement `ListCommand`**

Replace the row and header code:

```php
            $tls = $connection->type->requiresNetworkConfig()
                ? ($connection->ssl?->mode->value ?? 'default')
                : '—';

            $rows[] = [
                $name,
                $connection->type->value,
                $host,
                $connection->database ?? '—',
                $tls,
                $connection->isProduction ? 'Yes' : 'No',
            ];
        }

        $this->table(['Name', 'Driver', 'Host', 'Database', 'TLS', 'Production'], $rows);
```

- [ ] **Step 4: Implement `TestCommand`**

1. In `testSingle()`, replace the success `else` branch:

```php
                } elseif ($connection->type === DatabaseConnectionType::Sqlite) {
                    $this->line(sprintf('%s: OK (%dms)', $name, $elapsed));
                } else {
                    $this->line(sprintf('%s: OK (%dms, tls: %s)', $name, $elapsed, $connection->ssl?->mode->value ?? 'default'));

                    if ($message !== '') {
                        $this->line('  '.$message);
                    }
                }
```

2. Replace `testNetwork()`, which now goes through `open()` so file checks and hints apply, and drop the unused `use Illuminate\Support\Facades\DB;` only if nothing else uses it (`DB::purge` still does):

```php
    /**
     * On success the message carries the negotiated cipher (verbose only), or ''.
     *
     * @return array{bool, string, int, ExitCode}
     */
    private function testNetwork(ConnectionData $connection, int $start, DatabaseConnectionService $connector): array
    {
        try {
            $connector->resolvePassword($connection);
        } catch (RuntimeException) {
            return [false, 'Could not decrypt password — check APP_KEY.', $this->elapsedMs($start), ExitCode::ConfigError];
        }

        try {
            $dynamicName = $connector->open($connection);
        } catch (Throwable $throwable) {
            return [false, $throwable->getMessage(), $this->elapsedMs($start), ExitCode::ConnectionError];
        }

        $elapsed = $this->elapsedMs($start);
        $cipher = $this->output->isVerbose() ? $connector->negotiatedCipher($dynamicName, $connection->type) : null;

        DB::purge($dynamicName);

        return [true, $cipher !== null ? 'TLS cipher: '.$cipher : '', $elapsed, ExitCode::Success];
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Commands/Connection/`
Expected: PASS, including the existing `local: OK` and `FAILED` assertions.

- [ ] **Step 6: Commit (proposed)**

```bash
git add app/Commands/Connection/ListCommand.php app/Commands/Connection/TestCommand.php tests/Feature/Commands/Connection/ListCommandTest.php tests/Feature/Commands/Connection/TestCommandTest.php
git commit -m "feat(connection): show transport mode in list/test and report TLS hints and cipher"
```

---

### Task 8: JSON schema and docs

**Files:**
- Modify: `resources/schema/clonio.schema.json`
- Modify: `docs/commands/connection-add.md`, `connection-update.md`, `connection-list.md`, `connection-test.md`

- [ ] **Step 1: Schema**

In `resources/schema/clonio.schema.json`, in the network branch (`"then"` of the `mysql|mariadb|pgsql|sqlsrv` condition), add after `"is_production"`:

```json
              "trust_server_certificate": {
                "type": "boolean",
                "description": "Deprecated (SQL Server). Use ssl.mode = require instead. Ignored when ssl is set."
              },
              "ssl": {
                "type": "object",
                "additionalProperties": false,
                "required": ["mode"],
                "description": "Transport security (TLS) for this connection. Absent = driver default.",
                "properties": {
                  "mode": {
                    "type": "string",
                    "enum": ["disable", "require", "verify"],
                    "description": "disable = plaintext; require = encrypted, certificate not verified; verify = encrypted, CA and hostname verified."
                  },
                  "ca": { "type": "string", "description": "Path to the CA certificate (PEM). Only with mode verify. Relative paths resolve against the working directory." },
                  "cert": { "type": "string", "description": "Path to the client certificate (PEM) for mutual TLS." },
                  "key": { "type": "string", "description": "Path to the client private key (PEM) for mutual TLS." }
                },
                "dependencies": { "cert": ["key"], "key": ["cert"] }
              }
```

Also add `"ssl": { "type": "object" }` and `"trust_server_certificate": { "type": "boolean" }` to the top-level `properties` list of the connection definition (next to `"is_production": { "type": "boolean" }`). The sqlite branch keeps `additionalProperties: false` without `ssl`, so `ssl` on sqlite is rejected.

Validate syntax: `php -r 'json_decode(file_get_contents("resources/schema/clonio.schema.json"), flags: JSON_THROW_ON_ERROR); echo "ok\n";'`
Expected: `ok`

- [ ] **Step 2: `docs/commands/connection-add.md`**

- In "Interactive flow", insert a new step 9 and renumber production to 10:

```markdown
9. **Transport security** — Network drivers only. `Require (encrypted, not verified)` (default), `Verify (encrypted + certificate check)`, `Disable (plaintext)` or `Driver default`. With `Verify`, a CA certificate path is asked (not for SQL Server). With `Require`/`Verify`, you can add a client certificate and key for mutual TLS.
```

- Options table, replacing nothing, adding after `--password=`:

```markdown
| `--ssl-mode=` | Transport security: `disable`, `require`, `verify` (network drivers; default `require`) |
| `--ssl-ca=` | CA certificate (PEM). Only with `--ssl-mode=verify`; required for MySQL/MariaDB verify |
| `--ssl-cert=` | Client certificate (PEM) for mutual TLS; requires `--ssl-key` |
| `--ssl-key=` | Client private key (PEM) for mutual TLS; requires `--ssl-cert` |
| `--trust-server-certificate` | Deprecated alias for `--ssl-mode=require` (SQL Server) |
```

- Exit code 4 row: append `, invalid ssl mode or certificate options, certificate file not found`.
- New section under "Notes":

````markdown
### Transport security (TLS)

New network connections use `require` by default: the connection is encrypted, the server certificate is not verified. This is what servers with `require_secure_transport=ON` need.

| Mode | Encrypted | Certificate verified |
|---|---|---|
| `disable` | No | — |
| `require` | Yes | No |
| `verify` | Yes | CA **and** host name |

Certificate paths are stored as entered. `~/` resolves to your home directory; relative paths resolve against the current working directory (next to `clonio.json`). Only paths are stored, never certificate contents.

Servers without TLS (for example the stock `postgres` Docker image) need `--ssl-mode=disable`.

```bash
clonio connection:add prod --type=mysql --host=db.example.com --port=3306 \
  --database=app --username=clonio --password=... \
  --ssl-mode=verify --ssl-ca=certs/prod-ca.pem --production --no-interaction
```
````

- [ ] **Step 3: `docs/commands/connection-update.md`**

After the password step, add:

```markdown
5. **Transport security** — Pre-selected with the stored mode (`Driver default` if none is stored). Certificate path prompts show the stored value: press Enter to keep it, type `none` to remove it, or enter a new path. Switching to `require` drops the CA; switching to `disable` or `Driver default` drops all certificate paths. A legacy SQL Server `trust_server_certificate: true` is pre-selected as `require` and replaced by `ssl: { mode: require }` on save.
```

Renumber the following steps. Add to the notes: `The diff shows ssl.mode, ssl.ca, ssl.cert and ssl.key changes before saving.`

- [ ] **Step 4: `docs/commands/connection-list.md`**

Update the example table to include a `TLS` column between `Database` and `Production` (values `—`, `require`, `verify`), and add this row to the column table:

```markdown
| **TLS** | Transport security mode: `default` (no `ssl` set), `disable`, `require`, `verify`. `—` for SQLite and dump |
```

- [ ] **Step 5: `docs/commands/connection-test.md`**

Change the single-connection success example to `staging: OK (42ms, tls: require)`, and add:

```markdown
With `-v`, a successful MySQL/MariaDB/PostgreSQL test also prints the negotiated cipher (`TLS cipher: TLS_AES_256_GCM_SHA384`). This is the only query sent beyond the handshake.

TLS failures include a hint, for example:

    staging: FAILED — SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.
    The server requires TLS. Run "clonio connection:update staging" and set transport security to "require" or "verify".
```

- [ ] **Step 6: Commit (proposed)**

```bash
git add resources/schema/clonio.schema.json docs/commands/connection-*.md
git commit -m "docs(connection): document transport security and extend clonio.json schema"
```

---

### Task 9: CI — existing workflows plus the end-to-end TLS matrix

**Files:**
- Modify: `.github/workflows/connection-test.yml` (postgres add)
- Modify: `.github/workflows/cloning-run-test.yml` (both pgsql adds)
- Create: `.github/tls/make-certs.sh`: throwaway PKI (test CA, wrong CA, server cert, client cert)
- Create: `.github/tls/start-server.sh`: starts one driver in one posture
- Create: `.github/tls/run-matrix.sh`: registers every case, runs `connection:test`, asserts
- Create: `.github/tls/cases/mysql.cases`, `pgsql.cases`, `sqlsrv.cases`: the expected outcomes from spec §10.1
- Create: `.github/workflows/connection-tls-matrix.yml`

**Interfaces:**
- Consumes the CLI surface of Tasks 5–7:
  - `connection:add --ssl-mode= --ssl-ca= --ssl-cert= --ssl-key= --trust-server-certificate`
  - `connection:test <name> -v`, which prints `<name>: OK (<n>ms, tls: <mode|default>)` and, for an encrypted MySQL/MariaDB/PostgreSQL connection, `TLS cipher: …`
  - The hint texts from Task 4: `The server requires TLS`, `TLS handshake failed. Likely causes … host name "<host>"`, `TLS handshake failed. The server may not support TLS`, and `Certificate file not found`.
- Produces: the blocking workflow `connection TLS matrix` with 15 jobs (driver × posture).

**How the matrix is wired:** one job per driver × posture. `start-server.sh` starts a single container named `clonio-tls-db` in that posture. `run-matrix.sh` then walks every case row of that posture from the driver family's `.cases` file. For each row it:
1. registers a connection named `tls-<case>`,
2. runs `connection:test tls-<case> -v`,
3. asserts the exit code, the required substrings and the forbidden substrings.

All rows run even after a failure, and the job fails at the end if any row failed. Adding a case or posture means adding rows, not YAML.

- [ ] **Step 1: Stock Postgres in the existing workflows**

The stock `postgres:16` image has no TLS, so the new default `require` would fail there (spec §10, last criterion).

In `.github/workflows/connection-test.yml`, step `Register connections via connection:add`, change the postgres call to:

```yaml
          php clonio connection:add postgres-test \
            --type=pgsql --host=127.0.0.1 --port=5432 \
            --database=clonio_test --username=postgres --password=secret \
            --schema=public --ssl-mode=disable --no-interaction
```

In `.github/workflows/cloning-run-test.yml`, change both pgsql calls in the same way (source at ~line 223, target at ~line 251). Only the last line changes:

```yaml
              --schema=public --ssl-mode=disable --no-interaction
```

Leave the rest alone:
- The `mysql:8.0` and `mariadb:11` stock images ship self-signed TLS and accept `require`, as the spike showed.
- `sqlsrv` keeps `--trust-server-certificate`, which now maps to `require`.
- `cloning-dump-test.yml` only adds a mysql source and a dump target, so it needs no change.

- [ ] **Step 2: `.github/tls/make-certs.sh`**

```bash
#!/usr/bin/env bash
# Throwaway PKI for the TLS matrix (PRD-connection-tls §10.1). Valid for two days, never reused.
# Usage: make-certs.sh <dir>
set -euo pipefail

dir="${1:?usage: make-certs.sh <dir>}"
mkdir -p "$dir"
cd "$dir"

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Clonio Test CA" \
  -keyout ca-key.pem -out ca.pem
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Clonio Wrong CA" \
  -keyout wrong-ca-key.pem -out wrong-ca.pem

openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" -keyout server-key.pem -out server.csr
printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' > server.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 2 \
  -extfile server.ext -out server.pem

openssl req -newkey rsa:2048 -nodes -subj "/CN=clonio-client" -keyout client-key.pem -out client.csr
printf 'extendedKeyUsage=clientAuth\n' > client.ext
openssl x509 -req -in client.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 2 \
  -extfile client.ext -out client.pem

rm -f ./*.csr ./*.ext ./*.srl
# Server processes in the containers (mysql uid 999, mssql uid 10001) must read the server key.
chmod 644 ./*.pem
# Clonio warns about group/world-readable client keys (§7); keep the matrix output free of that noise.
chmod 600 client-key.pem
```

- [ ] **Step 3: `.github/tls/start-server.sh`**

```bash
#!/usr/bin/env bash
# Starts one database server in one TLS posture (PRD-connection-tls §10.1) as container "clonio-tls-db".
# Usage: start-server.sh <mysql|mariadb|pgsql|sqlsrv> <posture> <certs-dir>
set -euo pipefail

driver="$1"
posture="$2"
certs="$(cd "$3" && pwd)"
name=clonio-tls-db

wait_for() { # wait_for <what> <command...>
  local what="$1"
  shift
  for _ in $(seq 1 90); do
    if "$@" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "timeout waiting for $what" >&2
  docker logs "$name" 2>&1 | tail -50 >&2
  exit 1
}

unknown_posture() {
  echo "unknown posture '$posture' for $driver" >&2
  exit 2
}

# A config directory the container user can read (mktemp -d is 0700).
conf_dir() {
  local dir
  dir="$(mktemp -d)"
  chmod 755 "$dir"
  echo "$dir"
}

case "$driver" in
  mysql | mariadb)
    if [ "$driver" = mysql ]; then
      image=mysql:8.4 env=MYSQL cli=mysql plain=(--tls-version=)
    else
      image=mariadb:11 env=MARIADB cli=mariadb plain=(--skip-ssl)
    fi
    tls=(--ssl-ca=/certs/ca.pem --ssl-cert=/certs/server.pem --ssl-key=/certs/server-key.pem)
    case "$posture" in
      plain) args=("${plain[@]}") ;;
      tls) args=("${tls[@]}") ;;
      tls-required | mtls-required) args=("${tls[@]}" --require-secure-transport=ON) ;;
      *) unknown_posture ;;
    esac

    docker run -d --name "$name" -p 3306:3306 \
      -e "${env}_ROOT_PASSWORD=secret" -e "${env}_DATABASE=clonio_test" \
      -v "$certs:/certs:ro" "$image" "${args[@]}"

    # The init phase runs a temporary server without networking ("port: 0"). Only the final
    # server logs "port: 3306". The X plugin's "port: 33060" must not match.
    wait_for "$image" sh -c "docker logs $name 2>&1 | grep -Eq 'port: 3306([^0-9]|\$)'"

    if [ "$posture" = mtls-required ]; then
      # root@localhost (socket) stays usable for this statement; the matrix connects as root@'%'.
      docker exec "$name" "$cli" -uroot -psecret -e "ALTER USER 'root'@'%' REQUIRE X509; FLUSH PRIVILEGES;"
    fi
    ;;

  pgsql)
    if [ "$posture" = plain ]; then
      docker run -d --name "$name" -p 5432:5432 \
        -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=clonio_test postgres:16
    else
      case "$posture" in
        tls) hba='host all all all scram-sha-256' ;;
        tls-required) hba=$'hostnossl all all all reject\nhostssl all all all scram-sha-256' ;;
        mtls-required) hba=$'hostnossl all all all reject\nhostssl all all all scram-sha-256 clientcert=verify-ca' ;;
        *) unknown_posture ;;
      esac
      conf="$(conf_dir)"
      # "local … trust" keeps the image's init scripts working over the socket.
      printf 'local all all trust\n%s\n' "$hba" > "$conf/pg_hba.conf"
      chmod 644 "$conf/pg_hba.conf"

      # PostgreSQL refuses a server key that is not owned by postgres with mode 0600, so copy the
      # certs inside the container before handing over to the stock entrypoint.
      docker run -d --name "$name" -p 5432:5432 \
        -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=clonio_test \
        -v "$certs:/certs-src:ro" -v "$conf:/etc/clonio:ro" \
        --entrypoint bash postgres:16 -c '
          set -e
          install -d -o postgres -g postgres /certs
          install -o postgres -g postgres -m 600 /certs-src/ca.pem /certs-src/server.pem /certs-src/server-key.pem /certs/
          exec docker-entrypoint.sh postgres -c ssl=on \
            -c ssl_ca_file=/certs/ca.pem -c ssl_cert_file=/certs/server.pem -c ssl_key_file=/certs/server-key.pem \
            -c hba_file=/etc/clonio/pg_hba.conf'
    fi

    # The init server listens on the socket only, so TCP readiness means the final server is up.
    # pg_isready reports "accepting connections" even when pg_hba would reject it.
    wait_for postgres docker exec "$name" pg_isready -h 127.0.0.1 -U postgres
    ;;

  sqlsrv)
    image=mcr.microsoft.com/mssql/server:2022-latest
    run=(docker run -d --name "$name" -p 1433:1433 -e ACCEPT_EULA=Y -e "MSSQL_SA_PASSWORD=Clonio@Strong1" -e MSSQL_PID=Express)
    case "$posture" in
      self-signed)
        "${run[@]}" "$image"
        ;;
      tls | tls-required)
        if [ "$posture" = tls-required ]; then force=1; else force=0; fi
        conf="$(conf_dir)"
        printf '[network]\ntlscert = /certs/server.pem\ntlskey = /certs/server-key.pem\ntlsprotocols = 1.2\nforceencryption = %s\n' \
          "$force" > "$conf/mssql.conf"
        chmod 644 "$conf/mssql.conf"
        "${run[@]}" -v "$certs:/certs:ro" -v "$conf/mssql.conf:/var/opt/mssql/mssql.conf:ro" "$image"

        # The sqlsrv `verify` mode (and ODBC 18's default) use the OS trust store (§5.3).
        sudo cp "$certs/ca.pem" /usr/local/share/ca-certificates/clonio-test-ca.crt
        sudo update-ca-certificates
        ;;
      *) unknown_posture ;;
    esac

    wait_for "SQL Server" docker exec "$name" /opt/mssql-tools18/bin/sqlcmd \
      -S localhost -U sa -P 'Clonio@Strong1' -Q 'SELECT 1' -b -C
    ;;

  *)
    echo "unknown driver '$driver'" >&2
    exit 2
    ;;
esac

echo "$driver ($posture) is up"
```

- [ ] **Step 4: The cases files**

These files are a transcription of the spec §10.1 tables. Columns are `posture case expected`, separated by whitespace; `#` starts a comment. `OK-c` is spelled with an ASCII hyphen.

`.github/tls/cases/mysql.cases` (used for both `mysql` and `mariadb`):

```text
# posture      case                  expected    (PRD-connection-tls §10.1 — keep in sync)
plain          absent                OK-c
tls            absent                OK-c
tls-required   absent                F:req
mtls-required  absent                F:req
plain          disable               OK-c
tls            disable               OK-c
tls-required   disable               F:req
mtls-required  disable               F:req
plain          require               F:tls
tls            require               OK+c
tls-required   require               OK+c
mtls-required  require               F
plain          verify                F:tls
tls            verify                OK+c
tls-required   verify                OK+c
mtls-required  verify                F
plain          verify-wrong-ca       F:tls
tls            verify-wrong-ca       F:ver
tls-required   verify-wrong-ca       F:ver
mtls-required  verify-wrong-ca       F:ver
plain          verify-host-mismatch  F:tls
tls            verify-host-mismatch  F:host
tls-required   verify-host-mismatch  F:host
mtls-required  verify-host-mismatch  F:host
plain          require-mtls          F:tls
tls            require-mtls          OK+c
tls-required   require-mtls          OK+c
mtls-required  require-mtls          OK+c
plain          verify-mtls           F:tls
tls            verify-mtls           OK+c
tls-required   verify-mtls           OK+c
mtls-required  verify-mtls           OK+c
plain          missing-file          F:file
tls            missing-file          F:file
tls-required   missing-file          F:file
mtls-required  missing-file          F:file
```

`.github/tls/cases/pgsql.cases`:

```text
# posture      case                  expected    (PRD-connection-tls §10.1 — keep in sync; absent = libpq prefer)
plain          absent                OK-c
tls            absent                OK+c
tls-required   absent                OK+c
mtls-required  absent                F
plain          disable               OK-c
tls            disable               OK-c
tls-required   disable               F:req
mtls-required  disable               F:req
plain          require               F:tls
tls            require               OK+c
tls-required   require               OK+c
mtls-required  require               F
plain          verify                F:tls
tls            verify                OK+c
tls-required   verify                OK+c
mtls-required  verify                F
plain          verify-wrong-ca       F:tls
tls            verify-wrong-ca       F:ver
tls-required   verify-wrong-ca       F:ver
mtls-required  verify-wrong-ca       F:ver
plain          verify-host-mismatch  F:tls
tls            verify-host-mismatch  F:host
tls-required   verify-host-mismatch  F:host
mtls-required  verify-host-mismatch  F:host
plain          verify-system         F:tls
tls            verify-system         F:ver
tls-required   verify-system         F:ver
mtls-required  verify-system         F:ver
plain          require-mtls          F:tls
tls            require-mtls          OK+c
tls-required   require-mtls          OK+c
mtls-required  require-mtls          OK+c
plain          verify-mtls           F:tls
tls            verify-mtls           OK+c
tls-required   verify-mtls           OK+c
mtls-required  verify-mtls           OK+c
plain          missing-file          F:file
tls            missing-file          F:file
tls-required   missing-file          F:file
mtls-required  missing-file          F:file
```

`.github/tls/cases/sqlsrv.cases`:

```text
# posture      case                  expected    (PRD-connection-tls §10.1 — keep in sync; no cipher query for sqlsrv)
self-signed    absent                F:ver
tls            absent                OK
tls-required   absent                OK
self-signed    legacy-trust          OK
tls            legacy-trust          OK
tls-required   legacy-trust          OK
self-signed    disable               OK
tls            disable               OK
# † from Microsoft's ODBC 18 encryption table, not from the spike. If CI disagrees, fix spec + docs, never this row alone.
tls-required   disable               OK
self-signed    require               OK
tls            require               OK
tls-required   require               OK
self-signed    verify                F:ver
tls            verify                OK
tls-required   verify                OK
self-signed    verify-host-mismatch  F:ver
tls            verify-host-mismatch  F:host
tls-required   verify-host-mismatch  F:host
```

- [ ] **Step 5: `.github/tls/run-matrix.sh`**

```bash
#!/usr/bin/env bash
# Registers every client configuration for one server posture, runs connection:test and asserts the
# outcome listed in .github/tls/cases/<family>.cases (PRD-connection-tls §10.1).
# Usage (from the repo root, APP_KEY set): run-matrix.sh <driver> <posture> <certs-dir>
set -uo pipefail

driver="$1"
posture="$2"
certs="$3" # passed to connection:add as given; CI uses the relative "certs" to exercise cwd resolution
here="$(cd "$(dirname "$0")" && pwd)"

if [ -e clonio.json ]; then
  echo "run-matrix.sh needs a directory without clonio.json" >&2
  exit 2
fi

# Host-mismatch cases dial a non-loopback address that is not in the server certificate's SAN.
lan_ip="${CLONIO_TLS_LAN_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
if [ -z "$lan_ip" ]; then
  echo "no non-loopback IP found; set CLONIO_TLS_LAN_IP" >&2
  exit 2
fi

case "$driver" in
  mysql | mariadb)
    family=mysql host=127.0.0.1 port=3306
    db=(--database=clonio_test --username=root --password=secret)
    ;;
  pgsql)
    family=pgsql host=127.0.0.1 port=5432
    db=(--database=clonio_test --username=postgres --password=secret --schema=public)
    ;;
  sqlsrv)
    # localhost rather than 127.0.0.1: DNS SANs are matched by every TLS stack, IP SANs not necessarily by ODBC.
    family=sqlsrv host=localhost port=1433
    db=(--database=master --username=sa --password=Clonio@Strong1)
    ;;
  *)
    echo "unknown driver '$driver'" >&2
    exit 2
    ;;
esac

ca="$certs/ca.pem"
client=(--ssl-cert="$certs/client.pem" --ssl-key="$certs/client-key.pem")
# sqlsrv verifies against the system trust store; certificate paths are a validation error there (§7).
if [ "$family" = sqlsrv ]; then
  verify=(--ssl-mode=verify)
else
  verify=(--ssl-mode=verify --ssl-ca="$ca")
fi

add() { # add <name> <host> [options...]
  local name="$1" dial="$2"
  shift 2
  php clonio connection:add "$name" --type="$driver" --host="$dial" --port="$port" "${db[@]}" "$@" --no-interaction
}

# Rewrites one connection in clonio.json, keeping the file's permissions.
edit_json() { # edit_json <jq filter> <name>
  local tmp
  tmp="$(mktemp)"
  jq --arg n "$2" "$1" clonio.json > "$tmp" && cat "$tmp" > clonio.json
  rm -f "$tmp"
}

setup_case() { # setup_case <case> <connection name>
  local c="$1" n="$2"
  case "$c" in
    absent) add "$n" "$host" && edit_json 'del(.connections[$n].ssl)' "$n" ;;
    legacy-trust) add "$n" "$host" && edit_json 'del(.connections[$n].ssl) | .connections[$n].trust_server_certificate = true' "$n" ;;
    disable | require) add "$n" "$host" --ssl-mode="$c" ;;
    verify) add "$n" "$host" "${verify[@]}" ;;
    verify-wrong-ca) add "$n" "$host" --ssl-mode=verify --ssl-ca="$certs/wrong-ca.pem" ;;
    verify-host-mismatch) add "$n" "$lan_ip" "${verify[@]}" ;;
    verify-system) add "$n" "$host" --ssl-mode=verify ;;
    require-mtls) add "$n" "$host" --ssl-mode=require "${client[@]}" ;;
    verify-mtls) add "$n" "$host" "${verify[@]}" "${client[@]}" ;;
    missing-file)
      # add checks that the file exists (§7), so create it, register, then delete it.
      cp "$ca" "$certs/deleted-ca.pem" &&
        add "$n" "$host" --ssl-mode=verify --ssl-ca="$certs/deleted-ca.pem" &&
        rm "$certs/deleted-ca.pem"
      ;;
    *)
      echo "unknown case '$c'" >&2
      return 2
      ;;
  esac
}

mode_label() { # the "tls: …" value connection:test prints for a case
  case "$1" in
    absent | legacy-trust) echo default ;;
    disable) echo disable ;;
    require | require-mtls) echo require ;;
    *) echo verify ;;
  esac
}

total=0
failures=0

while read -r case expected; do
  total=$((total + 1))
  name="tls-$case"
  echo "::group::$driver / $posture / $case (expect $expected)"

  if ! setup_case "$case" "$name"; then
    echo "::endgroup::"
    echo "::error::$driver/$posture/$case: connection:add failed"
    failures=$((failures + 1))
    continue
  fi

  out="$(php clonio connection:test "$name" -v 2>&1)"
  code=$?
  printf '%s\nexit code: %s\n' "$out" "$code"

  want=3
  need=()
  forbid=()
  case "$expected" in
    OK) want=0 need=("tls: $(mode_label "$case")") ;;
    OK+c) want=0 need=("tls: $(mode_label "$case")" "TLS cipher:") ;;
    OK-c) want=0 need=("tls: $(mode_label "$case")") forbid=("TLS cipher:") ;;
    F) ;;
    F:req) need=("The server requires TLS") ;;
    F:tls) need=("TLS handshake failed") ;;
    F:ver) need=("TLS handshake failed. Likely causes") ;;
    F:host) need=("TLS handshake failed. Likely causes" "\"$lan_ip\"") ;;
    F:file) need=("Certificate file not found") ;;
    *)
      echo "unknown expectation '$expected' in $family.cases" >&2
      exit 2
      ;;
  esac

  ok=1
  if [ "$code" -ne "$want" ]; then
    echo "✗ exit code $code, expected $want"
    ok=0
  fi
  # ${a[@]+"${a[@]}"}: empty arrays under `set -u` on bash 3.2 (macOS rehearsal).
  for s in ${need[@]+"${need[@]}"}; do
    if ! grep -qF -- "$s" <<< "$out"; then
      echo "✗ missing: $s"
      ok=0
    fi
  done
  for s in ${forbid[@]+"${forbid[@]}"}; do
    if grep -qF -- "$s" <<< "$out"; then
      echo "✗ must not contain: $s"
      ok=0
    fi
  done
  echo "::endgroup::"

  if [ "$ok" -eq 1 ]; then
    echo "✓ $case → $expected"
  else
    echo "::error::$driver/$posture/$case expected $expected"
    failures=$((failures + 1))
  fi
done < <(awk -v p="$posture" '!/^#/ && NF && $1 == p { print $2, $3 }' "$here/cases/$family.cases")

if [ "$total" -eq 0 ]; then
  echo "no cases for posture '$posture' in $family.cases" >&2
  exit 2
fi

echo "$((total - failures))/$total cases passed for $driver / $posture"
[ "$failures" -eq 0 ]
```

- [ ] **Step 6: `.github/workflows/connection-tls-matrix.yml`**

```yaml
name: connection TLS matrix

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  # PRD-connection-tls §10.1: every driver × server posture × client configuration against a real server.
  tls-matrix:
    name: TLS ${{ matrix.driver }} / ${{ matrix.posture }}
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        include:
          - { driver: mysql, posture: plain, extensions: pdo_mysql }
          - { driver: mysql, posture: tls, extensions: pdo_mysql }
          - { driver: mysql, posture: tls-required, extensions: pdo_mysql }
          - { driver: mysql, posture: mtls-required, extensions: pdo_mysql }
          - { driver: mariadb, posture: plain, extensions: pdo_mysql }
          - { driver: mariadb, posture: tls, extensions: pdo_mysql }
          - { driver: mariadb, posture: tls-required, extensions: pdo_mysql }
          - { driver: mariadb, posture: mtls-required, extensions: pdo_mysql }
          - { driver: pgsql, posture: plain, extensions: pdo_pgsql }
          - { driver: pgsql, posture: tls, extensions: pdo_pgsql }
          - { driver: pgsql, posture: tls-required, extensions: pdo_pgsql }
          - { driver: pgsql, posture: mtls-required, extensions: pdo_pgsql }
          - { driver: sqlsrv, posture: self-signed, extensions: "sqlsrv, pdo_sqlsrv" }
          - { driver: sqlsrv, posture: tls, extensions: "sqlsrv, pdo_sqlsrv" }
          - { driver: sqlsrv, posture: tls-required, extensions: "sqlsrv, pdo_sqlsrv" }

    steps:
      - name: Checkout
        uses: actions/checkout@9c091bb21b7c1c1d1991bb908d89e4e9dddfe3e0 # v7.0.0

      - name: Install Microsoft ODBC Driver 18
        if: matrix.driver == 'sqlsrv'
        run: |
          curl -sSL -O https://packages.microsoft.com/config/ubuntu/$(grep VERSION_ID /etc/os-release | cut -d '"' -f 2)/packages-microsoft-prod.deb
          sudo dpkg -i packages-microsoft-prod.deb
          rm packages-microsoft-prod.deb
          sudo apt-get update
          sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

      - name: Setup PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2
        with:
          php-version: "8.5"
          tools: composer:v2
          extensions: ${{ matrix.extensions }}

      - name: Cache Composer dependencies
        uses: actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v5
        with:
          path: ~/.composer/cache
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Generate APP_KEY
        run: echo "APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')" >> "$GITHUB_ENV"

      - name: Generate test PKI
        run: bash .github/tls/make-certs.sh certs

      - name: Start ${{ matrix.driver }} (${{ matrix.posture }})
        run: bash .github/tls/start-server.sh ${{ matrix.driver }} ${{ matrix.posture }} certs

      - name: Run cases
        run: bash .github/tls/run-matrix.sh ${{ matrix.driver }} ${{ matrix.posture }} certs

      - name: Show clonio.json and server log on failure
        if: failure()
        run: |
          cat clonio.json 2>/dev/null || echo "clonio.json not found"
          docker logs clonio-tls-db 2>&1 | tail -100
```

The job has no `continue-on-error`, SQL Server included (spec §10.1). The existing optional `connection-test-mssql` job stays as it is.

- [ ] **Step 7: Static checks**

Run:

```bash
bash -n .github/tls/make-certs.sh .github/tls/start-server.sh .github/tls/run-matrix.sh && echo "bash ok"
ruby -ryaml -e 'ARGV.each { |f| YAML.load_file(f) }; puts "yaml ok"' \
  .github/workflows/connection-tls-matrix.yml .github/workflows/connection-test.yml .github/workflows/cloning-run-test.yml
```

Expected: `bash ok` and `yaml ok`. If `shellcheck` and `actionlint` are installed, run them on the three scripts and the new workflow as well.

Check that the cases files match spec §10.1 cell for cell. A row count alone is not enough; compare the rows against the spec tables. Expected counts: `mysql.cases` 36 rows, `pgsql.cases` 40, `sqlsrv.cases` 18.

```bash
for f in .github/tls/cases/*.cases; do printf '%s ' "$f"; grep -cv '^#' "$f"; done
```

- [ ] **Step 8: Local rehearsal (mysql, mariadb, pgsql)**

This step requires Docker. Rehearse at least `mysql tls-required`, `pgsql mtls-required` and one `plain` posture before pushing. The sqlsrv postures install a CA with `sudo update-ca-certificates` and so run only in CI (Linux).

```bash
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export CLONIO_TLS_LAN_IP="$(ipconfig getifaddr en0)"   # macOS; on Linux hostname -I is used automatically
bash .github/tls/make-certs.sh certs
bash .github/tls/start-server.sh mysql tls-required certs
bash .github/tls/run-matrix.sh mysql tls-required certs
docker rm -f clonio-tls-db; rm -f clonio.json
```

Repeat the last three commands for each other posture, then run `rm -rf certs`. `certs/` and `clonio.json` must not be committed.

The script runs from the repo root because it calls `php clonio`, and it refuses to run if a `clonio.json` is present.

When a cell fails, find out why before touching the cases file:
- **The driver's message has no hint.** Some ODBC 18 texts (for example host-name mismatch) may not have been seen in the spike. Add the exact message pattern to `ConnectionErrorHint` together with a unit test in `ConnectionErrorHintTest` (Task 4), then re-run.
- **The server behaves differently from the spec.** Examples are the † cell, or a posture that doesn't do what the spec says. Stop and report it. The spec, the cases file and the docs (Task 10) change together, after the user decides. Never make a row accept both outcomes.

- [ ] **Step 9: Commit (proposed)**

```bash
git add .github/tls .github/workflows/connection-tls-matrix.yml .github/workflows/connection-test.yml .github/workflows/cloning-run-test.yml
git commit -m "ci(connection): end-to-end TLS matrix for every driver, server posture and client mode"
```

---

### Task 10: Documentation in clonio-docs

**Repository:** `/Users/rok/workspace/clonio-dev/clonio-docs`. This is a separate git repo, Pergament static site, currently on `main`. Before editing, create branch `149-connection-tls` there with `git -C /Users/rok/workspace/clonio-dev/clonio-docs switch -c 149-connection-tls`. `public/` is build output; never edit or commit it.

**Files (paths relative to the docs repo):**
- Create: `content/docs/1-connections/04-transport-security.md`
- Modify: `content/docs/1-connections/01-managing-connections.md`
- Modify: `content/docs/1-connections/02-supported-databases.md`
- Modify: `content/docs/5-reference/02-command-reference.md`
- Modify: `content/docs/5-reference/04-troubleshooting.md`
- Modify: `content/docs/3-running-clones/03-ci-cd.md`

**Interfaces:**
- Consumes the behaviour of Tasks 1–9: option names, prompt labels, output lines and hint texts, all verbatim. If Task 9 changed a spec cell (such as the † SQL Server `disable` row), the docs follow the corrected spec.
- Produces: nothing code depends on.

**House style of the docs** (match it): frontmatter `title` + `excerpt`; one `#` heading equal to the title; short sections; tables for options; fenced `bash` blocks; links between pages as relative `.md` paths, as in `(03-sql-dump-connections.md)` or `(../1-connections/03-sql-dump-connections.md)`.

- [ ] **Step 1: Create `content/docs/1-connections/04-transport-security.md`**

````markdown
---
title: Transport Security (TLS)
excerpt: Encrypt database connections, verify server certificates, and use client certificates for mutual TLS.
---

# Transport Security (TLS)

Every network connection (`mysql`, `mariadb`, `pgsql`, `sqlsrv`) has a transport security mode. It decides whether the connection is encrypted and whether Clonio checks that it is talking to the right server.

## Modes

| Mode | Encrypted | Server certificate checked | Use it when |
|---|---|---|---|
| `disable` | No | — | Local development or a trusted private network, and the server has no TLS (for example the stock `postgres` Docker image) |
| `require` | Yes | No | The server enforces TLS or uses a self-signed certificate. Protects against eavesdropping, not against a server pretending to be yours |
| `verify` | Yes | Yes: the CA **and** the host name | Production databases, especially across networks you don't control |
| default | Driver default | Driver default | Connections created before transport security existed (no `ssl` in `clonio.json`) |

There is no `prefer` mode. Clonio never silently falls back from TLS to plaintext.

## Default for new connections

`clonio connection:add` preselects `require`, and `--no-interaction` stores `require` unless you pass `--ssl-mode`. Servers that enforce TLS, such as MySQL with `require_secure_transport=ON` or managed cloud databases, work without further setup.

Existing connections in `clonio.json` are not changed. They keep the driver default until you run `clonio connection:update` and pick a mode.

A server without TLS rejects `require`. Use `disable` for it:

```bash
clonio connection:add local-pg --type=pgsql --host=127.0.0.1 --port=5432 \
  --database=app --schema=public --username=postgres --password=secret \
  --ssl-mode=disable
```

## Adding a connection with TLS

Interactively, `connection:add` asks for transport security right after the password:

- `Require (encrypted, not verified)` (default)
- `Verify (encrypted + certificate check)`: then asks for the CA certificate path (not for SQL Server)
- `Disable (plaintext)`
- `Driver default`

For `Require` and `Verify` it then offers to add a client certificate and key (not for SQL Server).

Non-interactive options:

| Option | Meaning |
|---|---|
| `--ssl-mode=` | `disable`, `require` or `verify` |
| `--ssl-ca=` | CA certificate (PEM). Only with `verify`; required for MySQL and MariaDB |
| `--ssl-cert=` | Client certificate (PEM) for mutual TLS. Needs `--ssl-key` |
| `--ssl-key=` | Client private key (PEM) for mutual TLS. Needs `--ssl-cert` |

```bash
clonio connection:add production --type=mysql --host=db.example.com --port=3306 \
  --database=app --username=clonio --password="$DB_PASSWORD" \
  --ssl-mode=verify --ssl-ca=certs/production-ca.pem --production --no-interaction
```

`clonio connection:update` asks the same questions, preselected with the stored values. Certificate path prompts show the stored path: press Enter to keep it, type `none` to remove it, or enter a new path. The change summary lists every transport security change before you save.

## Certificate files

Clonio stores the **paths** you enter, never the file contents, so `clonio.json` contains no key material.

Paths resolve when Clonio connects:

| Path as entered | Resolves to |
|---|---|
| `~/certs/ca.pem` | Your home directory |
| `/etc/ssl/db/ca.pem` | Used as-is |
| `certs/ca.pem` | The current working directory, next to `clonio.json` |

Relative paths keep `clonio.json` portable. The same file works on your machine, in CI and in the Docker image, where the working directory is the mounted project.

Clonio checks the files when you add or update a connection, and again before it connects. A missing file fails with `Certificate file not found: <absolute path>`, before any network traffic.

Keep private keys out of Git: store them outside the project or list them in `.gitignore`, and restrict them with `chmod 600`. Clonio warns when a key file is readable by other users.

## Mutual TLS

Some servers require the client to present a certificate, such as a MySQL user with `REQUIRE X509` or a PostgreSQL `hostssl … clientcert=verify-ca` rule. Add `--ssl-cert` and `--ssl-key` to `require` or `verify`:

```bash
clonio connection:add production --type=pgsql --host=db.example.com --port=5432 \
  --database=app --schema=public --username=clonio --password="$DB_PASSWORD" \
  --ssl-mode=verify --ssl-ca=certs/ca.pem \
  --ssl-cert=certs/clonio.pem --ssl-key=certs/clonio-key.pem --no-interaction
```

SQL Server connections don't support client certificates.

## Per-driver behaviour

| Driver | `disable` | `require` | `verify` | `verify` without a CA file | default |
|---|---|---|---|---|---|
| `mysql`, `mariadb` | No TLS | TLS, certificate not checked | TLS, CA and host name checked | Not allowed | No TLS |
| `pgsql` | `sslmode=disable` | `sslmode=require` | `sslmode=verify-full` | System trust store | `sslmode=prefer`: TLS if the server offers it |
| `sqlsrv` | `Encrypt=no` | `Encrypt=yes`, `TrustServerCertificate=yes` | `Encrypt=yes`, `TrustServerCertificate=no` | System trust store (always) | ODBC Driver 18: `Encrypt=yes`, certificate checked |

### SQL Server

- The ODBC driver takes no certificate paths. `verify` checks the server certificate against the operating system's trust store, so install your CA there.
- ODBC Driver 18 encrypts and verifies by default. A server with a self-signed certificate (the default for SQL Server on Linux and in Docker) fails with the driver default and with `verify`. Use `require`.
- A server configured with `forceencryption=1` encrypts the connection even with `disable`.
- Older `clonio.json` files may contain `"trust_server_certificate": true`. It keeps working. `clonio connection:update` replaces it with `"ssl": { "mode": "require" }`. `--trust-server-certificate` is a deprecated alias for `--ssl-mode=require`.

## Checking a connection

```bash
clonio connection:test production -v
```

```text
production: OK (38ms, tls: verify)
  TLS cipher: TLS_AES_256_GCM_SHA384
```

`connection:list` shows each connection's mode in the `TLS` column. With `-v`, MySQL, MariaDB and PostgreSQL connections also report the negotiated cipher; if the `TLS cipher` line is missing, the connection is not encrypted. When a connection fails, the error comes with a hint; see [Troubleshooting](../5-reference/04-troubleshooting.md).

## `clonio.json`

```json
"production": {
  "type": "mysql",
  "host": "db.example.com",
  "port": 3306,
  "database": "app",
  "username": "clonio",
  "password": "encrypted:…",
  "is_production": true,
  "ssl": {
    "mode": "verify",
    "ca": "certs/production-ca.pem"
  }
}
```

| Key | Allowed with | Notes |
|---|---|---|
| `ssl.mode` | — | Required when `ssl` is present |
| `ssl.ca` | `verify` | Not for `sqlsrv` |
| `ssl.cert`, `ssl.key` | `require`, `verify` | Together or not at all. Not for `sqlsrv` |

Without an `ssl` key, the connection uses the driver default. `ssl` is not allowed on `sqlite` and `dump` connections.

## Managed databases

Managed database services publish the CA that signs their server certificates. Download it into the project, for example as `certs/<provider>-ca.pem`, and use `verify`:

| Provider | CA file |
|---|---|
| AWS RDS / Aurora | `global-bundle.pem` from the RDS "Using SSL/TLS" documentation |
| Google Cloud SQL | `server-ca.pem` from the instance's *Connections → Security* page |
| Azure Database for MySQL / PostgreSQL | The root CAs listed in Azure's TLS documentation for your server type |
| DigitalOcean Managed Databases | `ca-certificate.crt` from the cluster's *Connection details* |

Use the exact host name the provider gives you. `verify` checks it against the certificate, so an IP address or a custom DNS alias fails even with the right CA.

If you don't have the CA at hand, `require` still encrypts the connection. It doesn't prove the server's identity.
````

- [ ] **Step 2: `content/docs/1-connections/01-managing-connections.md`**

1. In the "Without flags, Clonio prompts for" list, insert this after `- username and password`:

```markdown
- transport security for network drivers: `require` (default), `verify`, `disable` or driver default, plus certificate paths when needed (see [Transport Security](04-transport-security.md))
```

2. In the non-interactive example, replace `  --password="$DB_PASSWORD" \` with the two lines below, so that the example shows TLS:

```bash
  --password="$DB_PASSWORD" \
  --ssl-mode=verify --ssl-ca=certs/production-ca.pem \
```

Below the example, add:

```markdown
New network connections use `--ssl-mode=require` when no mode is given. A server without TLS, such as the stock `postgres` Docker image, needs `--ssl-mode=disable`.
```

3. Under "List connections", add:

```markdown
The `TLS` column shows each connection's transport security mode (`default`, `disable`, `require`, `verify`).
```

4. Under "Test a connection", add:

````markdown
A successful test shows the transport security mode:

```text
production: OK (42ms, tls: require)
```

Add `-v` to also see the negotiated TLS cipher (MySQL, MariaDB and PostgreSQL).
````

5. Under "Update a connection", add:

```markdown
Transport security is preselected with the stored mode. Certificate path prompts show the stored path: press Enter to keep it, type `none` to remove it, or enter a new path.
```

6. Append to "Security notes":

```markdown
- `clonio.json` stores certificate and key **paths**, never their contents. Keep private key files outside the project or in `.gitignore`, and restrict them with `chmod 600`.
```

- [ ] **Step 3: `content/docs/1-connections/02-supported-databases.md`**

1. After the driver table, add:

```markdown
All network drivers (`mysql`, `mariadb`, `pgsql`, `sqlsrv`) support encrypted connections. New connections use TLS by default; see [Transport Security](04-transport-security.md) for modes, certificates and per-driver details.
```

2. At the end of "Docker networking", add:

```markdown
With transport security `verify`, the server certificate must contain the host name Clonio dials. Inside Docker, that is `host.docker.internal` for a configured `localhost`/`127.0.0.1`, which is usually not in the certificate. Use the server's real DNS name, or `require` for a local database.
```

- [ ] **Step 4: `content/docs/5-reference/02-command-reference.md`**

Replace the four connection rows:

```markdown
| `clonio connection:add` | Add a database or [dump](../1-connections/03-sql-dump-connections.md) connection, including [transport security](../1-connections/04-transport-security.md) (TLS). |
| `clonio connection:list` | List configured connections and their TLS mode. |
| `clonio connection:test` | Test database connectivity; `-v` shows the negotiated TLS cipher. |
| `clonio connection:update` | Update connection settings, secrets or transport security. |
```

- [ ] **Step 5: `content/docs/5-reference/04-troubleshooting.md`**

Insert these sections after "Docker cannot reach localhost database":

````markdown
## "The server requires TLS"

```text
staging: FAILED — SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.
The server requires TLS. Run "clonio connection:update staging" and set transport security to "require" or "verify".
```

The server only accepts encrypted connections, but the connection uses `disable` or was created before transport security existed. PostgreSQL reports the same situation as `pg_hba.conf rejects connection … no encryption`. Set the mode to `require` (or `verify` with the server's CA):

```bash
clonio connection:update staging
```

## "TLS handshake failed. The server may not support TLS"

The connection asks for TLS (`require` or `verify`) but the server doesn't offer it. The drivers report it as:

- MySQL/MariaDB: `[2006] MySQL server has gone away` or `[2002] Cannot connect to MySQL using SSL`
- PostgreSQL: `server does not support SSL, but SSL was required`

If the database is on a trusted network, set the mode to `disable`. Otherwise, enable TLS on the server.

## A local PostgreSQL in Docker fails right after `connection:add`

The stock `postgres` image has no TLS, and new connections use `require` by default. Add the connection with `--ssl-mode=disable`, or run `clonio connection:update <name>` and choose `Disable (plaintext)`.

## "TLS handshake failed. Likely causes: …"

```text
TLS handshake failed. Likely causes: the CA file did not sign the server certificate, or the host name "10.0.0.5" is not in the certificate. Use mode "require" to skip verification.
```

Mode `verify` couldn't confirm the server's identity. Check:

- the CA file is the one that signed the server certificate (for managed databases, the provider's current bundle);
- the host in the message is in the server certificate. Connecting by IP address, through a DNS alias, or through Docker's `host.docker.internal` usually fails;
- for SQL Server and for PostgreSQL `verify` without a CA file: the CA is installed in the operating system's trust store.

MySQL reports all of these as the same generic `[2002] Cannot connect to MySQL using SSL`, so Clonio lists them together.

## "Certificate file not found"

```text
Certificate file not found: /home/ci/project/certs/ca.pem
```

A path from the connection's `ssl` block doesn't exist on this machine. Relative paths resolve against the current working directory, so run Clonio from the directory that contains `clonio.json`, or store an absolute or `~/` path. Clonio checks this before connecting.
````

- [ ] **Step 6: `content/docs/3-running-clones/03-ci-cd.md`**

Insert this section before "Docker":

````markdown
## Encrypted database connections

Store the database CA certificate as a CI secret, write it to a file, and point the connection at it:

```yaml
- name: Write database CA
  run: printf '%s\n' "$DB_CA_PEM" > db-ca.pem
  env:
    DB_CA_PEM: ${{ secrets.DB_CA_PEM }}

- name: Register connection
  run: |
    vendor/bin/clonio connection:add production --type=mysql \
      --host=db.example.com --port=3306 --database=app \
      --username=clonio --password="$DB_PASSWORD" \
      --ssl-mode=verify --ssl-ca=db-ca.pem --production --no-interaction
  env:
    APP_KEY: ${{ secrets.CLONIO_APP_KEY }}
    DB_PASSWORD: ${{ secrets.DB_PASSWORD }}
```

Relative certificate paths resolve against the working directory, so write the file where `clonio.json` expects it. If the CA isn't available in the pipeline, `--ssl-mode=require` encrypts without verifying the server. See [Transport Security](../1-connections/04-transport-security.md).
````

- [ ] **Step 7: Build the site**

```bash
cd /Users/rok/workspace/clonio-dev/clonio-docs
composer build
grep -rl "Transport Security (TLS)" public | head
```

Expected: the build finishes without errors, and `grep` lists the generated page. It also lists the navigation of the other pages if Pergament renders a sidebar.

Then check links. Every link added in Steps 1–6 points at an existing file:

```bash
grep -ho '([^)]*\.md)' content/docs/1-connections/*.md content/docs/5-reference/0{2,4}-*.md content/docs/3-running-clones/03-ci-cd.md | sort -u
```

For each path, check that the file exists relative to the page that links to it.

- [ ] **Step 8: Commit (proposed, in clonio-docs)**

```bash
git -C /Users/rok/workspace/clonio-dev/clonio-docs add content/docs
git -C /Users/rok/workspace/clonio-dev/clonio-docs commit -m "docs(connections): document transport security (TLS) for connections"
```

---

### Task 11: Full verification

- [ ] **Step 1: Auto-fix style**

Run: `composer lint`
Expected: Rector and Pint finish. Review the diff; they only touch files from this plan.

- [ ] **Step 2: Full suite**

Run: `composer test`
Expected: type coverage ≥ 90 %, Pest passes with coverage ≥ 85 %, PHPStan `[OK] No errors`, lint dry-run clean.

Typical PHPStan fixes:
- Nullsafe chains (`$ssl?->ca`) followed by direct property access: use `instanceof` guards instead.
- `choice()` returns `string|array`: already handled by comparing against labels.

- [ ] **Step 3: Manual smoke against Docker**

```bash
docker run -d --rm --name clonio-tls-smoke -p 33306:3306 -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=app mysql:8.4 --require-secure-transport=ON
# wait ~20s
cd "$(mktemp -d)"
php /Users/rok/workspace/clonio-dev/clonio-cli/clonio connection:add smoke --type=mysql --host=127.0.0.1 --port=33306 --database=app --username=root --password=secret --no-interaction
php /Users/rok/workspace/clonio-dev/clonio-cli/clonio connection:test smoke -v   # expect OK, tls: require, TLS cipher: …
php /Users/rok/workspace/clonio-dev/clonio-cli/clonio connection:list             # expect TLS column "require"
cat clonio.json                                                                  # expect "ssl": {"mode": "require"}
docker stop clonio-tls-smoke
```

- [ ] **Step 4: Docs build**

Run: `cd /Users/rok/workspace/clonio-dev/clonio-docs && composer build`
Expected: build succeeds and `public/` contains the "Transport Security (TLS)" page. `public/` stays uncommitted.

- [ ] **Step 5: TLS matrix green in CI**

The matrix only runs on GitHub (SQL Server postures need the runner's trust store). Once the user pushes the branch or opens the PR, all 15 `connection TLS matrix` jobs and the updated `connection tests` / `cloning run` workflows must be green. Check the † SQL Server cell (`tls-required` / `disable`) explicitly. If it deviates, stop and report (Global Constraints). Do not push on your own.

- [ ] **Step 6: Tick the acceptance criteria**

Tick the §10 checkboxes in `specs/PRD-connection-tls.md`, and set `**Status:** Implemented`.

- [ ] **Step 7: Commit (proposed)**

```bash
git add specs/PRD-connection-tls.md
git commit -m "docs(spec): mark connection TLS PRD as implemented"
```

---

## Notes for the implementer

- **Out of scope, already present:** `UpdateCommand` stores a new password without the `encrypted:` prefix (`Crypt::encryptString($passwordInput)`). Don't fix it here; it is a separate issue.
- **`Pdo\Mysql` constants** need PHP ≥ 8.4. The project runs 8.5, so don't fall back to `PDO::MYSQL_ATTR_*` (deprecated in 8.5).
- **`expectsChoice`** asserts the full option list in order. Use it where order matters (Task 5, Task 6), and `expectsQuestion` everywhere else.
- **TLS matrix failures (Task 9):** ODBC 18 texts for SQL Server (for example host-name mismatch) were not seen in the spike. If a sqlsrv `F:ver`/`F:host` cell fails only because the hint is missing, add the exact message pattern to `ConnectionErrorHint` with a unit test in `ConnectionErrorHintTest`. Never change the expectation to make it pass.
- **† cell:** SQL Server `tls-required` + `disable` = `OK` comes from Microsoft's ODBC 18 encryption table, not from the spike. If CI disagrees, report it. Spec §10.1, `sqlsrv.cases` and the docs page's SQL Server section change together after the user decides.
- **Two repos:** Task 10 works in `clonio-docs`. Create its branch there, and never commit `public/`.
