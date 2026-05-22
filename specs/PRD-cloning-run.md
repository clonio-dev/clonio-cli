# PRD — `cloning:run` Command

**Version:** 0.2
**Status:** Draft
**Date:** 2026-04-01

---

## 1. Goal

Execute a cloning transformation YAML file: validate it against the schema, verify source and target database connectivity, then transfer data row-by-row with the configured anonymization transformations applied. After the run, produce a signed audit log and a structured run log, then deliver both via the configured channels. Output is tuned to the caller's context — from silent CI execution to rich interactive progress display.

---

## 2. Background

`cloning:run` is the execution engine of the CLI. It takes a YAML file produced by `cloning:dump` (and potentially hand-edited), validates it, and orchestrates the full transfer pipeline: schema replication, dependency ordering, per-table chunked row transfer with anonymization, audit log generation, and delivery.

See **PRD-cloning-yaml-schema.md** for the YAML schema. See **PRD-command-behaviour.md** for global output and exit code rules. See **PRD-audit-delivery.md** for the audit log format, signing, run log format, and delivery channel configuration.

---

## 3. Command Signature

```
cloning:run
    {file                  : Path to the .cloning.yaml configuration file}
    {--target=             : Name of the target database connection (from clonio.json)}
    {--allow-failure       : Exit with code 0 even if the run fails (for optional CI steps)}
    {--dry-run             : Validate, test connections, count rows — no data is transferred}
    {--ci                  : CI mode — suppress all non-error output; strict exit codes}
    {--skip-schema         : Skip schema replication; assume target schema already matches}
    {--skip-tables=        : Comma-separated list of table names to exclude from this run}
    {--only-tables=        : Comma-separated list of table names to include; all others skipped}
    {--audit-channel=      : Comma-separated list of channel names to use for this run (overrides clonio.json audit.use)}
```

`--skip-tables` and `--only-tables` are mutually exclusive. Verbosity flags `-v` / `-vv` / `-vvv` are inherited from Symfony Console (see §6).

---

## 4. Execution Pipeline

```
Phase 1 — YAML Validation
Phase 2 — Connection Checks
Phase 3 — Dry-run (if --dry-run; exits here)
Phase 4 — Schema Replication        (skipped if --skip-schema)
Phase 5 — Dependency Resolution
Phase 6 — Data Transfer
Phase 7 — Audit Log & Run Log
Phase 8 — Summary
```

Each phase must complete before the next begins. `--allow-failure` affects only the final exit code, not pipeline flow.

### 4.1 Phase 1 — YAML Validation

1. Load the YAML file from the given path.
2. Validate against the JSON Schema defined in **PRD-cloning-yaml-schema.md**.
3. Validate logical constraints not expressible in JSON Schema:
   - Row strategy `first` or `last` requires a non-zero `limit`.
   - `hash` strategy requires `algorithm` to be a supported value.
   - `mask` strategy requires `visible_chars` ≥ 0.
   - `static` strategy requires `value` to be present (may be empty string).
   - `fake` strategy requires `faker_method` to be a known method.
   - `--skip-tables` and `--only-tables` are mutually exclusive.
4. Report all validation errors at once (not one-by-one) → exit `ValidationError (4)`.

### 4.2 Phase 2 — Connection Checks

1. Resolve the source connection: look up `connection` name from the YAML in `clonio.json`.
2. Resolve the target connection:
   - `--target` provided → look it up in `clonio.json`.
   - Omitted in interactive mode → show selection list (excluding source).
   - Omitted in `--ci` mode → `ValidationError (4)`.
3. Test both connections (PDO ping). Failure → `ConnectionError (3)`.
4. Verify source ≠ target → `ValidationError (4)` with explicit safety warning.

### 4.3 Phase 3 — Dry-run

Performed only when `--dry-run` is set; the command exits after this phase.

1. Fetch the source schema via `SchemaInspector`.
2. For each table listed in the YAML:
   - If the table does not exist in the source: mark as **not found** (see §11).
   - Run `SELECT COUNT(*) FROM <table>` applying the row selection (strategy + limit + sort).
   - Record estimated row count.
3. Print the dry-run summary:

