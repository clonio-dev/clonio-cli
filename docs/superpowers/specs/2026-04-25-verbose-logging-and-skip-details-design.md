# Verbose Logging Rework + Per-Row Skip Details

**Date:** 2026-04-25
**Status:** Design

## Problem

Two pain points in the current `cloning:run` verbose output:

1. **Phase steps print after-the-fact.** Lines like `  ✓  Validating YAML ...` are emitted *after* the phase completes, with the green check pre-pended. While a long step runs the user sees nothing — no spinner, no "in flight" line — and then a finished line appears. This makes long phases feel hung.
2. **Row-level skips are silent.** In `app/Services/Cloning/CloningRunOrchestrator.php:300-307` the row-by-row insert fallback swallows every `Throwable` without binding the exception:
   ```php
   foreach ($transformed as $row) {
       try {
           DB::connection($targetConn)->table($tableConfig->tableName)->insert($row);
           $rows++;
       } catch (Throwable) {
           $skipped++;
       }
   }
   ```
   The user sees `users  (12.300 rows, 45 skipped)` and has no information about *why* those 45 rows were rejected — duplicate key? FK violation? truncated value? They have to bisect blindly.

## Goal

Rework verbose-mode output so that:

- Each phase step prints its label **at the start** of the work, with a spinner on TTY output, and is finalised at the **end of the line** with a green `✓` (success) or red `✗` (failure).
- The same start/finish pattern applies to per-table data transfer entries.
- Every row that fails to insert during the row-by-row fallback is captured with the SQL error, chunk offset, row index, and primary-key snapshot, written to the run log, and surfaced inline (grouped by error type) under the table line.

## Non-goals

- Changing the non-verbose (dot-mode) output format. Dots stay; only the run log gains the new `row_skipped` events for off-line analysis.
- Adding chunk-level progress within a single table transfer. The spinner during a table run shows only the table name, not chunk progress.
- A separate `storage/logs/skipped-rows-*.jsonl` file. The existing `RunLogWriter` and audit channels are sufficient.
- Restructuring the orchestrator's table loop or the existing `TableRunStatus` enum.

## Design

### 1. New service: `VerboseStepRenderer`

Path: `app/Services/Output/VerboseStepRenderer.php`

Owns the rendering of a step's lifecycle. Constructed with the Symfony `OutputInterface` and a `bool $ci` flag (so it can be made completely silent in CI mode). Internally it inspects `$output->getVerbosity()` to decide whether to render at all (only at `>= VERBOSITY_VERBOSE`) and `$output->isDecorated()` to decide between TTY and non-TTY rendering.

Public API:

```php
public function run(string $label, Closure $work): mixed;
public function start(string $label): void;
public function success(?string $suffix = null): void;
public function fail(?string $suffix = null): void;
public function note(string $line): void;
```

Behaviour:

- `run($label, $work)` is a convenience wrapper: `start($label)` → execute closure → `success()` on return, `fail()` on `Throwable` (then re-throw). The closure's return value is passed through.
- `start()` on a TTY: instantiates `Symfony\Component\Console\Helper\ProgressIndicator`, advances it once to render the first frame.
- `start()` on a non-TTY: writes the label as a plain line (no carriage return, no spinner).
- `success(?string $suffix)` on a TTY: finalises the indicator, then `\r`-overwrites the line with `  <label> [padding-dots] [suffix ]<info>✓</info>` padded to a fixed column (80).
- `success()` on a non-TTY: writes a second line `  <label> [suffix] ✓` (no `\r`).
- `fail()` mirrors `success()` with `<error>✗</error>`.
- `note($line)` always writes a plain indented line (used for the skip-group sub-lines under a table row). It does not interact with the indicator state.
- All methods are no-ops when verbosity is below `VERBOSITY_VERBOSE` *or* when `$ci === true`.

The renderer is instantiated directly in `RunCommand::handle()` with the per-command `OutputInterface` and the `--ci` flag. No service-container binding — the `OutputInterface` only becomes available once Symfony hands it to the command, so DI binding at boot would be premature.

