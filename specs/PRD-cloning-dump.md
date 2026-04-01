# PRD — `cloning:dump` Command

**Version:** 0.1
**Status:** Draft
**Date:** 2026-04-01

---

## 1. Goal

Inspect a database connection's full schema, run automatic PII column detection, and emit a versioned YAML configuration file that describes which transformation strategy should be applied to every column. The resulting file is designed to be committed to version control — it contains no credentials, only a connection name as a reference.

---

## 2. Background

Before executing a cloning run, a developer needs a transformation configuration that maps every table and column to a strategy (keep, fake, hash, mask, null, static). Writing this by hand for a large schema is error-prone and time-consuming. `cloning:dump` automates the bootstrapping step by inspecting the schema live and pre-populating PII-detected columns with the recommended transformation strategy, leaving everything else as `keep`.

The output file is the input to `cloning:run`. See **PRD-cloning-run.md** and **PRD-cloning-yaml-schema.md** for the full YAML schema and run semantics.

### PII Matcher Source

The PII detection logic used during dump is driven by the **active PII matcher set** loaded from `pii-matchers.yaml`. See **PRD-pii-matchers.md** for the full specification of how matchers are defined, stored, and organised into groups.

In short:
- If `pii-matchers.yaml` is **absent**, the binary baseline matchers are used silently.
- If `pii-matchers.yaml` is **present**, it is the sole source of truth — no merging with the baseline occurs.
- Run `cloning:matchers init` to write the full baseline to `pii-matchers.yaml` for inspection and editing.
- Run `cloning:matchers update` after upgrading Clonio to add new baseline matchers to an existing file.

`pii-matchers.yaml` contains no credentials and is safe to commit to version control. A committed file means every developer on the team gets identical, project-specific PII detection. See **PRD-cloning-matchers.md** for the command specifications.

---

## 3. Command Signature

```
cloning:dump
    {--connection=  : Name of the saved connection to inspect}
    {--output=      : Output file path (default: <connection-name>.cloning.yaml)}
    {--force        : Overwrite an existing file without asking for confirmation}
    {--only-pii     : Omit tables and columns with no PII match (reduce noise in large schemas)}
    {--locale=      : FakerPHP locale written into options.faker_locale (e.g. de_DE, fr_FR)}
```

---

## 4. Interactive Flow

### 4.1 Connection resolution

1. If `--connection` is provided, look it up in `clonio.json`.
   - Not found → exit `ConnectionError (3)` with a clear message.
2. If `--connection` is omitted, show an interactive selection list of all connections defined in `clonio.json`.
   - No connections defined → exit `ConfigError (2)` and suggest `connection:add`.

### 4.2 Output path resolution

1. If `--output` is provided, use that path as-is.
2. Otherwise, default to `<connection-name>.cloning.yaml` in the current working directory.
3. If the resolved file already exists and `--force` is not set:
   - Ask: `File <path> already exists. Overwrite? [y/N]`
   - If answered no → exit `Success (0)` without writing.

### 4.3 Schema inspection

1. Decrypt the connection password via `DatabaseConnectionService`.
2. Open a live connection using the `SchemaInspector` factory (matches the driver: MySQL, MariaDB, PostgreSQL, SQL Server, SQLite).
3. Fetch `getDatabaseSchema()` → a `DatabaseSchema` DTO containing all tables, columns, indexes, and foreign keys.
4. Display a spinner / indeterminate progress while fetching (hidden in `--ci` mode).

### 4.4 PII detection

1. Load the active matcher set via `PiiMatcherLoader` (from `pii-matchers.yaml` if present, otherwise binary baseline). See **PRD-pii-matchers.md** for loading rules.
2. For each table → for each column:
   - Call `PiiMatcherSetData::match($columnName)`.
   - If a `PiiMatcherData` is returned, pre-populate the column's strategy and options from `$matcher->transformation`.
   - If no match, set strategy to `keep`.
3. The matcher's `name` (e.g. `"Email Address"`) is written as a YAML comment above the column entry so the user understands why that strategy was suggested.

### 4.5 YAML generation

Build the YAML document according to the schema defined in **PRD-cloning-yaml-schema.md** and write it to the resolved output path using `Storage::disk('local')`.

**`--only-pii` behaviour:** Omit any column whose strategy resolved to `keep` **and** omit any table that has zero PII-matched columns. Tables with at least one transformed column are always included, even if some of their columns are `keep`. The `options` block and all other top-level keys are always written regardless of this flag.

**`--locale` behaviour:** Write the provided locale string into `options.faker_locale` in the generated YAML. If omitted, `faker_locale: en_US` is written (the field is always explicit — see **PRD-cloning-yaml-schema.md §2**).

**Row selection:** Every table is generated with `rows.strategy: full`. Users edit the YAML to set `first` or `last` with a `limit` per table after generation. The schema supports per-table row selection — `cloning:dump` only sets the safe default.

---

## 5. Generated YAML Structure (Example)