```
  Dry-run: production-db → staging

  Table                   Rows (est.)   Strategy   Transformations
  ─────────────────────────────────────────────────────────────────
  users                    12 340       last 5000   email, first_name, password
  orders                   48 201       full        shipping_address
  audit_logs              NOT FOUND     —           —
  product_catalog             923       full        (none)
  ─────────────────────────────────────────────────────────────────
  Total                    61 464 rows across 3 tables (1 not found, will be skipped)

  No data will be transferred. Run without --dry-run to execute.
```

4. Exit `Success (0)`. Exit code is `0` even if tables are not found — not-found tables are a warning, not an error, in dry-run mode.

### 4.4 Phase 4 — Schema Replication

Replicate the source schema to the target (create missing tables, add missing columns). Uses `SchemaReplicator`. Controlled by `options.enforce_column_types` and `options.drop_unknown_tables` from the YAML.

Tables that do not exist in the source (see §11) are skipped during schema replication.

### 4.5 Phase 5 — Dependency Resolution

1. Build the full FK dependency graph from the source schema (same `DependencyResolver` logic used for insert ordering).
2. Compute the **cascade exclusion set**: for every table explicitly excluded by `--skip-tables` or absent from `--only-tables`, walk the dependency graph and mark all tables that have a direct or transitive FK dependency on the excluded table as `skipped_by_cascade`.
3. Remove all excluded and cascaded tables from the working set.
4. Topologically sort the remaining tables in parent-first order to avoid FK violations during insert.

### 4.6 Phase 6 — Data Transfer

For each table (in resolved order):
1. If `options.disable_foreign_key_checks` is true, temporarily disable FK checks on target.
2. Apply row selection (full / first X / last X with optional `sort_by`).
3. Fetch rows in chunks of `options.chunk_size`.
4. For each chunk, apply column transformations per the YAML configuration.
5. Bulk-insert into target. On unique/FK constraint violation, fall back to row-by-row insert; skip conflicting rows and record them.
6. Re-enable FK checks after the table completes.
7. Emit a log event to the `RunLogWriter` after each table.

### 4.7 Phase 7 — Audit Log & Run Log

1. `AuditLogBuilder` assembles `AuditRecordData` from the run result, config snapshot, and skipped-table list.
2. `AuditLogSigner` signs the record (see **PRD-audit-delivery.md §2.2**).
3. `AuditLogRenderer` renders the HTML audit document.
4. `RunLogWriter` finalises the JSONL run log.
5. `AuditDeliveryService` dispatches all artefacts to the configured channels.
   - If `--audit-channel=` is provided, only the named channels receive artefacts (overrides `audit.use` for this run). Unknown channel names are reported as a warning and skipped.
   - If `--audit-channel=` is not provided, delivery proceeds as configured in `clonio.json`.

If `audit` is absent from `clonio.json`, this phase is silently skipped.

### 4.8 Phase 8 — Summary

Print the run summary (see §6 for format per verbosity level). If any tables were not-found in the source, list them explicitly at the end regardless of verbosity level.

---

## 5. Table Filtering — `--skip-tables` and `--only-tables`

Both flags accept a comma-separated list of **exact table names**. Glob patterns, regex, and prefix wildcards are not supported. Spaces around commas are trimmed.

```bash
# Skip two tables
clonio cloning:run prod.cloning.yaml --target staging --skip-tables=audit_logs,sessions

# Transfer only these two tables
clonio cloning:run prod.cloning.yaml --target staging --only-tables=users,orders
```

Using both flags together is a `ValidationError (4)`.

### 5.1 FK-Dependent Cascade

When a table is excluded (by either flag), all tables that have a foreign key dependency on it — directly or transitively — are **also excluded automatically**. The dependency graph computed in Phase 5 is used for this.

**Example:** if `users` is skipped and `orders` has a FK to `users`, then `orders` is also skipped even if it was not listed in `--skip-tables`.

Cascaded exclusions are reported in the summary alongside explicitly excluded tables:

```
  Skipped tables:
    users        (--skip-tables)
    orders       (cascaded: FK dependency on users)
    order_items  (cascaded: FK dependency on orders)
```

