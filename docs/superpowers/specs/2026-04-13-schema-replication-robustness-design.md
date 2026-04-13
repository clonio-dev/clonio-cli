# Schema Replication Robustness

**Date:** 2026-04-13
**Status:** Approved

## Problem

Two errors were observed in production use:

1. **`schema_replication_failed`** — MySQL `SHOW CREATE TABLE` DDL was being sanitised incorrectly. The regex `FOREIGN KEY[^,)]+` stopped at the first `)` inside the FK column list (e.g. `FOREIGN KEY (`permission_id`)`), leaving `) REFERENCES `api_permissions` (`id`) ON DELETE CASCADE` as orphaned text attached to the preceding KEY line. This produced syntactically invalid SQL and caused `CREATE TABLE` to fail.

2. **`table_transfer_failed` (cascade)** — Because the table was never created, the subsequent `DELETE FROM` / `INSERT` during data transfer threw `Table does not exist`. This was logged as a transfer failure even though the real cause was the schema step.

## Goals

- Fix the FK-stripping regex so native DDL is always valid
- Add a per-table fallback when native DDL fails: retry using inspector-based `buildCreateTableSql()`
- Surface which tables failed schema creation so the orchestrator can skip their data transfer gracefully
- After data transfer, correct `AUTO_INCREMENT` values to `MAX(pk)+1` for integer-PK tables on MySQL/MariaDB targets
- Introduce `--break-on-failure` flag on `cloning:run` for users who want hard-abort on first error
- Update `docs/commands/cloning-run.md` to document the new flag and status

## Architecture

### 1. Fix `sanitiseNativeDdl()` in `SchemaReplicator`

Replace the buggy regex with a line-anchored pattern using multiline mode:

```php
// Before (buggy — stops at first ) in FK column list)
$ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY[^,)]+(?:REFERENCES[^,)]+)?/i', '', $ddl);

// After (correct — strips entire FK line to end-of-line)
$ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY.*$/im', '', $ddl);
```

With the `m` flag, `$` anchors to end-of-line and `.` does not match newlines, so the entire constraint line (including `REFERENCES ... ON DELETE CASCADE`) is removed.

Also update the existing test `it strips FOREIGN KEY constraints from native DDL` to additionally assert `->not->toContain('REFERENCES')`.

### 2. Per-table fallback in `SchemaReplicator::replicate()`

Change return type from `void` to `array<string, string>` (map of `tableName → errorMessage` for tables whose schema creation failed completely). `SchemaReplicator` has no `RunLogWriter` — all logging for schema failures is done by the orchestrator using the returned map.

For each table that needs to be created (not yet in target):

1. Attempt native DDL (`fetchNativeCreateTableDdl()` → execute on target)
2. If execution throws → catch, retry with `buildCreateTableSql()`
3. If retry also throws → add `tableName => $e->getMessage()` to failure map, continue to next table

Tables where the fallback succeeded are **not** in the failure map.

### 3. New `TableRunStatus` case

```php
case SkippedBySchemaFailure = 'skipped_by_schema_failure';
```

### 4. Orchestrator changes in `CloningRunOrchestrator::run()`

- Accept new parameter `bool $breakOnFailure = false`
- Receive `array<string, string> $schemaFailures` from `replicate()`
- Log `warning` event `schema_table_native_ddl_failed` for each entry where fallback was triggered (not surfaced from replicate directly — orchestrator infers from final failure map)
- Log `error` event `schema_table_failed` for each entry in the failure map
- Before transferring each table: if `$tableName` is in `$schemaFailures`, record `SkippedBySchemaFailure`, log `warning` event `table_skipped_schema_failure`, set `$success = false`, then either continue (default) or break (if `$breakOnFailure`)
- Existing data-transfer failures also respect `$breakOnFailure`
- `success: false` whenever at least one table is `Failed` or `SkippedBySchemaFailure`
- Audit log is written in all cases, including when `--break-on-failure` aborts early

### 5. `--break-on-failure` flag on `cloning:run`

New optional boolean flag. Passed as `$breakOnFailure` into `CloningRunOrchestrator::run()`.

| Scenario | Without flag | With `--break-on-failure` |
|---|---|---|
| Schema failure on table A | Continue, skip A's data | Abort run |
| Data failure on table B | Continue | Abort run |
| All tables OK | `success: true` | `success: true` |
| Partial failure | `success: false`, full results | `success: false`, partial results |

Audit log is written in both cases.

### 6. AUTO_INCREMENT correction

New method `SchemaReplicator::correctAutoIncrement(ConnectionData $target, string $tableName, string $pkColumn): void`.

Called from `CloningRunOrchestrator` after each successful table data transfer, when all of the following are true:

- Target is MySQL or MariaDB
- The table has exactly one PK column in `sourceSchema`
- That column's type is an integer type: `int`, `bigint`, `mediumint`, `smallint`, `tinyint` (unsigned variants included)

```sql
SELECT COALESCE(MAX(`{pk_col}`), 0) + 1 AS next_val FROM `{table}`
ALTER TABLE `{table}` AUTO_INCREMENT = {next_val}
```

`correctAutoIncrement()` throws on error; the orchestrator catches it, logs `warning` event `auto_increment_correction_failed`, and continues. Composite PKs are skipped by the orchestrator before calling the method (no single AUTO_INCREMENT column applies).

### 7. Documentation

Update `docs/commands/cloning-run.md`:
- Document `--break-on-failure` flag with description and example
- Add `skipped_by_schema_failure` to the table run status reference

## Files Changed

| File | Change |
|---|---|
| `app/Services/Cloning/SchemaReplicator.php` | Fix regex, per-table fallback, return `array<string, string>`, add `correctAutoIncrement()` |
| `app/Services/Cloning/CloningRunOrchestrator.php` | Handle `$schemaFailures`, `$breakOnFailure`, call `correctAutoIncrement()` |
| `app/Data/Cloning/TableRunStatus.php` | Add `SkippedBySchemaFailure` case |
| `app/Commands/Cloning/RunCommand.php` | Add `--break-on-failure` flag |
| `tests/Unit/Services/Cloning/SchemaReplicatorTest.php` | Fix existing FK test, add fallback/AUTO_INCREMENT tests |
| `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` | Add schema-failure and break-on-failure tests |
| `tests/Feature/Commands/RunCommandTest.php` | Add `--break-on-failure` feature test |
| `docs/commands/cloning-run.md` | Document new flag and status |

## Test Plan

- `sanitiseNativeDdl()` with FK containing `REFERENCES ... ON DELETE CASCADE` → no `REFERENCES` in output, no `FOREIGN KEY`, SQL ends with valid `)`
- Native DDL execution fails → fallback to `buildCreateTableSql()` is called and succeeds
- Both strategies fail → table name appears in `replicate()` return value
- `SkippedBySchemaFailure` tables appear in `RunResultData`, `success: false`
- `--break-on-failure` aborts loop after first failure, remaining tables absent from results
- Without flag: all tables processed, multiple failures recorded
- AUTO_INCREMENT set correctly after transfer; failure in setting it does not throw
- Composite PK tables: AUTO_INCREMENT step skipped