### 2. New value object: `SkippedRow`

Path: `app/Services/Cloning/SkippedRow.php`

```php
final readonly class SkippedRow
{
    /**
     * @param array<string, mixed>|null $pkSnapshot
     */
    public function __construct(
        public string $tableName,
        public int $chunkOffset,
        public int $rowIndex,
        public ?array $pkSnapshot,
        public string $sqlError,
    ) {}
}
```

Used both in-memory (passed to `onProgress`) and serialised to the run log.

### 3. Orchestrator changes

File: `app/Services/Cloning/CloningRunOrchestrator.php`

**New callback:** `?Closure $onTableStart` parameter on `run()`. Signature: `function (string $tableName): void`. Fired once per table **immediately before** the first chunk query — i.e. only for tables that actually enter `transferTable()`. Tables resolved as `SkippedByFlag`, `SkippedByCascade`, `NotFound`, or `SkippedBySchemaFailure` do *not* fire `onTableStart`; they fall through to `onProgress` directly, as today.

**`transferTable()` signature change:** the return tuple gains a fifth element — `list<SkippedRow>`. Existing tuple is `[int $rows, int $skipped, bool $failed, ?string $reason]`; new tuple is `[int $rows, int $skipped, bool $failed, ?string $reason, list<SkippedRow> $skippedRows]`.

**Skip capture (replacement for lines 300-307):**

```php
foreach ($transformed as $rowIndexInChunk => $row) {
    try {
        DB::connection($targetConn)->table($tableConfig->tableName)->insert($row);
        $rows++;
    } catch (Throwable $rowError) {
        $skipped++;
        $skippedRow = new SkippedRow(
            tableName: $tableConfig->tableName,
            chunkOffset: $offset,
            rowIndex: $rowIndexInChunk,
            pkSnapshot: $this->extractPkSnapshot($chunk[$rowIndexInChunk], $pkColumns),
            sqlError: $rowError->getMessage(),
        );
        $skippedRows[] = $skippedRow;
        $this->runLog->log('warning', 'row_skipped', [
            'table' => $skippedRow->tableName,
            'chunk_offset' => $skippedRow->chunkOffset,
            'row_index' => $skippedRow->rowIndex,
            'pk' => $skippedRow->pkSnapshot,
            'error' => $skippedRow->sqlError,
        ]);
    }
}
```

`$pkColumns` is computed once per table at the top of `transferTable()` from the existing `$sourceSchema` (the orchestrator already has it). `extractPkSnapshot()` is a small private helper that reads each PK column's value from the *source* row (`$chunk[$rowIndexInChunk]`, before transformation) and returns `null` if `$pkColumns` is empty.

**`onProgress` signature change:** new fifth parameter `list<SkippedRow> $skippedRows`. Existing parameters unchanged.

### 4. `RunCommand` changes

File: `app/Commands/Cloning/RunCommand.php`

**Replace each `if ($isVerbose) { $this->line('  <info>✓</info>  ...'); }` block** with `$step->run('<label>', fn() => <existing logic>)`. The closure must contain only the work whose success/failure should drive the indicator. Where today's code prints a different verbose line on different branches (e.g. schema-diff: "matches" vs "Replicating schema"), use the explicit `start()`/`success(suffix)`/`fail()` API rather than `run()`.

**`onTableStart` closure:** `fn (string $tableName) => $step->start("  {$tableName}")`. Wired only in verbose mode; in non-verbose / CI it can be `null` (orchestrator must accept that).

**`onProgress` closure** is split by status:

- `Transferred`:
  - `$suffix = sprintf('(%s rows%s)', number_format($rows), $skipped > 0 ? ', '.$skipped.' skipped' : '');`
  - `$step->success($suffix);`
  - If `$skippedRows !== []`: aggregate by `sqlError`, sort desc by count, take top 10, render each as `$step->note(sprintf('     └ %d× %s', $count, $message))`. If more than 10 groups: append `$step->note(sprintf('     └ … and %d more error types', $rest))`.