The cascade applies before schema replication and data transfer. Cascaded tables appear in the audit log with status `skipped_by_cascade`.

### 5.2 Behaviour Across Phases

Tables excluded by flag or cascade:
- Are skipped during schema replication.
- Are excluded from the topological sort (Phase 5).
- Are skipped during data transfer.
- Appear in the audit log as `skipped_by_flag` or `skipped_by_cascade`.
- Are not counted in transferred totals but are shown in the summary.

---

## 6. Not-Found Tables

A table is "not found" when it is referenced in the YAML but does not exist in the source database at run time. This is a non-fatal condition.

**Behaviour across all phases:**
- Phase 1 (validation): not checked — source schema is not fetched yet.
- Phase 3 (dry-run): detected and reported in the dry-run summary as `NOT FOUND`.
- Phase 4 (schema replication): table is skipped; a warning is logged.
- Phase 5 (dependency resolution): table is excluded from the sort.
- Phase 6 (data transfer): table is skipped; a warning is logged.
- Phase 7 (audit log): each not-found table is listed in the audit log with status `not_found`.
- Phase 8 (summary): not-found tables are always printed, regardless of verbosity:

```
  Warning: the following tables were listed in the YAML but not found in the source:
    - audit_logs
    - legacy_imports
  They were skipped. Update the YAML to remove them or restore the tables.
```

The run exit code is `Success (0)` if all found tables transferred successfully, even if some tables were not found.

---

## 7. Target Resolution — Interactive Prompt

When `--target` is omitted in non-CI mode:

```
  Source connection: production-db (pgsql @ db.prod.io:5432)

  Select target connection:
  > staging-db  (mysql @ 127.0.0.1:3306)
    local-dev   (mysql @ 127.0.0.1:3307)
```

In `--ci` mode, omitting `--target` is a `ValidationError (4)`.

---

## 8. Output Modes by Verbosity

### 8.1 `--ci` (no verbosity flags)

No stdout. Errors to stderr with `[ERROR]` prefix. Exit code only.

### 8.2 Default (no flags)

Dot-style per table. One line at the end.

```
....F..........

  Warning: 1 table not found in source (audit_logs)
  Run failed: table "orders" — connection reset (120 rows skipped)

  Tables: 14/15  Rows: 48 302  Duration: 2m 14s
  Audit log: production-db_staging_2026-04-01T14-32-00Z_audit.html  ✓ delivered
```

- `.` = table transferred successfully
- `F` = table had skipped/failed rows
- `E` = table aborted with an unrecoverable error
- `?` = table not found in source (skipped)

### 8.3 `-v` (verbose)

One updating line per phase and per table.

```
  ✓  Validating YAML ...
  ✓  Connecting to production-db and staging-db ...
  ✓  Replicating schema ...
  ✓  Resolving table order ...
  ✓  users     (12 340 rows)
  ✓  orders    (48 201 rows)
  ✗  audit_logs  — not found in source, skipped
  ✓  Generating audit log ...
  ✓  Delivering via local ...
```

### 8.4 `-vv` (very verbose)

Same as `-v` plus per-table row counts, skipped counts, and reasons.

```
  ✓  users           12 340 rows   0 skipped
  ✓  orders          48 201 rows   0 skipped
  ✗  audit_logs      — not found in source, skipped
  ⚠  sessions        3 910 rows  120 skipped  (FK violation × 89, unique conflict × 31)
```

### 8.5 `-vvv` (debug)

Full debug: schema diff, per-table progress bars with rows/sec and ETA, chunk-level events, skipped row details, delivery channel responses.

```
  Transferring table: orders
  ████████████████████░░░░░  18 000 / 48 201  (~312 rows/sec, ~1m 31s left)
```

Progress bars via Symfony Console `ProgressBar`. Suppressed when `--ci` is active regardless of verbosity.

---

## 9. Exit Codes

