# PRD — Dump Connection Type & SQL Dump Export

**Version:** 0.1
**Status:** Draft
**Date:** 2026-05-24

---

## 1. Goal

Introduce a `dump` connection type that, when used as a target in `cloning:run`, writes the entire transformation as a dialect-correct SQL file compressed into a password-protected ZIP archive. This enables transferring data in multi-stage environments where the source and target databases are never network-adjacent.

---

## 2. Background

The current pipeline requires both source and target database connections to be live simultaneously. In regulated or air-gapped environments this is not always possible. A "dump" connection decouples the transfer into two stages:

1. **Stage A** — Run `cloning:run` with a dump target: connects to source, produces a `.zip` SQL file.
2. **Stage B** — Ship the zip file to the target environment; decrypt and import with any standard client (`psql`, `mysql`, `sqlcmd`, `sqlite3`).

Because the output file may contain sensitive (though anonymised) data, it is always compressed and password-protected.

---

## 3. New Connection Type: `dump`

### 3.1 `clonio.json` Structure

```json
"staging-dump": {
  "type": "dump",
  "dialect": "pgsql",
  "password": "encrypted:eyJpdiI6..."
}
```

| Field      | Required | Description                                                                |
|------------|:--------:|----------------------------------------------------------------------------|
| `type`     | Yes      | Always `"dump"` for this connection kind                                   |
| `dialect`  | Yes      | Target DBMS SQL dialect: `mysql`, `mariadb`, `pgsql`, `sqlsrv`, `sqlite`  |
| `password` | No       | ZIP archive password, stored encrypted. If omitted, archive is unencrypted |

All other `ConnectionData` fields (`host`, `port`, `database`, `username`, etc.) are absent and ignored.

### 3.2 Output File

- **Location:** Current working directory (`getcwd()`)
- **Filename:** `<source-database>_<YYYYMMDD>_<HHmmss>.sql`
- **Archive:** Always `<filename>.zip`
- **Encoding:** Example → `myapp_20260524_143022.sql` inside `myapp_20260524_143022.zip`

The `.sql` file inside the archive is the only member; no directory nesting.

---

## 4. Command Interaction Changes

### 4.1 `connection:add` — New Dump Flow

When the user selects `dump` as the connection type, the interactive flow changes:

| Step | Prompt                                 | Notes                                                      |
|------|----------------------------------------|------------------------------------------------------------|
| 1    | **Name** — unique identifier           | Same as other types; `[a-z0-9_-]+`                        |
| 2    | **Type** — driver list                 | Now includes "Dump (SQL file)" option                      |
| 3    | **Dialect** — target DBMS              | Choice list: MySQL / MariaDB / PostgreSQL / SQL Server / SQLite |
| 4    | **ZIP password** — archive password    | Masked input; optional (press Enter to skip)               |
| 5    | **Is production?** — yes/no            | Same as other types                                        |

Steps for host, port, database, schema, and username are **skipped** for dump connections.

### 4.2 `connection:test`

For dump connections, skip PDO connectivity; instead verify:
- `dialect` is a valid `DatabaseConnectionType` value.
- `password` (if present) can be decrypted without error.
- The current working directory is writable.

### 4.3 `cloning:run` — Target Is a Dump Connection

Phase changes when the resolved target connection has `type === dump`:

| Phase | Normal Behaviour                | Dump Override                                           |
|-------|---------------------------------|---------------------------------------------------------|
| 2     | PDO ping target DB              | Validate dialect + check cwd is writable                |
| 3     | Dry-run row counts only         | Same (no target write needed)                           |
| 4     | DDL via PDO to target DB        | Write `CREATE TABLE` DDL to open `.sql` file            |
| 6     | Bulk-insert rows via PDO        | Write `INSERT INTO` statements to `.sql` file           |
| 7     | Audit log + delivery            | Add dump file path + sha256 to audit record             |
| 8     | Summary table                   | Print output zip path and size                          |

After Phase 6 the orchestrator finalises the `.sql` file, then compresses it to `.zip` (with password if set), then deletes the intermediate `.sql` file.

---

## 5. SQL Dialect Specification

### 5.1 File Structure (all dialects)

```sql
-- Clonio SQL Dump
-- Dialect:  PostgreSQL
-- Source:   myapp@prod-db
-- Date:     2026-05-24 14:30:22 UTC
-- Clonio:   v1.x.x

-- [preamble — disable FK checks, set encoding]

-- Table: users
-- [DDL]
-- [DML INSERT statements]

-- [postamble — re-enable FK checks]
```