- `Failed`:
  - `$step->fail();`
  - Render skip groups identically to the `Transferred` branch. (When *all* rows fail, the user gets both the failure indicator and the grouped reasons.)
- `NotFound`: today's `<comment>?</comment>  <table>  — not found in source, skipped` line, unchanged. No `start()` was called, no spinner state to clean up.
- `SkippedBySchemaFailure`: today's `<error>S</error>  <table>  — schema replication failed, skipped` line, unchanged.

**Aggregation helper** (private method on `RunCommand`):

```php
/**
 * @param list<SkippedRow> $rows
 * @return list<array{count: int, message: string}>
 */
private function aggregateSkipReasons(array $rows): array
{
    $byMessage = [];
    foreach ($rows as $row) {
        $byMessage[$row->sqlError] = ($byMessage[$row->sqlError] ?? 0) + 1;
    }
    arsort($byMessage);
    return array_map(
        fn (int $count, string $message) => ['count' => $count, 'message' => $message],
        array_values($byMessage),
        array_keys($byMessage),
    );
}
```

**Non-verbose path:** unchanged. The dot-mode rendering at lines 437-460 stays as-is. Skipped rows are still captured by the orchestrator and written to the run log; only the inline grouped sub-lines are skipped.

### 5. Output column / padding

The fixed right-margin column for the trailing `✓`/`✗` is **80**. Phase-step labels are short ("Validating YAML", "Connecting to <src> and <tgt> ...") and stay well under this. Table lines (`  <table>  (<n> rows, <m> skipped)`) can exceed 80 in pathological cases — when they do, the indicator renders one space before the symbol with no padding dots, and the line wraps naturally. Acceptable; no special handling.

The padding character between label and indicator is a single space followed by a run of `.` to the target column. Width is computed from `mb_strlen($label)` to handle multi-byte safely.

### 6. Run-log impact

Every row-by-row failure now writes one `row_skipped` event to `RunLogWriter`. Pre-existing audit channels (stdout, file, PDF, …) consume the run log unchanged — they will simply include more events. No schema migration or new event-type registry is needed; the writer already accepts arbitrary `event` strings.

When `-vv` is active, the existing `setLiveOutput` callback (RunCommand:97-104) emits each `row_skipped` event as a JSON line on STDERR — i.e. very-verbose mode now shows full per-row detail in real time, with no extra code.

## Edge cases

- **Closure inside `$step->run()` writes to STDOUT itself.** The renderer calls `$indicator->finish('')` before any `\r` rewrite, so a half-rendered spinner cannot survive into a stray write. Closures should not write to STDOUT, but if they do, the worst case is interleaved output — not a corrupted indicator.
- **All rows in a table fail.** Existing path at orchestrator lines 313-319 (`$rows === 0 && $skipped > 0` → return `failed = true` with reason). The new `skippedRows` list is still populated and forwarded; `RunCommand` renders `✗` plus the grouped skip lines. No regression.
- **Composite or missing primary key.** `$pkColumns` is empty → `pkSnapshot = null`. Run log entry still includes `chunk_offset` and `row_index`, which together uniquely identify the source row.
- **Memory pressure on huge skip lists.** Inline display caps at 10 groups; aggregation is by hash map on `sqlError` so it does not retain raw `SkippedRow` objects. The `list<SkippedRow>` *is* held until `onProgress` returns — for runs with millions of skips on a single table this could be heavy. If observed in practice, the follow-up is to stream aggregation directly inside the orchestrator. Out of scope here; flagged as a known limit.
- **`--ci` mode.** `VerboseStepRenderer` is constructed with `$ci = true` and no-ops everywhere. Existing CI behaviour (collecting `$notFoundTables` / `$schemaFailureTables` for the summary block) stays intact in the `onProgress` closure.
- **Re-thrown exceptions in `transferTable()`'s outer `try`.** Connection-level failures (line 323-324) still produce `Failed` status with a reason. No skip rows captured for those — they never entered the row loop. Unchanged.

