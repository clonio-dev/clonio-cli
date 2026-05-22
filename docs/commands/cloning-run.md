# `cloning:run` Command

Execute a cloning transfer: validate the `.cloning.yaml` configuration, verify connectivity, and transfer data from the source database to the target with all configured anonymization transformations applied. Produces a signed audit log and a structured run log delivered to the configured channels.

## Usage

```bash
clonio cloning:run <file> [options]
```

## Arguments

| Argument | Description |
|----------|-------------|
| `file` | Path to the `.cloning.yaml` configuration file |

## Options

| Option | Description |
|--------|-------------|
| `--target=<name>` | Name of the target database connection (from `clonio.json`) |
| `--allow-failure` | Exit with code `0` even if the run fails (for optional CI steps) |
| `--dry-run` | Validate, test connections, and count rows — no data is transferred |
| `--ci` | CI mode — suppress all non-error output; `--target` is required |
| `--skip-schema` | Skip schema replication; assume the target schema already matches |
| `--skip-tables=<list>` | Comma-separated list of table names to exclude from this run |
| `--only-tables=<list>` | Comma-separated list of table names to include; all others are skipped |
| `--audit-channel=<list>` | Comma-separated list of channel names to use for this run (overrides `audit.default` in `clonio.json`) |
| `--skip-remapping-keys` | Skip key mapping generation and FK rewriting |
| `--no-memory-limit` | Remove PHP's memory limit before generating key mappings. Useful for very large databases when `--file-based` is not viable. |
| `--file-based` | Store key mappings in AES-256-CBC encrypted temporary files instead of RAM. Keeps memory usage bounded to the size of the largest single table's mapping. |
| `--enforce-column-types` | Override: set `enforce_column_types: true` for this run |
| `--no-enforce-column-types` | Override: set `enforce_column_types: false` for this run |
| `--drop-unknown-tables` | Override: set `drop_unknown_tables: true` for this run |
| `--no-drop-unknown-tables` | Override: set `drop_unknown_tables: false` for this run |
| `--drop-extra-columns` | Override: set `drop_extra_columns: true` for this run |
| `--no-drop-extra-columns` | Override: set `drop_extra_columns: false` for this run |
| `--disable-fk-checks` | Override: set `disable_foreign_key_checks: true` for this run |
| `--no-disable-fk-checks` | Override: set `disable_foreign_key_checks: false` for this run |
| `--break-on-failure` | Abort the run immediately on the first table failure (schema or data) |

