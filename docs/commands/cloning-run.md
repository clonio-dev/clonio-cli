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
| `--audit-channel=<list>` | Comma-separated list of channel names to use for this run (overrides `deliver_to` in `clonio.json`) |

`--skip-tables` and `--only-tables` are mutually exclusive. Verbosity flags `-v` / `-vv` / `-vvv` are also supported (see [Output Modes](#output-modes)).

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

Validates the config, tests connectivity, and estimates row counts without moving any data:

```bash
clonio cloning:run production-db.cloning.yaml --target staging --dry-run
```

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

## The 8-Phase Pipeline

```
Phase 1 — YAML Validation
Phase 2 — Connection Checks
Phase 3 — Dry-run              (only if --dry-run; exits here)
Phase 4 — Schema Replication   (skipped if --skip-schema)
Phase 5 — Dependency Resolution
Phase 6 — Data Transfer
Phase 7 — Audit Log & Run Log
Phase 8 — Summary
```

Each phase must complete before the next begins.

### Phase 1 — YAML Validation

Loads and validates the YAML file against the Clonio schema. All validation errors are reported at once. Logical constraints are also checked (e.g. `first`/`last` row strategy requires a `limit`; `hash` requires a supported `algorithm`).

### Phase 2 — Connection Checks

Resolves the source connection (from the YAML `connection` field) and the target connection (from `--target` or interactive selection). Tests both with a real PDO ping. Verifies that source and target are not the same connection.

### Phase 3 — Dry-run

Only runs when `--dry-run` is set. Fetches the source schema, counts estimated rows per table, and prints a summary. Tables not found in the source are shown as `NOT FOUND`. Exits with code `0` after printing the summary.

### Phase 4 — Schema Replication

Replicates the source schema to the target: creates missing tables, adds missing columns. Controlled by `options.enforce_column_types` and `options.drop_unknown_tables` in the YAML. Skipped when `--skip-schema` is set.

### Phase 5 — Dependency Resolution

Builds a foreign key dependency graph and topologically sorts tables in parent-first order to avoid FK violations during insert. Tables excluded by `--skip-tables` or `--only-tables` — and all tables with a FK dependency on them — are removed from the working set (see [Table Filtering](#table-filtering)).

### Phase 6 — Data Transfer

For each table (in resolved order), fetches rows in chunks and applies the configured column transformations before inserting into the target. On unique or FK constraint violations, falls back to row-by-row insert and records skipped rows.

### Phase 7 — Audit Log & Run Log

Assembles and signs the HTML audit log and JSONL run log, then delivers both to the configured channels. Skipped silently when `audit` is absent from `clonio.json`.

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

## Output Modes

### `--ci` (CI mode)

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

| Symbol | Meaning |
|--------|---------|
| `.` | Table transferred successfully |
| `F` | Table had skipped or failed rows |
| `E` | Table aborted with an unrecoverable error |
| `?` | Table not found in source (skipped) |

### `-v` (verbose)

One line per phase and per table:

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

Same as `-v` plus per-table row counts, skipped counts, and reasons:

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

## Audit & Run Logs

At the end of every successful run, Clonio produces two artefacts:

- **Audit log** (`*_audit.html` + `*_audit.sig`) — a signed, human-readable document listing all tables, transformations, and transfer counts. Suitable for compliance auditors.
- **Run log** (`*_run.jsonl`) — a structured, machine-readable execution log with per-table, per-chunk, and per-skipped-row events.

Files are named using the pattern:
```
{source}_{target}_{timestamp}_{type}.{ext}
```

For example:
```
production-db_staging_2026-04-01T14-32-00Z_audit.html
production-db_staging_2026-04-01T14-32-00Z_audit.sig
production-db_staging_2026-04-01T14-32-00Z_run.jsonl
```

Delivery channels (local filesystem, S3-compatible storage, email) are configured in the `audit` block of `clonio.json`. Use `--audit-channel=<name>` to override the configured channels for a single run. If `audit` is absent from `clonio.json`, all delivery is silently skipped.

Verify the integrity of a stored audit log with `clonio cloning:verify-audit`.

## Related Commands

- `cloning:dump` — inspect a database and generate the `.cloning.yaml` file
- `cloning:verify-audit` — verify the integrity of a Clonio audit log
- `matchers:init` — export the baseline PII matchers for customisation
- `connection:add` — add a database connection