| Code | Situation |
|:----:|-----------|
| `0`  | Run completed (all found tables transferred successfully) — or `--allow-failure` |
| `0`  | Run completed with not-found tables (non-fatal warning) |
| `1`  | Run failed — one or more tables had unrecoverable transfer errors |
| `2`  | Config error — `clonio.json` missing, `APP_KEY` not set |
| `3`  | Connection error — source or target unreachable |
| `4`  | Validation error — YAML invalid, `--skip-tables` + `--only-tables` combined, CI without `--target` |
| `5`  | IO error — YAML file not found or not readable |

### 9.1 `--allow-failure`

The run executes fully. Regardless of outcome, the process exits with `0`. Errors still go to stderr in `--ci` mode. Useful for optional pipeline steps.

```yaml
# GitHub Actions example
- name: Sync staging (optional)
  run: clonio cloning:run prod.cloning.yaml --target staging --ci --allow-failure
```

---

## 10. DTOs and Architecture

### 10.1 Run options DTO

```php
// app/Data/Cloning/RunOptionsData.php
final readonly class RunOptionsData
{
    /**
     * @param list<string> $skipTables
     * @param list<string> $onlyTables
     */
    public function __construct(
        public string $yamlPath,
        public string $targetConnectionName,
        public bool $allowFailure,
        public bool $dryRun,
        public bool $skipSchema,
        public array $skipTables,
        public array $onlyTables,
    ) {}
}
```

### 10.2 Run result DTO

```php
// app/Data/Cloning/RunResultData.php
final readonly class RunResultData
{
    /**
     * @param list<TableRunResultData> $tables
     */
    public function __construct(
        public bool $success,
        public array $tables,
        public int $totalRows,
        public int $skippedRows,
        public float $durationSeconds,
        public ?string $failureReason,
    ) {}
}
```

### 10.3 Table run result DTO

```php
// app/Data/Cloning/TableRunResultData.php
final readonly class TableRunResultData
{
    public function __construct(
        public string $tableName,
        public TableRunStatus $status,     // transferred | skipped_by_flag | not_found | failed
        public int $rowsTransferred,
        public int $rowsSkipped,
        public float $durationSeconds,
        public ?string $failureReason,
    ) {}
}
```

```php
// app/Data/Cloning/TableRunStatus.php
enum TableRunStatus: string
{
    case Transferred      = 'transferred';
    case SkippedByFlag    = 'skipped_by_flag';
    case SkippedByCascade = 'skipped_by_cascade';  // FK dependency on a skipped table
    case NotFound         = 'not_found';
    case Failed           = 'failed';
}
```

### 10.4 Dry-run result DTO

```php
// app/Data/Cloning/DryRunResultData.php
final readonly class DryRunResultData
{
    /**
     * @param list<DryRunTableData> $tables
     */
    public function __construct(
        public array $tables,
        public int $totalEstimatedRows,
        public int $notFoundCount,
    ) {}
}
```

```php
// app/Data/Cloning/DryRunTableData.php
final readonly class DryRunTableData
{
    /**
     * @param list<string> $transformedColumns  column names with non-keep strategy
     */
    public function __construct(
        public string $tableName,
        public bool $exists,
        public ?int $estimatedRows,
        public string $rowStrategy,
        public ?int $rowLimit,
        public array $transformedColumns,
    ) {}
}
```

### 10.5 Cloning configuration DTOs

```php
// app/Data/Cloning/CloningConfigData.php
final readonly class CloningConfigData
{
    /**
     * @param list<TableCloningConfigData> $tables
     */
    public function __construct(
        public string $version,
        public string $connectionName,
        public CloningOptionsData $options,
        public array $tables,
    ) {}
}
```

```php
// app/Data/Cloning/CloningOptionsData.php
final readonly class CloningOptionsData
{
    public function __construct(
        public int $chunkSize,
        public bool $enforceColumnTypes,
        public bool $dropUnknownTables,
        public bool $disableForeignKeyChecks,
        public string $fakerLocale,
    ) {}
}
```

```php
// app/Data/Cloning/TableCloningConfigData.php
final readonly class TableCloningConfigData
{
    /**
     * @param list<ColumnCloningConfigData> $columns
     */
    public function __construct(
        public string $tableName,
        public TableRowConfigData $rows,
        public array $columns,
    ) {}
}
```