`--skip-tables` and `--only-tables` are mutually exclusive. Verbosity flags `-v` / `-vv` / `-vvv` are also supported (see [Output Modes](#output-modes)).

### `--break-on-failure`

By default, Clonio continues processing all tables even when one fails — every table gets a chance to transfer and the full results are reported at the end. Pass `--break-on-failure` to abort the run immediately the first time a table fails (either at schema creation or at data transfer).

| Scenario | Without flag | With `--break-on-failure` |
|---|---|---|
| Schema failure on table A | Continue, skip A's data | Abort run |
| Data failure on table B | Continue | Abort run |
| All tables OK | `success: true` | `success: true` |
| Partial failure | `success: false`, full results | `success: false`, partial results |

The audit log is always written, even on early abort.

## Prerequisites

1. Run `clonio init` to set up `APP_KEY`
2. Add connections with `clonio connection:add`
3. Generate a configuration file with `clonio cloning:dump`
4. Review and adjust the generated `.cloning.yaml`

## Examples

### Basic transfer

```bash
clonio cloning:run production-db.cloning.yaml --target local-dev
```

### Dry run (no data transferred)

Validates the config, tests connectivity, estimates row counts, and shows the schema diff between source and target — without moving any data:

```bash
clonio cloning:run production-db.cloning.yaml --target staging --dry-run
```

```
  Dry-run: production-db

  Schema diff: source → target

  Missing tables (1):   audit_logs
  Modified table:        users: +1 cols (phone)

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

When source and target schemas are identical, the diff block shows:

```
  Schema diff: target matches source
```

### Skip specific tables

```bash
clonio cloning:run production-db.cloning.yaml --target staging --skip-tables=audit_logs,sessions
```

### Transfer only specific tables

```bash
clonio cloning:run production-db.cloning.yaml --target staging --only-tables=users,orders
```

### CI mode

```bash
clonio cloning:run production-db.cloning.yaml --target staging --ci
```

### Optional CI step (always exits 0)

```yaml
# GitHub Actions example
- name: Sync staging (optional)
  run: clonio cloning:run prod.cloning.yaml --target staging --ci --allow-failure
```

## Schema Synchronization

Before transferring data, Clonio synchronizes the target schema to match the source. Four options in the `options:` block of the YAML file control this behaviour. These values are set interactively by `cloning:dump` and can be overridden at run time via CLI flags.

| YAML option | Default | CLI override (on / off) | Effect |
|-------------|---------|------------------------|--------|
| `enforce_column_types` | `false` | `--enforce-column-types` / `--no-enforce-column-types` | Add columns to existing target tables that are present in the source but missing from the target |
| `drop_unknown_tables` | `false` | `--drop-unknown-tables` / `--no-drop-unknown-tables` | Drop tables from the target that do not exist in the source |
| `drop_extra_columns` | `false` | `--drop-extra-columns` / `--no-drop-extra-columns` | Drop columns from existing target tables that are absent from the source |
| `disable_foreign_key_checks` | `true` | `--disable-fk-checks` / `--no-disable-fk-checks` | Disable FK constraint checks during data transfer |

```yaml
options:
  chunk_size: 1000
  enforce_column_types: true   # add missing columns
  drop_extra_columns: true     # remove extra columns
  drop_unknown_tables: false   # keep extra tables
  disable_foreign_key_checks: true
  faker_locale: en_US
```

All schema-sync options are applied during **Phase 4 — Schema Replication**, which runs before any data is transferred. Schema replication is skipped entirely when `--skip-schema` is passed on the command line.

### Table creation

**Missing tables are always created**, regardless of any option setting. If a table exists in the source but not on the target, Clonio creates it during Phase 4. No option needs to be enabled for this — it is unconditional.

The following options control what happens to columns and tables that diverge from the source:

| Source table | Target table | What triggers the action |
|---|---|---|
| Exists | **Missing** | **Always created** — no option required |
| Exists | Exists, missing columns | Columns added when `enforce_column_types: true` |
| Exists | Exists, extra columns | Columns dropped when `drop_extra_columns: true` |
| Missing | Exists | Table dropped when `drop_unknown_tables: true` |

### Overriding options at run time

CLI flags override the YAML `options:` values for a single run without modifying the file:

```bash
# Force table-creation safety net even if YAML says false
clonio cloning:run prod.cloning.yaml --target staging --enforce-column-types

# Tear down stale tables on a throwaway CI database
clonio cloning:run prod.cloning.yaml --target ci-db --drop-unknown-tables --drop-extra-columns

# Disable a destructive option set in the YAML for this run only
clonio cloning:run prod.cloning.yaml --target staging --no-drop-unknown-tables
```

Both the `--<option>` and `--no-<option>` variants are always available, so you can force either direction regardless of what the YAML contains.

### Schema diff in dry-run

`--dry-run` always inspects both the source and target schemas and prints a diff summary before the per-table row counts, so you can see exactly what Phase 4 will change before committing to a full run.

### Caution: `drop_extra_columns`

Dropping columns is irreversible and will destroy any data stored in those columns on the target. Only enable `drop_extra_columns: true` when:

- The target environment is ephemeral (e.g. a fresh CI database) or regularly rebuilt, **or**
- You have confirmed that the extra columns on the target are safe to remove.

---

## Key Remapping

Key remapping assigns new IDs to transferred primary keys and rewrites all foreign key references that point to those IDs, preventing ID collisions on the target environment.

### Defining remapping inline (recommended)

Add `strategy: remapping` to any column in a table's `columns` block. The `arguments` list specifies the strategy and, crucially, which other columns hold foreign keys to this one:

```yaml
tables:
  users:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys:
              - table: orders
                column: user_id
              - table: employees
                column: manager_id
                self_referential: true   # employees.manager_id → employees.id
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []

  orders:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys:
              - table: order_items
                column: order_id
    # orders.user_id is rewritten automatically because users.id declares it as a FK

  order_items:
    rows:
      strategy: full
    # no PII detected — no columns listed; all kept as-is

  employees:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys:
              - table: employees
                column: manager_id
                self_referential: true
```

**How FK rewriting works:**

- `orders.user_id` is rewritten to the new value for whichever `users.id` matched the original.
- `order_items.order_id` is rewritten to the new `orders.id`.
- `employees.manager_id` is self-referential: on first pass it is set to `null`, then updated in a second pass once all employees are inserted.

### Argument reference

| Argument key | Type | When | Description |
|---|---|---|---|
| `use` | enum | always | `random_integer` or `new_uuid` |
| `min` | integer | `random_integer` only | Lower bound (inclusive). Default: 100000. |
| `max` | integer | `random_integer` only | Upper bound (inclusive). Default: 9999999. |
| `foreign_keys` | list | always | Columns on other (or the same) tables that must be rewritten. Use `[]` when there are none. |

Each `foreign_keys` entry:

| Field | Type | Description |
|---|---|---|
| `table` | string | Table that holds the FK column |
| `column` | string | Name of the FK column |
| `self_referential` | bool | `true` when the FK points to the same table (default: `false`) |

### Skipping remapping for a single run

```bash
clonio cloning:run production-db.cloning.yaml --target staging --skip-remapping-keys
```

Bypasses all key mapping generation and PK/FK rewriting even when the YAML has `strategy: remapping` columns defined.

### Memory management for large databases

By default, all PK mappings are held in RAM simultaneously (one entry per row per remapped table). For most databases this is fast and adequate. For very large datasets two flags are available:

| Flag | Description |
|------|-------------|
| `--no-memory-limit` | Removes PHP's `memory_limit` constraint before generating mappings. Useful when the dataset fits on the server but exceeds the configured PHP limit. |
| `--file-based` | Writes each table's mappings to an AES-256-CBC encrypted temporary file instead of RAM. Only one table's mappings are loaded at a time, keeping peak memory usage bounded to the largest single table. Files are deleted automatically on completion or crash. |

Both flags can be used together if needed. When `--skip-remapping-keys` is set, both flags are silently ignored.

```bash
# Remove memory cap (dataset fits in RAM, just over the PHP limit)
clonio cloning:run prod.cloning.yaml --target staging --no-memory-limit

# Encrypt mappings to disk (dataset too large for RAM)
clonio cloning:run prod.cloning.yaml --target staging --file-based
```

### Legacy format (still supported)

The original top-level `key_remapping:` section is still parsed. Existing YAML files do not need to be updated. When both formats are present in the same file, the inline column format takes priority.

### Recovering from "key remapping exhausted"

When the column type cannot host the source row count (for example a `TINYINT` primary key that already holds 250 rows but the source has 300), the run aborts in Phase 5b with a *key remapping exhausted* error.

- **Interactive mode** — Clonio prints a prose summary (column type, ceiling, rows requested, slots available) and prompts:

  ```
  Switch users.id strategy to 'keep' in production-db.cloning.yaml? (yes/no)
  ```

  Accepting the prompt invokes `cloning:column:edit ... --strategy=keep` in-process, which rewrites the YAML so the offending column is no longer remapped. The command exits with code `0` and prints the original `cloning:run` invocation so you can re-run it. Declining the prompt also exits with code `0` and leaves the YAML untouched.

- **CI mode** (`--ci`) — Clonio prints the same prose summary plus a hint, including the exact `cloning:column:edit` command needed to patch the configuration, then exits with code `1` (`GeneralError`). No interactive prompts are shown.

If widening the column type is the right fix instead of switching to `keep`, run a schema migration on the source and retry — Clonio will pick up the new ceiling automatically on the next `cloning:run`.

---

## The 8-Phase Pipeline

```
Phase 1  — YAML Validation
Phase 2  — Connection Checks
Phase 3  — Dry-run               (only if --dry-run; exits here)
Phase 4  — Schema Replication    (skipped if --skip-schema)
Phase 5  — Dependency Resolution
Phase 5b — Key Mapping Generation (when remapping columns are defined)
Phase 6  — Data Transfer
Phase 7  — Key Mapping Cleanup   (when remapping columns are defined)
Phase 8  — Audit Log & Process Log
Phase 9  — Summary
```

Each phase must complete before the next begins.

### Phase 1 — YAML Validation

Loads and validates the YAML file against the Clonio schema. All validation errors are reported at once. Logical constraints are also checked (e.g. `first`/`last` row strategy requires a `limit`; `hash` requires a supported `algorithm`).

### Phase 2 — Connection Checks

Resolves the source connection (from the YAML `connection` field) and the target connection (from `--target` or interactive selection). Tests both with a real PDO ping. Verifies that source and target are not the same connection.

### Phase 3 — Dry-run

Only runs when `--dry-run` is set. Fetches both the source and target schemas, computes the schema diff, counts estimated rows per table, and prints a summary. Tables not found in the source are shown as `NOT FOUND`. Exits with code `0` after printing the summary.

### Phase 4 — Schema Replication

Synchronizes the target schema with the source. Controlled by four options in the YAML `options` block (see [Schema Synchronization](#schema-synchronization)):

- **Always** creates tables that exist in the source but not in the target.
- `enforce_column_types: true` — adds missing columns to existing target tables.
- `drop_extra_columns: true` — drops columns from existing target tables that are absent in the source.
- `drop_unknown_tables: true` — drops tables from the target that are not present in the source.

Each option can be overridden for a single run via CLI flags (`--enforce-column-types`, `--drop-unknown-tables`, `--drop-extra-columns`, `--disable-fk-checks` and their `--no-*` counterparts) without modifying the YAML file.

Skipped entirely when `--skip-schema` is set.

### Phase 5 — Dependency Resolution

Builds a foreign key dependency graph and topologically sorts tables in parent-first order to avoid FK violations during insert. Tables excluded by `--skip-tables` or `--only-tables` — and all tables with a FK dependency on them — are removed from the working set (see [Table Filtering](#table-filtering)).

### Phase 6 — Data Transfer

For each table (in resolved order):

1. If `options.disable_foreign_key_checks` is `true`, foreign-key constraints are disabled on the target connection.
2. If `rows.clear` is `truncate` or `delete`, the target table is emptied before any rows are inserted (see [Clearing Tables](#clearing-tables)).
3. Rows are fetched from the source in chunks and the configured column transformations are applied before inserting into the target.
4. On unique or FK constraint violations, falls back to row-by-row insert and records skipped rows.

#### Clearing Tables

The `rows.clear` setting in the YAML config controls whether the target table is emptied before transfer:

| Value | Behaviour |
|-------|-----------|
| `false` (default) | Target table is not cleared; transferred rows are appended |
| `truncate` | Issues `TRUNCATE TABLE` (SQLite: falls back to `DELETE FROM`) |
| `delete` | Issues `DELETE FROM` without a `WHERE` clause |

Clearing happens **after** FK checks are disabled, so `TRUNCATE` does not fail on FK-constrained tables (on databases that enforce this restriction at the statement level).

### Phase 7 — Audit Log & Process Log

Assembles and signs the HTML audit log and JSONL process log, then delivers them to the configured channels according to each channel's delivery settings. Skipped silently when `audit` is absent from `clonio.json`.

### Phase 8 — Summary

Prints the run summary. Tables not found in the source are always listed, regardless of verbosity level.

## Table Filtering

### `--skip-tables` and `--only-tables`

Both flags accept a comma-separated list of exact table names. Glob patterns and regex are not supported. Spaces around commas are trimmed.

```bash
# Skip two tables
clonio cloning:run prod.cloning.yaml --target staging --skip-tables=audit_logs,sessions

# Transfer only these tables
clonio cloning:run prod.cloning.yaml --target staging --only-tables=users,orders
```

Using both flags together is a `ValidationError (4)`.

### FK-Dependent Cascade

When a table is excluded, all tables with a foreign key dependency on it — directly or transitively — are also excluded automatically:

```
  Skipped tables:
    users        (--skip-tables)
    orders       (cascaded: FK dependency on users)
    order_items  (cascaded: FK dependency on orders)
```

Cascaded tables appear in the audit log with status `skipped_by_cascade`.

### Skipping tables in YAML

Instead of passing `--skip-tables` on every invocation, you can declare tables to skip permanently inside `cloning.yaml`. Two syntaxes are supported and can be combined:

**Top-level `skip:` list** — for tables that need no anonymisation config:

```yaml
skip:
  - audit_logs
  - telescope_entries
  - failed_jobs
```

**`rows.strategy: skip`** — for tables already present in `tables:`:

```yaml
tables:
  audit_logs:
    rows:
      strategy: skip
  users:
    rows:
      strategy: full
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
```

YAML-level skips and `--skip-tables` are **additive**: both lists are merged at runtime. The same cascade rules apply — tables with FK dependencies on a skipped table are also skipped automatically.

## Table Run Statuses

Each table in a run is recorded with one of the following statuses in the audit and process logs:

| Status | Meaning |
|--------|---------|
| `transferred` | Table was transferred successfully |
| `skipped_by_flag` | Table was excluded via `--skip-tables` or `--only-tables` |
| `skipped_by_cascade` | Table was excluded because it has a FK dependency on a skipped table |
| `skipped_by_schema_failure` | Table's schema could not be created in the target database (native DDL and fallback both failed); the data transfer step was skipped. Overall `success` is `false`. |
| `not_found` | Table is listed in the YAML but does not exist in the source database |
| `failed` | Table transfer was attempted but encountered an unrecoverable error |

## Output Modes

| Level | Flag | Output |
|-------|------|--------|
| quiet | `-q` / `--ci` | No output, exit code only |
| normal | (default) | Dot indicators (`.FE?S`), 70 chars per line, summary |
| verbose | `-v` | One line per table with status and row count |
| very verbose | `-vv` | Live streaming of run log events to stderr |

### `--ci` / `-q` (quiet)

No stdout. Errors written to stderr with `[ERROR]` prefix. Exit code only.

### Default (no flags)

Dot-style progress per table, with a summary at the end:

```
....F..........

  Warning: 1 table not found in source (audit_logs)
  Run failed: table "orders" — connection reset (120 rows skipped)

  Tables: 14/15  Rows: 48 302  Duration: 2m 14s
  Audit log: production-db_staging_2026-04-01T14-32-00Z_audit.html  ✓ delivered
```

**Dot indicators:**
- `.` — table transferred successfully
- `F` — table transferred with skipped rows
- `E` — table transfer failed
- `?` — table not found in source
- `S` — skipped due to schema replication failure

### `-v` (verbose)

One line per table with status and row count:

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

### `-vv` (very verbose)

Live streaming of run log events to stderr:

```
  ✓  users           12 340 rows   0 skipped
  ✓  orders          48 201 rows   0 skipped
  ✗  audit_logs      — not found in source, skipped
  ⚠  sessions        3 910 rows  120 skipped  (FK violation × 89, unique conflict × 31)
```

### `-vvv` (debug)

Full debug output: schema diff, per-table progress bars with rows/sec and ETA, chunk-level events, skipped row details, and delivery channel responses:

```
  Transferring table: orders
  ████████████████████░░░░░  18 000 / 48 201  (~312 rows/sec, ~1m 31s left)
```

Progress bars are suppressed when `--ci` is active regardless of verbosity level.

## Exit Codes

| Code | Meaning |
|:----:|---------|
| `0` | Run completed (all found tables transferred successfully) |
| `0` | Run completed with not-found tables (non-fatal warning) |
| `0` | `--allow-failure` was set (regardless of outcome) |
| `1` | Run failed — one or more tables had unrecoverable transfer errors |
| `2` | Config error — `clonio.json` missing, or `APP_KEY` not set |
| `3` | Connection error — source or target unreachable |
| `4` | Validation error — YAML invalid, `--skip-tables` + `--only-tables` combined, or CI without `--target` |
| `5` | IO error — YAML file not found or not readable |

## Audit & Process Logs

At the end of every successful run, Clonio produces two types of artefacts:

- **Audit log** (`*_audit.html` + `*_audit.sig`) — a signed, human-readable document listing all tables, transformations, and transfer counts. Suitable for compliance auditors.
- **Process log** (`*_process.jsonl`) — a structured, machine-readable JSONL execution log with per-table, per-chunk, and per-skipped-row events recorded during the run.

Files are named using the pattern:
```
{source}_{target}_{timestamp}_{type}.{ext}
```

For example:
```
production-db_staging_2026-04-01T14-32-00Z_audit.html
production-db_staging_2026-04-01T14-32-00Z_audit.sig
production-db_staging_2026-04-01T14-32-00Z_process.jsonl
```

### What each channel delivers

Each channel delivers artefacts based on its type and any per-channel overrides in `clonio.json`:

| Channel type | Audit log (default) | Process log (default) |
|---|---|---|
| `local` | Yes | Yes |
| `s3` | Yes | Yes |
| `email` | Yes | No |
| `ms_teams` | Yes | No |
| `slack` | Yes | No |
| `ntfy` | Yes | No |
| `stdout` / `stderr` | Yes | No |

Override the defaults for any individual channel with two optional boolean keys in its `clonio.json` entry:

```json
{
  "audit": {
    "default": "local",
    "channels": {
      "local": {
        "type": "local",
        "path": "./",
        "delivers_audit": true,
        "delivers_process_log": false
      }
    }
  }
}
```

Delivery channels are configured in the `audit` block of `clonio.json`. The `audit.use` array (list of channel names) selects which channels actively deliver artefacts. Use `--audit-channel=<list>` to override `audit.use` for a single run. If `audit` is absent from `clonio.json`, all delivery is silently skipped.

Verify the integrity of a stored audit log with `clonio cloning:verify-audit`.

## Related Commands

- `cloning:dump` — inspect a database and generate the `.cloning.yaml` file
- `cloning:verify-audit` — verify the integrity of a Clonio audit log
- `matchers:init` — export the baseline PII matchers for customisation
- `connection:add` — add a database connection