```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: production-db

options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US

tables:
  users:
    rows:
      strategy: full
    # Only columns that need transformation are listed.
    # All other columns (id, created_at, …) are implicitly kept as-is.
    columns:
      # PII: Email Address
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      # PII: First Name
      first_name:
        strategy: fake
        faker_method: firstName
        faker_arguments: []
      # PII: Last Name
      last_name:
        strategy: fake
        faker_method: lastName
        faker_arguments: []
      # PII: Password / Secret
      password:
        strategy: hash
        algorithm: sha256
        salt: ""

  orders:
    rows:
      strategy: full
    columns:
      # no PII detected — no columns listed; all kept as-is
```

Only columns that require transformation are written. The matcher's `name` is written as a comment above each entry so the intent is visible at a glance. Columns resolved to `keep` are never written (they are implicit). The `$schema` hint enables IDE auto-complete and inline validation.

---

## 6. Output Format and UX

### 6.1 Normal mode (no flags)

```
  Inspecting "production-db" (pgsql @ db.prod.io:5432) ...

  Schema fetched: 24 tables, 187 columns

  PII auto-detection:
    ✓  12 columns matched across 5 tables
    ○  175 columns set to keep

  Written: ./production-db.cloning.yaml

  Review the file, adjust strategies as needed, then run:
    clonio cloning:run production-db.cloning.yaml --target <name>
```

### 6.2 `--ci` mode

No stdout output. Errors go to stderr with `[ERROR]` prefix. Exit codes only.

### 6.3 `-vvv` (debug)

Additionally shows per-table column breakdown and which PII pattern each matched column triggered.

---

## 7. DTOs and Architecture

The command MUST NOT pass raw arrays between layers. Use dedicated DTOs with full type declarations.

### 7.1 Input DTO

```php
// app/Data/Cloning/DumpOptionsData.php
final readonly class DumpOptionsData
{
    public function __construct(
        public string $connectionName,
        public string $outputPath,
        public bool $force,
        public bool $onlyPii,
    ) {}
}
```

### 7.2 Column dump DTO

```php
// app/Data/Cloning/ColumnDumpData.php
final readonly class ColumnDumpData
{
    public function __construct(
        public string $name,
        public string $strategy,        // keep | fake | hash | mask | null | static
        public ?string $fakerMethod,
        /** @var list<scalar> */
        public array $fakerArguments,
        public ?string $maskChar,
        public ?int $visibleChars,
        public ?bool $preserveFormat,
        public ?string $hashAlgorithm,
        public ?string $hashSalt,
        public ?string $staticValue,
        public bool $piiDetected,
        public ?string $piiCategory,    // e.g. "Email Address"
    ) {}
}
```

### 7.3 Table dump DTO

```php
// app/Data/Cloning/TableDumpData.php
final readonly class TableDumpData
{
    /**
     * @param list<ColumnDumpData> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $rowStrategy,   // full | first | last
        public ?int $rowLimit,
        public ?string $sortBy,
    ) {}
}
```

### 7.4 Dump result DTO

```php
// app/Data/Cloning/DumpResultData.php
final readonly class DumpResultData
{
    /**
     * @param list<TableDumpData> $tables
     */
    public function __construct(
        public string $connectionName,
        public array $tables,
        public int $totalColumns,
        public int $piiColumnsDetected,
        public string $outputPath,
    ) {}
}
```

---

## 8. Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `DatabaseConnectionService` | Decrypt password, open live DB connection |
| `SchemaInspector` (via factory) | Fetch full `DatabaseSchema` DTO |
| `PiiMatcherLoader` (new) | Load `pii-matchers.yaml` if present, otherwise load binary baseline; return `PiiMatcherSetData` |
| `PiiMatcherSetData` | Encapsulates merged matcher list; `match(columnName)` returns `?PiiMatcherData` |
| `CloningYamlWriter` (new) | Serialize `DumpResultData` → YAML string; write via `Storage::disk('local')` |

The command itself orchestrates these services but contains no business logic.

---

## 9. Error Cases

| Situation | Exit Code | Behaviour |
|-----------|-----------|-----------|
| `clonio.json` missing | ConfigError (2) | Show path, suggest `clonio init` |
| Connection name not found | ConnectionError (3) | List available connection names |
| DB connection refused | ConnectionError (3) | Show host:port and driver error |
| Auth failure | ConnectionError (3) | Show that credentials are incorrect |
| Output path not writable | IOError (5) | Show path and permission hint |
| File exists, no `--force`, user declines | Success (0) | Silently exit |
| Schema fetch returns zero tables | ValidationError (4) | Warn user, still write empty YAML |

---

## 10. Out of Scope

- Key remapping configuration — added manually to the YAML after generation
- Scheduling or trigger configuration
- Updating an existing YAML (diff/merge) — generate a new file and diff manually
- JSON output format (`--format=json`) — not planned

---

## 11. Decisions

- **`--locale` validation**: The locale string is written into the YAML as-is without validation. No list of known FakerPHP locales is checked at dump time. If the locale is invalid, `cloning:run` will fail when FakerPHP attempts to instantiate the unknown locale. This is intentional — no real validation for now.