### 5.2 MySQL / MariaDB

```sql
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`) VALUES
  (1, 'John Doe'),
  (2, 'Jane Smith');

SET FOREIGN_KEY_CHECKS = 1;
```

Identifiers: backtick-quoted. Batch size: configurable (default 500 rows per `INSERT`).

### 5.3 PostgreSQL

```sql
SET client_encoding = 'UTF8';
SET session_replication_role = 'replica';

DROP TABLE IF EXISTS "users" CASCADE;
CREATE TABLE "users" (
  "id" SERIAL NOT NULL,
  "name" VARCHAR(255) NOT NULL,
  PRIMARY KEY ("id")
);

INSERT INTO "users" ("id", "name") VALUES
  (1, 'John Doe'),
  (2, 'Jane Smith');

SELECT setval(pg_get_serial_sequence('"users"', 'id'),
              COALESCE((SELECT MAX("id") FROM "users"), 1));

SET session_replication_role = 'origin';
```

Identifiers: double-quoted. AUTO_INCREMENT columns become `SERIAL`. Sequence reset statement emitted after each table with a serial column.

### 5.4 SQL Server

```sql
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;

IF OBJECT_ID(N'[dbo].[users]', N'U') IS NOT NULL
  DROP TABLE [dbo].[users];
CREATE TABLE [dbo].[users] (
  [id] INT IDENTITY(1,1) NOT NULL,
  [name] NVARCHAR(255) NOT NULL,
  PRIMARY KEY ([id])
);

SET IDENTITY_INSERT [dbo].[users] ON;
INSERT INTO [dbo].[users] ([id], [name]) VALUES
  (1, N'John Doe'),
  (2, N'Jane Smith');
SET IDENTITY_INSERT [dbo].[users] OFF;
```

Identifiers: bracket-quoted. String literals prefixed with `N` for Unicode. `IDENTITY_INSERT` wraps each table's INSERT block.

### 5.5 SQLite

```sql
PRAGMA foreign_keys = OFF;

DROP TABLE IF EXISTS "users";
CREATE TABLE "users" (
  "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL
);

INSERT INTO "users" ("id", "name") VALUES
  (1, 'John Doe'),
  (2, 'Jane Smith');

PRAGMA foreign_keys = ON;
```

Identifiers: double-quoted. Types mapped to SQLite affinity groups (`INTEGER`, `REAL`, `TEXT`, `BLOB`, `NUMERIC`).

---

## 6. Compression & Encryption

### 6.1 Always Zip

The `.sql` file is **always** wrapped in a ZIP archive even when no password is set. This is consistent with user expectation ("always zip") and keeps the output format uniform regardless of password configuration.

### 6.2 Password-Protected ZIP

When a password is configured on the dump connection:

- Encryption: **AES-256** (`ZipArchive::EM_AES_256`) via PHP's `ZipArchive` extension.
- The encryption password is the decrypted value of `ConnectionData::password`.
- PHP's `ZipArchive` with AES-256 support requires libzip ≥ 1.2.0, which is included in the SPC-built static binaries.

### 6.3 Fallback

If AES-256 is not available (e.g. an older PHP build), the archive is created without encryption and a warning is printed. The run does **not** fail.

---

## 7. Architecture — New & Modified Files

### 7.1 Modified Files

| File | Change |
|------|--------|
| `app/Enums/DatabaseConnectionType.php` | Add `case Dump = 'dump'`; update `label()`, `requiresNetworkConfig()` |
| `app/Data/ConnectionData.php` | Add `readonly ?DatabaseConnectionType $dialect` field |
| `app/Commands/Connection/AddCommand.php` | Add dump-specific interactive flow (dialect + zip password) |
| `app/Commands/Connection/TestCommand.php` | Skip PDO test for dump; verify writable cwd |
| `app/Services/Database/DatabaseConnectionService.php` | Guard `open()` against dump type |
| `app/Services/Cloning/CloningRunOrchestrator.php` | Detect dump target; route phases 4 & 6 to `SqlDumpService` |
| `app/Services/Config/ConfigService.php` | Persist/load `dialect` field for dump connections |

### 7.2 New Files

```
app/
└── Services/
    └── SqlDump/
        ├── SqlDumpService.php          # Orchestrates file creation; called from CloningRunOrchestrator
        ├── SqlDumpDialect.php          # Interface: header(), preamble(), ddl(), dml(), postamble()
        ├── DumpArchiver.php            # Creates ZIP archive (with optional AES-256 password)
        └── Dialects/
            ├── MySqlDumpDialect.php
            ├── MariaDbDumpDialect.php  # Extends MySqlDumpDialect with minor differences
            ├── PostgreSqlDumpDialect.php
            ├── SqlServerDumpDialect.php
            └── SqliteDumpDialect.php