## Sample console output (verbose mode, mixed success / partial-skip / total-fail)

```
$ clonio cloning:run cloning.yml --target=staging -v

  Validating YAML ......................................................  ✓
  Connecting to production and staging .................................  ✓
  Resolving table order .................................................  ✓
  Generating key mappings ...............................................  ✓
  ✓  Key mapping: users (1.245.000 rows)
  ✓  Key mapping: orders (8.732.412 rows)
  Schema diff: target matches source ....................................  ✓

  users  (1.245.000 rows) ...............................................  ✓
  user_profiles  (1.244.998 rows, 2 skipped) ............................  ✓
       └ 2× SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate
         entry '8421' for key 'user_profiles.PRIMARY'
  orders  (8.731.987 rows, 425 skipped) .................................  ✓
       └ 312× SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot
         add or update a child row: a foreign key constraint fails
       └ 98× SQLSTATE[22001]: Data truncation: Data too long for column
         'shipping_address' at row 1
       └ 15× SQLSTATE[HY000]: General error: 1366 Incorrect integer value:
         '' for column 'discount_cents' at row 1
  invoice_lines  ........................................................  ✗
       └ 1.024× SQLSTATE[42S22]: Column not found: 1054 Unknown column
         'tax_rate_v2' in 'field list'
  ?  legacy_audit_trail  — not found in source, skipped
  S  reports_cache  — schema replication failed, skipped

  Generating audit log ..................................................  ✓
  Delivering via stdout, file ...........................................  ✓
```

## Test plan

### New unit tests

`tests/Unit/Services/Output/VerboseStepRendererTest.php`:

- TTY mode (`OutputInterface::isDecorated() === true`): after `success()`, buffer contains `✓` and the label.
- Non-TTY mode: two separate lines, no `\r` sequence in output.
- `run()` passes the closure's return value through.
- `run()` renders `✗` and re-throws on `Throwable`; buffer contains `✗`.
- Below `VERBOSITY_VERBOSE`: every method is a no-op, buffer stays empty.
- `$ci = true`: every method is a no-op even at high verbosity.
- `note()` writes an indented line that does not interact with the spinner.
- `success($suffix)` places the suffix between label and `✓`.

`tests/Unit/Services/Cloning/SkippedRowTest.php`:

- Constructs and exposes all properties; readonly enforced (PHPStan-level check).

### Extended unit tests

`tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`:

- DB stub: bulk insert throws once, row-by-row succeeds for M of N rows and throws for the rest with two distinct messages.
- Asserts `transferTable()` returns `skippedRows` of length `N - M` with correct `chunkOffset`, `rowIndex`, `sqlError`.
- Asserts one `row_skipped` event per failure on the `RunLogWriter`.
- Asserts `onTableStart` is called exactly once before `onProgress` for transferred tables.
- Asserts `onTableStart` is *not* called for `SkippedByFlag`, `SkippedByCascade`, `NotFound`, or `SkippedBySchemaFailure` tables.
- Asserts `pkSnapshot` is populated when schema has a PK and `null` when it does not.

### Extended feature tests

`tests/Feature/Commands/Cloning/RunCommandTest.php`:

- Verbose run with partial skips → output contains `✓` at the end of the table line *and* the indented `└ Nx ...` group sub-lines, ordered by descending count.
- Verbose run with a fully failing table → output contains `✗` and the same grouped sub-lines.
- Non-verbose run with skips → output contains `F` dot but *no* sub-lines; run-log assertion confirms the `row_skipped` events were still emitted.
- Verbose phase steps: the `Validating YAML` line appears *before* any subsequent step (assert on output ordering); after a YAML failure the line ends with `✗`.

### Static analysis

- New code passes `composer test:types` (PHPStan level max).
- `composer test:type-coverage` stays at ≥ 90%; new files are 100% typed.

### Coverage

- `composer test:unit` minimum coverage stays at 75% — new files contribute coverage for both the renderer and the skip-capture path.