```php
// app/Data/Cloning/TableRowConfigData.php
final readonly class TableRowConfigData
{
    public function __construct(
        public string $strategy,   // full | first | last
        public ?int $limit,
        public ?string $sortBy,
    ) {}
}
```

```php
// app/Data/Cloning/ColumnCloningConfigData.php
final readonly class ColumnCloningConfigData
{
    /**
     * @param list<scalar> $fakerArguments
     */
    public function __construct(
        public string $columnName,
        public string $strategy,        // keep | fake | hash | mask | null | static
        public ?string $fakerMethod,
        public array $fakerArguments,
        public ?string $hashAlgorithm,
        public ?string $hashSalt,
        public ?string $maskChar,
        public ?int $visibleChars,
        public ?bool $preserveFormat,
        public ?string $staticValue,
    ) {}
}
```

---

## 11. Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `CloningYamlLoader` | Read YAML file from disk, parse into `CloningConfigData` |
| `CloningYamlValidator` | Validate parsed YAML against JSON Schema + logical constraints |
| `DatabaseConnectionService` | Resolve and open source and target connections |
| `SchemaInspector` (via factory) | Fetch source schema; used for dry-run counts, dependency resolution, not-found detection |
| `DependencyResolver` | Topological sort of tables |
| `SchemaReplicator` | Replicate source schema to target |
| `AnonymizationEngine` | Apply column-level transformations per `ColumnCloningConfigData` |
| `CloningRunOrchestrator` | Coordinate phases 4–6; emit progress events; track not-found tables |
| `ProgressReporter` | Translate progress events to output based on verbosity mode |
| `AuditLogBuilder` | Assemble `AuditRecordData` from run result and config snapshot |
| `AuditLogSigner` | Sign the audit record (see **PRD-audit-delivery.md**) |
| `AuditLogRenderer` | Render HTML audit document from Blade template |
| `RunLogWriter` | Accumulate and flush JSONL run log |
| `AuditDeliveryService` | Dispatch artefacts to all configured channels |

---

## 12. Transformation Engine

Per column, applied in this priority order:

1. `null` → set to `NULL` (column must be nullable)
2. `fake` → `fake()->{$fakerMethod}(...$fakerArguments)` using locale from `options.faker_locale`
3. `hash` → `hash($algorithm, $salt . $value)`
4. `mask` → character-level masking; email-aware when `preserve_format` is true
5. `static` → return fixed `$value`
6. `keep` → return value unchanged

---

## 13. Error Cases

| Situation | Exit Code | Behaviour |
|-----------|-----------|-----------|
| YAML file not found | IOError (5) | Show resolved path |
| YAML parse error | ValidationError (4) | Show line/column |
| YAML schema failure | ValidationError (4) | List all failures |
| `--skip-tables` + `--only-tables` combined | ValidationError (4) | Explain mutual exclusion |
| Source connection not in `clonio.json` | ConnectionError (3) | Show name from YAML |
| Target connection not in `clonio.json` | ConnectionError (3) | Show provided name |
| Source == target | ValidationError (4) | Safety warning |
| DB connection refused | ConnectionError (3) | Show host:port |
| Schema replication error | GeneralError (1) | Show table and DDL error |
| Table not found in source | Success (0) | Log warning; skip; report in summary |
| Chunk insert failure (all rows skipped) | GeneralError (1) | Log; continue next table |
| `--ci` without `--target` | ValidationError (4) | Explain interactive unavailable |
| Audit delivery failure (all channels) | Success (0) | Log warning; run result is unaffected |

---

## 14. Out of Scope

- Key remapping — reserved YAML extension; implementation deferred
- Parallel table transfer — single-threaded sequential in v1
- Resume / checkpointing after partial failure
- Rollback of target database on failure

---

## 15. Decisions

- **`--skip-tables` / `--only-tables` accept exact table names only.** Glob patterns and regex are not supported. This keeps validation simple and the behaviour predictable.
- **FK-dependent child tables are auto-skipped via cascade.** When a table is excluded, all tables with a direct or transitive FK dependency on it are also excluded. The dependency graph from Phase 5 is used for this computation. Cascaded exclusions are reported in the summary and audit log with status `skipped_by_cascade`.