```

### 7.3 `SqlDumpDialect` Interface

```php
interface SqlDumpDialect
{
    public function header(string $sourceDatabase, string $sourceConnection, string $version): string;
    public function preamble(): string;
    public function ddl(TableSchemaData $table): string;
    /** @param list<array<string, mixed>> $rows */
    public function dml(string $tableName, array $columns, array $rows): string;
    public function postamble(): string;
    public function quoteIdentifier(string $name): string;
    public function quoteValue(mixed $value): string;
}
```

### 7.4 `SqlDumpService` Responsibilities

1. Open a writable stream to `<cwd>/<name>.sql`.
2. Write `header()` + `preamble()`.
3. For each table (called once per table by the orchestrator): write `ddl()` then accumulate rows in batches, flushing `dml()` per batch.
4. Write `postamble()`.
5. Close the stream; hand file path to `DumpArchiver`.
6. `DumpArchiver` creates `<name>.zip` with AES-256 if password set; deletes `.sql`.
7. Return the archive path + sha256 hash for the audit record.

---

## 8. Audit Record Extension

The existing audit record (`AuditRecordData`) is extended with an optional `dump` section when the target is a dump connection:

```json
"dump": {
  "file": "myapp_20260524_143022.zip",
  "path": "/home/user/project/myapp_20260524_143022.zip",
  "sha256": "abc123...",
  "size_bytes": 204800,
  "dialect": "pgsql",
  "encrypted": true
}
```

---

## 9. Type Column Mapping

Each DBMS has its own native column type syntax. The `SqlDumpDialect` is responsible for mapping the `ColumnSchemaData::type` string (as returned by `SchemaInspector`, which reflects the source DBMS) to a valid type in the target dialect.

A best-effort mapping table should be maintained per dialect pair. Unknown types fall back to `TEXT` / `NVARCHAR(MAX)` / `BLOB` with a comment.

---

## 10. Testing

| Test File | Coverage |
|-----------|---------|
| `tests/Unit/Services/SqlDump/MySqlDumpDialectTest.php` | DDL & DML generation, quoting, batching |
| `tests/Unit/Services/SqlDump/PostgreSqlDumpDialectTest.php` | Same; serial sequence reset |
| `tests/Unit/Services/SqlDump/SqlServerDumpDialectTest.php` | IDENTITY INSERT wrapping, N-prefix |
| `tests/Unit/Services/SqlDump/SqliteDumpDialectTest.php` | AUTOINCREMENT, PRAGMA wrapping |
| `tests/Unit/Services/SqlDump/DumpArchiverTest.php` | ZIP creation, AES-256 flag, fallback |
| `tests/Feature/Commands/Connection/AddCommandDumpTest.php` | Full interactive dump connection creation |
| `tests/Feature/Commands/Connection/TestCommandDumpTest.php` | Dump connection test flow |
| `tests/Feature/Commands/Cloning/RunCommandDumpTest.php` | End-to-end cloning:run with dump target |

---

## 11. Open Questions

- [ ] **Batch size for INSERT** — default 500 rows per statement; should this be configurable in the YAML or the dump connection?
- [ ] **DDL strategy** — always `DROP TABLE IF EXISTS` + `CREATE TABLE`, or make it configurable (`CREATE TABLE IF NOT EXISTS`)?
- [ ] **Schema prefix for SQL Server** — always `dbo`? Or derive from connection schema field?
- [ ] **Type mapping fidelity** — for cross-dialect dumps (e.g. MySQL → PostgreSQL), how opinionated should the type mapping be? Use a conservative mapping or expose a mapping config in `clonio.json`?
- [ ] **Large BLOBs** — binary columns should be hex-encoded (`0x...` for SQL Server, `E'\\x...'` for PostgreSQL, `X'...'` for SQLite, `0x...` for MySQL). Confirm this is handled in `quoteValue()`.
- [ ] **NULL handling** — `NULL` literal must be unquoted in all dialects. Confirm `quoteValue(null)` returns `'NULL'` (unquoted).
- [ ] **ZIP password UX** — Should the ZIP password be displayable with `connection:list --show-password` in the same way DB passwords work?
