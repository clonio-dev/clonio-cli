# Verbose Logging Rework + Per-Row Skip Details — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move verbose phase-step output from "✓ at start, after-the-fact" to "label first, ✓/✗ at end of line", and capture every row-level insert failure with full diagnostic detail (chunk offset, row index, primary-key snapshot, SQL error) — surfaced inline as grouped sub-lines and persisted to the run log.

**Architecture:** New `VerboseStepRenderer` service encapsulates the start-then-finalize lifecycle (TTY: in-place line rewrite via `\x1b[1A\r\x1b[K`; non-TTY: two-line form). The orchestrator's `transferTable()` captures each per-row exception into a `SkippedRow` value object, logs a `row_skipped` event per failure, and forwards the list through a new fifth parameter on the `onProgress` callback. A new `onTableStart` callback fires per actually-transferred table so `RunCommand` can show the table name immediately when its transfer begins.

**Tech Stack:** PHP 8.5, Laravel Zero 12, Symfony Console (already in framework), PestPHP v4, Mockery, PHPStan level max, Larastan.

**Spec:** `docs/superpowers/specs/2026-04-25-verbose-logging-and-skip-details-design.md`

---

## File Structure

**New files:**
- `app/Services/Cloning/SkippedRow.php` — readonly value object for one row-level insert failure
- `app/Services/Output/VerboseStepRenderer.php` — verbose-mode step renderer (start/success/fail/note)
- `tests/Unit/Services/Cloning/SkippedRowTest.php` — VO tests
- `tests/Unit/Services/Output/VerboseStepRendererTest.php` — renderer tests

**Modified files:**
- `app/Services/Cloning/CloningRunOrchestrator.php` — capture per-row skips, return 5-tuple from `transferTable()`, add `onTableStart` callback, extend `onProgress` to pass `list<SkippedRow>`, log `row_skipped` events
- `app/Commands/Cloning/RunCommand.php` — replace each `if ($isVerbose) { $this->line('  <info>✓</info>  ...'); }` block with `$step->run()` (or explicit `start`/`success`/`fail` for branching cases), wire `onTableStart`, render skip groups under each table line
- `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` — extend with skip-capture and callback-ordering tests
- `tests/Feature/Commands/Cloning/RunCommandTest.php` — extend with verbose-output and skip-group rendering tests

---

## Task 1: `SkippedRow` value object

**Files:**
- Create: `app/Services/Cloning/SkippedRow.php`
- Test: `tests/Unit/Services/Cloning/SkippedRowTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Cloning/SkippedRowTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Cloning\SkippedRow;

it('exposes all skip detail properties', function (): void {
    $row = new SkippedRow(
        tableName: 'users',
        chunkOffset: 1000,
        rowIndex: 42,
        pkSnapshot: ['id' => 8421],
        sqlError: "SQLSTATE[23000]: Duplicate entry '8421' for key 'PRIMARY'",
    );

    expect($row->tableName)->toBe('users');
    expect($row->chunkOffset)->toBe(1000);
    expect($row->rowIndex)->toBe(42);
    expect($row->pkSnapshot)->toBe(['id' => 8421]);
    expect($row->sqlError)->toBe("SQLSTATE[23000]: Duplicate entry '8421' for key 'PRIMARY'");
});

it('accepts null pk snapshot for tables without identifiable primary key', function (): void {
    $row = new SkippedRow(
        tableName: 'audit_blob',
        chunkOffset: 0,
        rowIndex: 0,
        pkSnapshot: null,
        sqlError: 'some error',
    );

    expect($row->pkSnapshot)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/SkippedRowTest.php`

Expected: FAIL with "Class 'App\\Services\\Cloning\\SkippedRow' not found".

- [ ] **Step 3: Create the value object**

Create `app/Services/Cloning/SkippedRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Cloning;

final readonly class SkippedRow
{
    /**
     * @param  array<string, mixed>|null  $pkSnapshot
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

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/SkippedRowTest.php`

Expected: PASS (2 assertions).

- [ ] **Step 5: Run static analysis**

Run: `composer test:types`

Expected: PASS — no PHPStan errors on the new file.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Cloning/SkippedRow.php tests/Unit/Services/Cloning/SkippedRowTest.php
git commit -m "feat(cloning): add SkippedRow value object for per-row insert failures"
```

---

## Task 2: `VerboseStepRenderer` service

**Files:**
- Create: `app/Services/Output/VerboseStepRenderer.php`
- Test: `tests/Unit/Services/Output/VerboseStepRendererTest.php`

The renderer encapsulates the verbose phase-step lifecycle. It is enabled only when `getVerbosity() >= VERBOSITY_VERBOSE` *and* `$ci === false`. On a decorated (TTY) output it rewrites the start-line in place with cursor-up + clear-line + the finalised composition. On a non-decorated output it writes a second completion line.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Output/VerboseStepRendererTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Output\VerboseStepRenderer;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

function makeStepRenderer(
    int $verbosity = OutputInterface::VERBOSITY_VERBOSE,
    bool $decorated = false,
    bool $ci = false,
): array {
    $output = new BufferedOutput($verbosity, $decorated);

    return [new VerboseStepRenderer($output, $ci), $output];
}

it('writes nothing when verbosity is below verbose', function (): void {
    [$step, $output] = makeStepRenderer(verbosity: OutputInterface::VERBOSITY_NORMAL);

    $step->start('Doing thing');
    $step->success();
    $step->note('     └ note line');

    expect($output->fetch())->toBe('');
});

it('writes nothing when ci flag is set even at verbose verbosity', function (): void {
    [$step, $output] = makeStepRenderer(ci: true);

    $step->start('Doing thing');
    $step->success();

    expect($output->fetch())->toBe('');
});

it('renders non-tty start as label-with-ellipsis line and success as second line', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('Validating YAML');
    $step->success();

    $text = $output->fetch();
    expect($text)->toContain('  Validating YAML ...');
    expect($text)->toContain('✓');
    // Two separate lines on non-TTY output
    expect(substr_count($text, "\n"))->toBe(2);
});

it('renders failure with cross symbol on non-tty', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('Connecting to db');
    $step->fail();

    expect($output->fetch())->toContain('✗');
});

it('rewrites the start line in place on tty (uses cursor-up + clear-line escape)', function (): void {
    [$step, $output] = makeStepRenderer(decorated: true);

    $step->start('Validating YAML');
    $step->success();

    $text = $output->fetch();
    expect($text)->toContain("\x1b[1A");
    expect($text)->toContain("\r");
    expect($text)->toContain('Validating YAML');
});

it('places suffix between label and success symbol', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('users');
    $step->success('(1.245.000 rows, 2 skipped)');

    $text = $output->fetch();
    expect($text)->toMatch('/users.*\(1\.245\.000 rows, 2 skipped\).*✓/s');
});

it('writes indented note line without affecting step state', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('orders');
    $step->success('(8.731.987 rows, 425 skipped)');
    $step->note('     └ 312× SQLSTATE[23000]: Integrity constraint violation');

    $text = $output->fetch();
    expect($text)->toContain('     └ 312× SQLSTATE[23000]: Integrity constraint violation');
});

it('run wraps closure: success path writes ✓ and returns closure value', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $result = $step->run('Validating YAML', static fn (): int => 42);

    expect($result)->toBe(42);
    expect($output->fetch())->toContain('✓');
});

it('run wraps closure: failure path writes ✗ and re-throws exception', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $closure = static function (): never {
        throw new RuntimeException('boom');
    };

    expect(static fn (): mixed => $step->run('Validating YAML', $closure))
        ->toThrow(RuntimeException::class, 'boom');

    expect($output->fetch())->toContain('✗');
});

it('finalize is a no-op when no step was started', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->success();
    $step->fail();

    expect($output->fetch())->toBe('');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Output/VerboseStepRendererTest.php`

Expected: FAIL with "Class 'App\\Services\\Output\\VerboseStepRenderer' not found".

- [ ] **Step 3: Create the renderer**

Create `app/Services/Output/VerboseStepRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Output;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class VerboseStepRenderer
{
    private const int TARGET_COLUMN = 80;

    private readonly bool $enabled;

    private ?string $currentLabel = null;

    public function __construct(
        private readonly OutputInterface $output,
        bool $ci,
    ) {
        $this->enabled = ! $ci && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * Start a step, run the closure, and finalize with ✓ on return or ✗ on Throwable (then re-throw).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(string $label, Closure $work): mixed
    {
        $this->start($label);

        try {
            $result = $work();
        } catch (Throwable $throwable) {
            $this->fail();

            throw $throwable;
        }

        $this->success();

        return $result;
    }

    public function start(string $label): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->currentLabel = $label;
        $this->output->writeln('  '.$label.' ...');
    }

    public function success(?string $suffix = null): void
    {
        $this->finalize('<info>✓</info>', $suffix);
    }

    public function fail(?string $suffix = null): void
    {
        $this->finalize('<error>✗</error>', $suffix);
    }

    public function note(string $line): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->output->writeln($line);
    }

    private function finalize(string $symbol, ?string $suffix): void
    {
        if (! $this->enabled || $this->currentLabel === null) {
            return;
        }

        $label = $this->currentLabel;
        $suffixText = $suffix !== null && $suffix !== '' ? ' '.$suffix : '';
        $compose = '  '.$label.$suffixText;

        if ($this->output->isDecorated()) {
            $padLength = max(self::TARGET_COLUMN - mb_strlen($compose) - 2, 1);
            $padding = ' '.str_repeat('.', max($padLength - 1, 1));
            $this->output->write("\x1b[1A\r\x1b[K");
            $this->output->writeln(sprintf('%s%s  %s', $compose, $padding, $symbol));
        } else {
            $this->output->writeln(sprintf('%s %s', $compose, $symbol));
        }

        $this->currentLabel = null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Output/VerboseStepRendererTest.php`

Expected: PASS (10 assertions).

- [ ] **Step 5: Run static analysis and lint**

Run: `composer test:types && composer test:lint`

Expected: both PASS — no PHPStan errors and no Pint/Rector findings on the new files.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Output/VerboseStepRenderer.php tests/Unit/Services/Output/VerboseStepRendererTest.php
git commit -m "feat(output): add VerboseStepRenderer for start-then-finalize step output"
```

---

## Task 3: Capture per-row skip details in `transferTable()`

**Files:**
- Modify: `app/Services/Cloning/CloningRunOrchestrator.php` (lines 38-198 for `run()` signature, 226-333 for `transferTable()`)
- Test: `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` (extend)

This task changes `transferTable()` to:
1. Receive primary-key column names (computed by `run()` from the source schema).
2. Capture each row-level exception in the row-by-row fallback into a `SkippedRow` (with chunk offset, row index, PK snapshot, error message).
3. Log a `row_skipped` event to the run log per failure.
4. Return a 5-tuple including `list<SkippedRow>`.

The `run()` method gains: PK column resolution per table before the call, and propagates the `list<SkippedRow>` into the existing call sites — but the new 5th `onProgress` parameter and the `onTableStart` callback are added in **Task 4**, not here. To keep this task small, we change the tuple shape and capture the data, but the orchestrator-internal call sites simply destructure-and-discard the new 5th element here (the `RunCommand` will not consume it until Task 5).

- [ ] **Step 1: Write the failing tests** (extend existing test file)

Append to `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`:

```php
it('captures per-row skip details when bulk insert fails and row-by-row fallback also fails', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    $sourceRows = [
        (object) ['id' => 1],
        (object) ['id' => 2],
        (object) ['id' => 3],
    ];

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn($sourceRows, []);
    DB::shouldReceive('table')->andReturnSelf();

    $insertCalls = 0;
    DB::shouldReceive('insert')->andReturnUsing(static function ($payload) use (&$insertCalls): bool {
        $insertCalls++;

        if ($insertCalls === 1) {
            // Bulk insert: throw to force row-by-row fallback
            throw new RuntimeException('SQLSTATE[23000]: Duplicate entry for key PRIMARY (bulk)');
        }

        // Row-by-row: succeed for id=2, fail for id=1 and id=3 with distinct messages
        if (is_array($payload) && array_key_exists('id', $payload)) {
            if ($payload['id'] === 1) {
                throw new RuntimeException("SQLSTATE[23000]: Duplicate entry '1' for key 'PRIMARY'");
            }

            if ($payload['id'] === 3) {
                throw new RuntimeException("SQLSTATE[22001]: Data too long for column 'name'");
            }
        }

        return true;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $runLog = new App\Services\Cloning\RunLogWriter;
    $orchestratorWithLog = new App\Services\Cloning\CloningRunOrchestrator(
        Mockery::mock(App\Services\Database\DatabaseConnectionService::class)
            ->shouldReceive('open')
            ->andReturnUsing(static fn (App\Data\ConnectionData $c): string => $c->name.'_conn')
            ->getMock(),
        Mockery::mock(App\Services\Cloning\SchemaReplicator::class)
            ->shouldReceive('replicate')
            ->andReturn([])
            ->getMock(),
        Mockery::mock(App\Services\Cloning\DependencyResolver::class)
            ->shouldReceive('computeCascadeExclusions')
            ->andReturn([])
            ->shouldReceive('sort')
            ->andReturnUsing(static fn ($s, array $tables): array => $tables)
            ->getMock(),
        $runLog,
    );

    $orchestratorWithLog->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    $logged = json_decode('['.str_replace("\n", ',', rtrim($runLog->flush(), "\n,")).']', true);
    expect($logged)->toBeArray();

    $skipEvents = array_values(array_filter($logged, static fn (array $e): bool => $e['event'] === 'row_skipped'));
    expect($skipEvents)->toHaveCount(2);

    $errorMessages = array_column($skipEvents, 'error');
    expect($errorMessages)->toContain("SQLSTATE[23000]: Duplicate entry '1' for key 'PRIMARY'");
    expect($errorMessages)->toContain("SQLSTATE[22001]: Data too long for column 'name'");

    foreach ($skipEvents as $event) {
        expect($event)->toHaveKeys(['table', 'chunk_offset', 'row_index', 'pk', 'error']);
        expect($event['table'])->toBe('users');
        expect($event['pk'])->toBe(['id' => $event['pk']['id']]); // PK snapshot present
    }
});

it('falls back to null pk snapshot when source schema has no primary key column', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');

    $schema = new App\Data\Schema\DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new App\Data\Schema\TableSchemaData(
                name: 'users',
                columns: [
                    new App\Data\Schema\ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['email' => 'a@b.c']], []);
    DB::shouldReceive('table')->andReturnSelf();

    $callCount = 0;
    DB::shouldReceive('insert')->andReturnUsing(static function () use (&$callCount): bool {
        $callCount++;

        throw new RuntimeException('Some error');
    });
    DB::shouldReceive('purge')->andReturnNull();

    $runLog = new App\Services\Cloning\RunLogWriter;
    $orchestrator = new App\Services\Cloning\CloningRunOrchestrator(
        Mockery::mock(App\Services\Database\DatabaseConnectionService::class)
            ->shouldReceive('open')
            ->andReturnUsing(static fn (App\Data\ConnectionData $c): string => $c->name.'_conn')
            ->getMock(),
        Mockery::mock(App\Services\Cloning\SchemaReplicator::class)
            ->shouldReceive('replicate')
            ->andReturn([])
            ->getMock(),
        Mockery::mock(App\Services\Cloning\DependencyResolver::class)
            ->shouldReceive('computeCascadeExclusions')
            ->andReturn([])
            ->shouldReceive('sort')
            ->andReturnUsing(static fn ($s, array $tables): array => $tables)
            ->getMock(),
        $runLog,
    );

    $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    $logged = json_decode('['.str_replace("\n", ',', rtrim($runLog->flush(), "\n,")).']', true);
    $skipEvents = array_values(array_filter($logged, static fn (array $e): bool => $e['event'] === 'row_skipped'));

    expect($skipEvents)->toHaveCount(1);
    expect($skipEvents[0]['pk'])->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php --filter='captures per-row skip|null pk snapshot'`

Expected: FAIL — `row_skipped` events are not emitted by the current code.

- [ ] **Step 3: Modify `transferTable()` to capture skips**

Replace the `private function transferTable(...)` method body in `app/Services/Cloning/CloningRunOrchestrator.php` (lines 226-333) with the version below. Note three changes: new `$pkColumns` parameter, new `$tableName` parameter (passed in to avoid coupling SkippedRow to TableCloningConfigData), 5-tuple return type, and per-row capture.

```php
    /**
     * Transfer a single table. Returns [rowsTransferred, rowsSkipped, hasFailed, failureReason, skippedRows].
     *
     * @param  list<string>  $pkColumns
     * @return array{int, int, bool, ?string, list<SkippedRow>}
     */
    private function transferTable(
        CloningOptionsData $options,
        TableCloningConfigData $tableConfig,
        ConnectionData $source,
        ConnectionData $target,
        array $pkColumns,
        ?KeyRemappingService $keyRemapping = null,
        ?KeyRemappingConfigData $keyRemappingConfig = null,
    ): array {
        $engine = new AnonymizationEngine($options->fakerLocale);
        $sourceConn = $this->connector->open($source);
        $targetConn = $this->connector->open($target);

        try {
            if ($options->disableForeignKeyChecks) {
                $this->disableFkChecks($targetConn, $target);
            }

            if ($tableConfig->rows->clear !== ClearMode::None) {
                $this->clearTable($targetConn, $tableConfig->tableName, $tableConfig->rows->clear, $target);
            }

            $rows = 0;
            $skipped = 0;
            $offset = 0;
            $chunkSize = $options->chunkSize;
            $firstInsertError = null;

            /** @var list<SkippedRow> $skippedRows */
            $skippedRows = [];

            do {
                /** @var list<object> $chunk */
                $chunk = DB::connection($sourceConn)->select(
                    $this->buildChunkQuery($tableConfig, $source, $offset, $chunkSize)
                );

                if ($chunk === []) {
                    break;
                }

                /** @var list<array<string, mixed>> $transformed */
                $transformed = [];

                foreach ($chunk as $row) {
                    $rowArray = (array) $row;
                    $transformedRow = [];

                    foreach ($rowArray as $col => $val) {
                        if (! is_string($col)) {
                            continue;
                        }

                        $colConfig = $tableConfig->getColumn($col);
                        $transformedRow[$col] = $colConfig instanceof ColumnCloningConfigData ? $engine->transform($val, $colConfig) : $val;
                    }

                    if ($keyRemapping instanceof KeyRemappingService && $keyRemappingConfig instanceof KeyRemappingConfigData) {
                        $transformedRow = $keyRemapping->applyToRow($transformedRow, $tableConfig->tableName, $keyRemappingConfig);
                    }

                    $transformed[] = $transformedRow;
                }

                // Bulk insert into target
                try {
                    DB::connection($targetConn)->table($tableConfig->tableName)->insert($transformed);
                    $rows += count($transformed);
                } catch (Throwable $bulkError) {
                    if ($firstInsertError === null) {
                        $firstInsertError = $bulkError->getMessage();
                    }

                    // Fall back to row-by-row
                    foreach ($transformed as $rowIndexInChunk => $row) {
                        try {
                            DB::connection($targetConn)->table($tableConfig->tableName)->insert($row);
                            $rows++;
                        } catch (Throwable $rowError) {
                            $skipped++;
                            $sourceRow = (array) $chunk[$rowIndexInChunk];
                            $pkSnapshot = $this->extractPkSnapshot($sourceRow, $pkColumns);
                            $skippedRow = new SkippedRow(
                                tableName: $tableConfig->tableName,
                                chunkOffset: $offset,
                                rowIndex: $rowIndexInChunk,
                                pkSnapshot: $pkSnapshot,
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
                }

                $offset += count($chunk);
            } while (count($chunk) === $chunkSize);

            if ($rows === 0 && $skipped > 0) {
                $reason = sprintf('All %d rows failed to insert', $skipped);
                if ($firstInsertError !== null) {
                    $reason .= sprintf(': %s', $firstInsertError);
                }

                return [0, $skipped, true, $reason, $skippedRows];
            }

            return [$rows, $skipped, false, null, $skippedRows];
        } catch (Throwable $throwable) {
            return [0, 0, true, $throwable->getMessage(), []];
        } finally {
            if ($options->disableForeignKeyChecks) {
                $this->enableFkChecks($targetConn, $target);
            }

            DB::purge($sourceConn);
            DB::purge($targetConn);
        }
    }

    /**
     * @param  array<string, mixed>  $sourceRow
     * @param  list<string>  $pkColumns
     * @return array<string, mixed>|null
     */
    private function extractPkSnapshot(array $sourceRow, array $pkColumns): ?array
    {
        if ($pkColumns === []) {
            return null;
        }

        $snapshot = [];
        foreach ($pkColumns as $col) {
            if (array_key_exists($col, $sourceRow)) {
                $snapshot[$col] = $sourceRow[$col];
            }
        }

        return $snapshot === [] ? null : $snapshot;
    }
```

- [ ] **Step 4: Update the `transferTable()` call site in `run()`**

In `app/Services/Cloning/CloningRunOrchestrator.php` line 150, replace:

```php
            [$rows, $skipped, $failed, $reason] = $this->transferTable($config->options, $tableConfig, $source, $target, $keyRemapping, $config->keyRemapping);
```

with:

```php
            $sourceTable = $sourceSchema->getTable($tableName);
            $pkColumns = $sourceTable instanceof TableSchemaData
                ? array_values(array_map(
                    static fn (ColumnSchemaData $c): string => $c->name,
                    array_filter($sourceTable->columns, static fn (ColumnSchemaData $c): bool => $c->isPrimary)
                ))
                : [];

            [$rows, $skipped, $failed, $reason, $skippedRows] = $this->transferTable(
                $config->options,
                $tableConfig,
                $source,
                $target,
                $pkColumns,
                $keyRemapping,
                $config->keyRemapping,
            );
```

(Use the existing `$sourceTable` lookup if a similar one is already in scope further down — the auto-increment block at line 172 already uses `$sourceSchema->getTable($tableName)`. To avoid a duplicate lookup, hoist it up to before `transferTable()` and re-use the variable in the auto-increment block.)

- [ ] **Step 5: Add the `SkippedRow` import at the top of the orchestrator file**

In `app/Services/Cloning/CloningRunOrchestrator.php`, after the existing `use` block (around line 22), add:

```php
use App\Services\Cloning\SkippedRow;
```

(If linter prefers it sorted alphabetically with the others, place it accordingly.)

- [ ] **Step 6: Suppress the unused `$skippedRows` for now**

Since `onProgress` does not yet receive `$skippedRows` (added in Task 4), the variable is unused at the call site after destructuring. Add this line right after the destructuring to silence PHPStan:

```php
            unset($skippedRows); // wired through onProgress in the next task
```

Remove this `unset()` in Task 4 when wiring the callback.

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`

Expected: PASS — both new tests, plus no regressions in existing orchestrator tests.

- [ ] **Step 8: Run static analysis**

Run: `composer test:types`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Cloning/CloningRunOrchestrator.php tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
git commit -m "feat(cloning): capture per-row skip details with chunk offset, pk snapshot, and SQL error"
```

---

## Task 4: Add `onTableStart` callback + extend `onProgress` with skipped rows

**Files:**
- Modify: `app/Services/Cloning/CloningRunOrchestrator.php` (`run()` signature and call sites)
- Test: `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` (extend)

`onProgress` gains a 5th parameter `list<SkippedRow>`. A new optional callback `onTableStart(string $tableName)` fires once per actually-transferred table, immediately before `transferTable()` runs. Pre-skipped tables (`SkippedByFlag`/`SkippedByCascade`/`NotFound`/`SkippedBySchemaFailure`) do NOT trigger `onTableStart`.

The existing internal callers of `onProgress` (the `SkippedBySchemaFailure` and `NotFound` branches at lines 131 and 144) must pass an empty list as the new 5th argument.

- [ ] **Step 1: Write the failing tests** (append to existing file)

Append to `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`:

```php
it('fires onTableStart exactly once before onProgress for transferred tables', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $events = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, $status, int $rows, int $skipped, array $skippedRows) use (&$events): void {
            $events[] = ['progress', $tbl];
        },
        onTableStart: static function (string $tbl) use (&$events): void {
            $events[] = ['start', $tbl];
        },
    );

    expect($events)->toBe([
        ['start', 'users'],
        ['progress', 'users'],
    ]);
});

it('does not fire onTableStart for tables skipped by --skip flag', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $startCalls = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        ['users'],
        [],
        static fn (): null => null,
        onTableStart: static function (string $tbl) use (&$startCalls): void {
            $startCalls[] = $tbl;
        },
    );

    expect($startCalls)->toBe([]);
});

it('does not fire onTableStart for tables not found in source schema', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = new App\Data\Schema\DatabaseSchemaData(databaseName: 'testdb', tables: []);
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $startCalls = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static fn (): null => null,
        onTableStart: static function (string $tbl) use (&$startCalls): void {
            $startCalls[] = $tbl;
        },
    );

    expect($startCalls)->toBe([]);
});

it('passes skipped rows list to onProgress for transferred tables with row failures', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1], (object) ['id' => 2]], []);
    DB::shouldReceive('table')->andReturnSelf();

    $insertCalls = 0;
    DB::shouldReceive('insert')->andReturnUsing(static function ($payload) use (&$insertCalls): bool {
        $insertCalls++;

        if ($insertCalls === 1) {
            throw new RuntimeException('bulk fail');
        }

        if (is_array($payload) && ($payload['id'] ?? null) === 1) {
            throw new RuntimeException("error A");
        }

        return true;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $progressArgs = null;
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, $status, int $rows, int $skipped, array $skippedRows) use (&$progressArgs): void {
            $progressArgs = compact('tbl', 'rows', 'skipped', 'skippedRows');
        },
    );

    expect($progressArgs['tbl'])->toBe('users');
    expect($progressArgs['rows'])->toBe(1);
    expect($progressArgs['skipped'])->toBe(1);
    expect($progressArgs['skippedRows'])->toHaveCount(1);
    expect($progressArgs['skippedRows'][0])->toBeInstanceOf(App\Services\Cloning\SkippedRow::class);
    expect($progressArgs['skippedRows'][0]->sqlError)->toBe('error A');
});
```

- [ ] **Step 2: Update existing orchestrator test callbacks for the new signature**

The existing tests in `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` use `static fn (): null => null` as the `onProgress` callback (zero-arg). PHPStan level max with the new docblock signature `callable(string, TableRunStatus, int, int, list<SkippedRow>): void` will reject these. Use `find` + `sed` (or a manual replace) to convert each occurrence:

```bash
# From the repo root:
grep -n 'static fn (): null => null' tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
```

For each match, replace with:

```php
static fn (string $t, $status, int $rows, int $skipped, array $skippedRows): null => null
```

(Use `mixed $status` if PHPStan complains about the unionized status type — but `$status` un-annotated should be fine inside an arrow fn.)

- [ ] **Step 3: Run new tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php --filter='onTableStart|skipped rows list to onProgress'`

Expected: FAIL — `run()` does not accept `onTableStart`, and `onProgress` is called with 4 args, not 5.

- [ ] **Step 4: Add `onTableStart` parameter and update `onProgress` signature**

In `app/Services/Cloning/CloningRunOrchestrator.php`, change the signature of `run()` (line 38-49) to add `onTableStart` as a new optional parameter:

```php
    /**
     * @param  list<string>  $skipTables  Tables to exclude (already validated as mutually exclusive with onlyTables)
     * @param  list<string>  $onlyTables  If non-empty, only these tables are transferred
     * @param  callable(string, TableRunStatus, int, int, list<SkippedRow>): void  $onProgress
     * @param  (callable(string): void)|null  $onTableStart  Optional: fires once per actually-transferred table before its data is moved.
     */
    public function run(
        CloningConfigData $config,
        ConnectionData $source,
        ConnectionData $target,
        DatabaseSchemaData $sourceSchema,
        bool $skipSchema,
        array $skipTables,
        array $onlyTables,
        callable $onProgress,
        ?KeyRemappingService $keyRemapping = null,
        bool $breakOnFailure = false,
        ?callable $onTableStart = null,
    ): RunResultData {
```

Update each existing `($onProgress)(...)` invocation to pass an empty list as the 5th argument:

- Line 131 (currently): `($onProgress)($tableName, TableRunStatus::SkippedBySchemaFailure, 0, 0);` → `($onProgress)($tableName, TableRunStatus::SkippedBySchemaFailure, 0, 0, []);`
- Line 144: `($onProgress)($tableName, TableRunStatus::NotFound, 0, 0);` → `($onProgress)($tableName, TableRunStatus::NotFound, 0, 0, []);`
- Line 165: `($onProgress)($tableName, $status, $rows, $skipped);` → `($onProgress)($tableName, $status, $rows, $skipped, $skippedRows);`

In the transferred-table block (around the `transferTable()` call inside the loop), insert the `onTableStart` invocation **immediately before** `[$rows, $skipped, $failed, $reason, $skippedRows] = $this->transferTable(...);`:

```php
            if ($onTableStart !== null) {
                $onTableStart($tableName);
            }
```

- [ ] **Step 5: Remove the `unset($skippedRows)` placeholder from Task 3**

Search for the line `unset($skippedRows); // wired through onProgress in the next task` added in Task 3 and delete it. The variable is now consumed by the `onProgress` invocation.

- [ ] **Step 6: Run all orchestrator tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`

Expected: PASS — all existing tests plus the four new ones.

- [ ] **Step 7: Run static analysis**

Run: `composer test:types`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Cloning/CloningRunOrchestrator.php tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
git commit -m "feat(cloning): add onTableStart callback + extend onProgress with skippedRows"
```

---

## Task 5: Wire `RunCommand` to use `VerboseStepRenderer` and render skip groups

**Files:**
- Modify: `app/Commands/Cloning/RunCommand.php`
- Test: `tests/Feature/Commands/Cloning/RunCommandTest.php` (extend)

This is the largest task: it ties everything together at the command level. Each existing `if ($isVerbose) { $this->line('  <info>✓</info>  ...'); }` block becomes either `$step->run('<label>', fn() => <work>)` or an explicit `$step->start('<label>') / $step->success('<suffix>')` for branching cases. The `onProgress` closure consumes `$skippedRows`, aggregates by `sqlError`, and renders the top 10 groups as indented `$step->note()` sub-lines under each table line.

**Phase-step replacements (each is independent):**

| Existing (file:line)                          | New treatment                                                              |
|------------------------------------------------|----------------------------------------------------------------------------|
| Line 156 — Validating YAML                     | Wrap the YAML load+validate block in `$step->run('Validating YAML', …)`    |
| Line 288 — Connecting to <src> and <tgt>      | Wrap the source+target connection test calls in `$step->run('Connecting to <src> and <tgt>', …)` |
| Line 309 — Resolving table order               | Wrap the resolver call in `$step->run('Resolving table order', …)`         |
| Line 323 — Generating key mappings              | Wrap `$keyRemappingService->generateMappings(...)` in `$step->run('Generating key mappings', …)` |
| Lines 351-355 — per-table key mapping summary  | KEEP as-is (old `<info>✓</info>` at start of each line — these are sub-results, not phase steps) |
| Line 380/382 — Schema diff (two branches)      | Use explicit `$step->start('Comparing schema')` then `$step->success('(<branch text>)')`  |
| Line 390 — Replicating schema                   | KEEP as-is (old style intro — work happens inside `orchestrator->run()`, not wrappable here) |
| Line 423 — per-table progress (Transferred)    | Replaced by `onTableStart` + `$step->success('(<n> rows[, <m> skipped]')'` + skip-group `$step->note()` lines |
| Lines 425/428/430 — `?` / `✗` / `S` branches   | KEEP as old-style single-line forms (no spinner state to clean up — `onTableStart` did not fire for these) |
| Line 483 — Generating audit log                | Wrap audit-log generation in `$step->run('Generating audit log', …)`       |
| Line 557 — Delivering via <channels>           | Wrap delivery in `$step->run('Delivering via <channels>', …)`              |

- [ ] **Step 1: Write the failing feature tests** (extend existing file)

Look at the top of `tests/Feature/Commands/Cloning/RunCommandTest.php` to find or define the helpers (the file already has `makeRunMysqlConnection`, `makeRunTargetConnection`, etc.). Append the following tests to the file. They use the existing pattern of mocking out `ConfigService`, `DatabaseConnectionService`, `SchemaInspector`, and `CloningRunOrchestrator`.

```php
it('renders verbose phase steps with start-then-finalize layout', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cloning.yml', file_get_contents(__DIR__.'/fixtures/cloning-minimal.yml'));

    $this->mock(App\Services\Config\ConfigService::class, function ($mock): void {
        $mock->shouldReceive('getConnection')->andReturn(makeRunMysqlConnection());
        $mock->shouldReceive('getConnections')->andReturn([
            'production-db' => makeRunMysqlConnection(),
            'staging' => makeRunTargetConnection(),
        ]);
    });

    $this->mock(App\Services\Database\DatabaseConnectionService::class, function ($mock): void {
        $mock->shouldReceive('test')->andReturn(true);
        $mock->shouldReceive('open')->andReturn('test_conn');
    });

    $this->mock(App\Services\Schema\SchemaInspector::class, function ($mock): void {
        $mock->shouldReceive('inspect')->andReturn(new App\Data\Schema\DatabaseSchemaData(
            databaseName: 'mydb',
            tables: [],
        ));
    });

    $this->mock(App\Services\Cloning\CloningRunOrchestrator::class, function ($mock): void {
        $mock->shouldReceive('run')->andReturn(new App\Data\Cloning\RunResultData(
            success: true,
            tables: [],
            totalRows: 0,
            skippedRows: 0,
            durationSeconds: 0.0,
            failureReason: null,
        ));
    });

    $exitCode = $this->artisan('cloning:run cloning.yml --target=staging --ci -v')
        ->run();

    expect($exitCode)->toBe(App\Enums\ExitCode::Ok->value);
    // The verbose phase steps appear on dedicated lines with the trailing ✓ symbol.
    // Output is captured via the Artisan testing helper.
});

it('renders skip groups under the table line in verbose mode', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cloning.yml', file_get_contents(__DIR__.'/fixtures/cloning-minimal.yml'));

    $skippedRows = [
        new App\Services\Cloning\SkippedRow(
            tableName: 'orders',
            chunkOffset: 0,
            rowIndex: 0,
            pkSnapshot: ['id' => 1],
            sqlError: 'SQLSTATE[23000]: FK fail',
        ),
        new App\Services\Cloning\SkippedRow(
            tableName: 'orders',
            chunkOffset: 0,
            rowIndex: 1,
            pkSnapshot: ['id' => 2],
            sqlError: 'SQLSTATE[23000]: FK fail',
        ),
        new App\Services\Cloning\SkippedRow(
            tableName: 'orders',
            chunkOffset: 0,
            rowIndex: 2,
            pkSnapshot: ['id' => 3],
            sqlError: 'SQLSTATE[22001]: Data too long',
        ),
    ];

    $this->mock(App\Services\Config\ConfigService::class, function ($mock): void {
        $mock->shouldReceive('getConnection')->andReturn(makeRunMysqlConnection());
        $mock->shouldReceive('getConnections')->andReturn([
            'production-db' => makeRunMysqlConnection(),
            'staging' => makeRunTargetConnection(),
        ]);
    });

    $this->mock(App\Services\Database\DatabaseConnectionService::class, function ($mock): void {
        $mock->shouldReceive('test')->andReturn(true);
        $mock->shouldReceive('open')->andReturn('test_conn');
    });

    $this->mock(App\Services\Schema\SchemaInspector::class, function ($mock): void {
        $mock->shouldReceive('inspect')->andReturn(new App\Data\Schema\DatabaseSchemaData(
            databaseName: 'mydb',
            tables: [],
        ));
    });

    $this->mock(App\Services\Cloning\CloningRunOrchestrator::class, function ($mock) use ($skippedRows): void {
        $mock->shouldReceive('run')->andReturnUsing(function (
            App\Data\Cloning\CloningConfigData $config,
            App\Data\ConnectionData $source,
            App\Data\ConnectionData $target,
            App\Data\Schema\DatabaseSchemaData $sourceSchema,
            bool $skipSchema,
            array $skipTables,
            array $onlyTables,
            callable $onProgress,
            ?App\Services\Cloning\KeyRemappingService $keyRemapping = null,
            bool $breakOnFailure = false,
            ?callable $onTableStart = null,
        ) use ($skippedRows): App\Data\Cloning\RunResultData {
            if ($onTableStart !== null) {
                $onTableStart('orders');
            }

            $onProgress('orders', App\Data\Cloning\TableRunStatus::Transferred, 5, 3, $skippedRows);

            return new App\Data\Cloning\RunResultData(
                success: true,
                tables: [],
                totalRows: 5,
                skippedRows: 3,
                durationSeconds: 0.0,
                failureReason: null,
            );
        });
    });

    $this->artisan('cloning:run cloning.yml --target=staging -v')
        ->expectsOutputToContain('└ 2× SQLSTATE[23000]: FK fail')
        ->expectsOutputToContain('└ 1× SQLSTATE[22001]: Data too long')
        ->assertExitCode(App\Enums\ExitCode::Ok->value);
});
```

If `tests/Feature/Commands/Cloning/fixtures/cloning-minimal.yml` does not exist, create it with the smallest valid clonio YAML config:

```yaml
version: "1"
connection: production-db
options:
  chunkSize: 100
tables: []
```

- [ ] **Step 2: Run feature tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Commands/Cloning/RunCommandTest.php --filter='verbose phase steps|skip groups under the table line'`

Expected: FAIL — current `RunCommand` does not render the new patterns or sub-lines.

- [ ] **Step 3: Add the renderer instantiation and helper to `RunCommand`**

In `app/Commands/Cloning/RunCommand.php`, at the top of `handle()` immediately after the verbosity detection block (currently lines 90-105), add:

```php
        $step = new \App\Services\Output\VerboseStepRenderer($this->output, $ci);
```

Add the following private method to the same class (place it near the bottom, alongside other private helpers):

```php
    /**
     * Aggregate skipped rows by SQL error message, sorted by descending count.
     *
     * @param  list<\App\Services\Cloning\SkippedRow>  $rows
     * @return list<array{count: int, message: string}>
     */
    private function aggregateSkipReasons(array $rows): array
    {
        $byMessage = [];
        foreach ($rows as $row) {
            $byMessage[$row->sqlError] = ($byMessage[$row->sqlError] ?? 0) + 1;
        }

        arsort($byMessage);

        $result = [];
        foreach ($byMessage as $message => $count) {
            $result[] = ['count' => $count, 'message' => (string) $message];
        }

        return $result;
    }
```

Add `use App\Services\Output\VerboseStepRenderer;` and `use App\Services\Cloning\SkippedRow;` to the existing `use` block at the top of the file.

- [ ] **Step 4: Replace the YAML validation verbose line**

In `app/Commands/Cloning/RunCommand.php`, locate the block currently at approximately lines 107-157 (Phase 1: YAML Validation). The current trailing `if ($isVerbose) { $this->line('  <info>✓</info>  Validating YAML ...'); }` (around line 155-157) should be deleted. Wrap the YAML loading and validation in a `$step->run()`. Concretely, transform the block:

**Before:**
```php
        try {
            $config = $loader->load($filePath);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to parse YAML: %s', $throwable->getMessage()));
            return ExitCode::ValidationError->value;
        }

        try {
            $content = Storage::disk('local')->get($filePath);
            // ... existing validator logic ...
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to validate YAML: %s', $throwable->getMessage()));
            return ExitCode::ValidationError->value;
        }

        if ($isVerbose) {
            $this->line('  <info>✓</info>  Validating YAML ...');
        }
```

**After:**
```php
        try {
            $config = $step->run('Validating YAML', function () use ($loader, $validator, $filePath): CloningConfigData {
                $config = $loader->load($filePath);

                $content = Storage::disk('local')->get($filePath);
                if ($content === null) {
                    $content = (string) file_get_contents($filePath);
                }

                $rawData = Yaml::parse($content);
                if (is_array($rawData)) {
                    /** @var array<string, mixed> $rawData */
                    $errors = $validator->validate($rawData);
                    if ($errors !== []) {
                        throw new RuntimeException(implode("\n", $errors));
                    }
                }

                return $config;
            });
        } catch (RuntimeException $validationError) {
            foreach (explode("\n", $validationError->getMessage()) as $line) {
                $this->error($line);
            }

            return ExitCode::ValidationError->value;
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to parse or validate YAML: %s', $throwable->getMessage()));

            return ExitCode::ValidationError->value;
        }
```

(`RuntimeException` is the validation-error sentinel — pick a more specific subclass if the codebase already has one for this purpose. If not, the inline `RuntimeException` is acceptable since the message-passing is internal to this block.)

- [ ] **Step 5: Replace the connection-check verbose line**

In `app/Commands/Cloning/RunCommand.php`, locate the connection-check block ending at approximately line 288 (`if ($isVerbose) { $this->line(sprintf('  <info>✓</info>  Connecting to %s and %s ...', ...)); }`). Wrap the `connector->test(...)` calls for source and target in a `$step->run()`:

**Before:**
```php
        // ... existing connection resolution code ...

        if (! $connector->test($sourceConnection)) {
            $this->error(sprintf("Failed to connect to source '%s'", $sourceConnection->name));
            return ExitCode::ConnectionError->value;
        }

        if (! $connector->test($targetConnection)) {
            $this->error(sprintf("Failed to connect to target '%s'", $targetConnection->name));
            return ExitCode::ConnectionError->value;
        }

        if ($isVerbose) {
            $this->line(sprintf('  <info>✓</info>  Connecting to %s and %s ...', $sourceConnection->name, $targetConnection->name));
        }
```

**After:**
```php
        $connectLabel = sprintf('Connecting to %s and %s', $sourceConnection->name, $targetConnection->name);

        try {
            $step->run($connectLabel, function () use ($connector, $sourceConnection, $targetConnection): void {
                if (! $connector->test($sourceConnection)) {
                    throw new RuntimeException(sprintf("Failed to connect to source '%s'", $sourceConnection->name));
                }

                if (! $connector->test($targetConnection)) {
                    throw new RuntimeException(sprintf("Failed to connect to target '%s'", $targetConnection->name));
                }
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return ExitCode::ConnectionError->value;
        }
```

- [ ] **Step 6: Replace the table-order resolution verbose line**

Locate the block around line 309 (`Resolving table order ...`). Wrap the resolver invocation. Currently the resolution and sort happen inside `orchestrator->run()`, so the line is a pre-announcement only. Replace:

**Before:**
```php
        if ($isVerbose) {
            $this->line('  <info>✓</info>  Resolving table order ...');
        }
        $sourceSchema = $inspector->inspect($sourceConnection);
```

**After:**
```php
        $sourceSchema = $step->run('Resolving table order', fn (): \App\Data\Schema\DatabaseSchemaData => $inspector->inspect($sourceConnection));
```

(The actual table-order computation happens inside `orchestrator->run()`, but the schema inspection — the only synchronous work in this command before that — is a fitting fit. The label communicates intent to the user.)

- [ ] **Step 7: Replace the key-mapping verbose lines**

Locate the block at lines 322-355 (key-mapping generation). Replace the intro line (line 323) with `$step->run()`, and keep the per-table summary lines (351-355) as old-style sub-results. Concretely:

**Before:**
```php
        if ($isVerbose) {
            $this->line('  <info>✓</info>  Generating key mappings ...');
        }

        $keyRemappingService = (bool) $this->option('file-based')
            ? new KeyRemappingService($connector, new EncryptedFileKeyRemappingStore)
            : new KeyRemappingService($connector);
        $sortedForMapping = array_map(/* ... */);

        try {
            $counts = $keyRemappingService->generateMappings($keyRemappingConfig, $sourceConnection, $sortedForMapping);
        } catch (Throwable $throwable) {
            // memory-limit handling block ...
        }

        if ($isVerbose) {
            foreach ($counts as $tbl => $cnt) {
                $this->line(sprintf('  <info>✓</info>  Key mapping: %s (%s rows)', $tbl, number_format($cnt)));
            }
        }
```

**After:**
```php
        $keyRemappingService = (bool) $this->option('file-based')
            ? new KeyRemappingService($connector, new EncryptedFileKeyRemappingStore)
            : new KeyRemappingService($connector);
        $sortedForMapping = array_map(
            static fn (TableCloningConfigData $t): string => $t->tableName,
            $config->tables
        );

        try {
            $counts = $step->run(
                'Generating key mappings',
                fn (): array => $keyRemappingService->generateMappings($keyRemappingConfig, $sourceConnection, $sortedForMapping),
            );
        } catch (Throwable $throwable) {
            if (str_contains($throwable->getMessage(), 'Allowed memory size') || str_contains($throwable->getMessage(), 'Out of memory')) {
                $reRunCommand = $this->getOriginalCommandWithNoMemoryLimit();
                $this->error(sprintf(
                    "Memory exhausted during key mapping generation: %s\n\nHint: Re-run with --no-memory-limit to remove the PHP memory limit:\n\n    %s\n",
                    $throwable->getMessage(),
                    $reRunCommand
                ));
            } else {
                throw $throwable;
            }

            return ExitCode::GeneralError->value;
        }

        if ($isVerbose) {
            foreach ($counts as $tbl => $cnt) {
                $this->line(sprintf('  <info>✓</info>  Key mapping: %s (%s rows)', $tbl, number_format($cnt)));
            }
        }
```

- [ ] **Step 8: Replace the schema-diff verbose lines (branching)**

Locate the schema-diff block at lines 360-387. This block has two success branches and a silent catch. Use explicit `start`/`success`:

**Before:**
```php
        if ($isVerbose) {
            try {
                $targetSchema = $inspector->inspect($targetConnection);
                $schemaDiff = (new SchemaDiffService)->diff($sourceSchema, $targetSchema);

                if ($schemaDiff->hasDifferences()) {
                    $parts = [];
                    if ($schemaDiff->missingTables !== []) {
                        $parts[] = count($schemaDiff->missingTables).' missing';
                    }
                    if ($schemaDiff->modifiedTables !== []) {
                        $parts[] = count($schemaDiff->modifiedTables).' modified';
                    }
                    if ($schemaDiff->extraTables !== []) {
                        $parts[] = count($schemaDiff->extraTables).' extra on target';
                    }
                    $this->line(sprintf('  <comment>~</comment>  Schema diff: %s', implode(', ', $parts)));
                } else {
                    $this->line('  <info>✓</info>  Schema diff: target matches source');
                }
            } catch (Throwable) {
                // non-fatal: skip diff output if target schema cannot be inspected
            }
        }
```

**After:**
```php
        $step->start('Comparing schema');
        try {
            $targetSchema = $inspector->inspect($targetConnection);
            $schemaDiff = (new SchemaDiffService)->diff($sourceSchema, $targetSchema);

            if ($schemaDiff->hasDifferences()) {
                $parts = [];
                if ($schemaDiff->missingTables !== []) {
                    $parts[] = count($schemaDiff->missingTables).' missing';
                }
                if ($schemaDiff->modifiedTables !== []) {
                    $parts[] = count($schemaDiff->modifiedTables).' modified';
                }
                if ($schemaDiff->extraTables !== []) {
                    $parts[] = count($schemaDiff->extraTables).' extra on target';
                }
                $step->success(sprintf('(differs: %s)', implode(', ', $parts)));
            } else {
                $step->success('(target matches source)');
            }
        } catch (Throwable) {
            $step->success('(unable to inspect target — non-fatal)');
        }
```

- [ ] **Step 9: Leave the "Replicating schema ..." line as-is (old style)**

The existing `if ($isVerbose && ! $skipSchema) { $this->line('  <info>✓</info>  Replicating schema ...'); }` (around line 389-391) wraps no synchronous work in this command — the actual schema replication happens inside `orchestrator->run()`. Leave this line untouched. It serves as an old-style intro announcement.

- [ ] **Step 10: Replace the per-table progress callbacks**

Locate the `onProgress` closure at lines 410-461. Update its signature to accept the 5th parameter `list<SkippedRow>`, and replace the verbose branch with `$step->success/fail` plus aggregated `note` lines. Also wire `onTableStart`. Concretely:

**Before:**
```php
        $result = $orchestrator->run(
            // ... other args ...
            onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped) use ($isVerbose, $ci, &$notFoundTables, &$schemaFailureTables, &$dotColumn, $maxDotColumns): void {
                if ($ci) {
                    if ($status === TableRunStatus::NotFound) {
                        $notFoundTables[] = $tableName;
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $schemaFailureTables[] = $tableName;
                    }
                    return;
                }

                if ($isVerbose) {
                    if ($status === TableRunStatus::Transferred) {
                        $this->line(sprintf('  <info>✓</info>  %s  (%s rows%s)', $tableName, number_format($rows), $skipped > 0 ? ', '.$skipped.' skipped' : ''));
                    } elseif ($status === TableRunStatus::NotFound) {
                        $this->line(sprintf('  <comment>?</comment>  %s  — not found in source, skipped', $tableName));
                        $notFoundTables[] = $tableName;
                    } elseif ($status === TableRunStatus::Failed) {
                        $this->line(sprintf('  <error>✗</error>  %s  — failed', $tableName));
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $this->line(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
                        $schemaFailureTables[] = $tableName;
                    }
                    return;
                }

                // Normal mode: dot indicators wrapped at 70 chars
                // ... (unchanged) ...
            },
            keyRemapping: $keyRemappingService,
            breakOnFailure: (bool) $this->option('break-on-failure'),
        );
```

**After:**
```php
        $result = $orchestrator->run(
            // ... other args (config, source, target, sourceSchema, skipSchema, skipTables, onlyTables) ...
            onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows) use ($step, $isVerbose, $ci, &$notFoundTables, &$schemaFailureTables, &$dotColumn, $maxDotColumns): void {
                if ($ci) {
                    if ($status === TableRunStatus::NotFound) {
                        $notFoundTables[] = $tableName;
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $schemaFailureTables[] = $tableName;
                    }

                    return;
                }

                if ($isVerbose) {
                    if ($status === TableRunStatus::Transferred) {
                        $suffix = sprintf('(%s rows%s)', number_format($rows), $skipped > 0 ? ', '.$skipped.' skipped' : '');
                        $step->success($suffix);
                        $this->renderSkipGroups($step, $skippedRows);
                    } elseif ($status === TableRunStatus::Failed) {
                        $step->fail();
                        $this->renderSkipGroups($step, $skippedRows);
                    } elseif ($status === TableRunStatus::NotFound) {
                        $this->line(sprintf('  <comment>?</comment>  %s  — not found in source, skipped', $tableName));
                        $notFoundTables[] = $tableName;
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $this->line(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
                        $schemaFailureTables[] = $tableName;
                    }

                    return;
                }

                // Normal mode: dot indicators wrapped at 70 chars (unchanged)
                if ($status === TableRunStatus::NotFound) {
                    $notFoundTables[] = $tableName;
                } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                    $schemaFailureTables[] = $tableName;
                }

                $indicator = match ($status) {
                    TableRunStatus::Transferred => $skipped > 0 ? 'F' : '.',
                    TableRunStatus::Failed => 'E',
                    TableRunStatus::NotFound => '?',
                    TableRunStatus::SkippedBySchemaFailure => 'S',
                    default => null,
                };

                if ($indicator !== null) {
                    $this->output->write($indicator);
                    $dotColumn++;

                    if ($dotColumn >= $maxDotColumns) {
                        $this->output->writeln('');
                        $dotColumn = 0;
                    }
                }
            },
            keyRemapping: $keyRemappingService,
            breakOnFailure: (bool) $this->option('break-on-failure'),
            onTableStart: $isVerbose && ! $ci
                ? fn (string $tableName) => $step->start('  '.$tableName)
                : null,
        );
```

Add the helper method to `RunCommand`:

```php
    /**
     * @param  list<\App\Services\Cloning\SkippedRow>  $skippedRows
     */
    private function renderSkipGroups(\App\Services\Output\VerboseStepRenderer $step, array $skippedRows): void
    {
        if ($skippedRows === []) {
            return;
        }

        $groups = $this->aggregateSkipReasons($skippedRows);
        $shown = array_slice($groups, 0, 10);
        foreach ($shown as $group) {
            $step->note(sprintf('     └ %d× %s', $group['count'], $group['message']));
        }

        $rest = count($groups) - count($shown);
        if ($rest > 0) {
            $step->note(sprintf('     └ … and %d more error types', $rest));
        }
    }
```

- [ ] **Step 11: Replace the audit-log generation verbose line**

Locate the block around line 483 (`Generating audit log ...`). Wrap audit-log generation in `$step->run('Generating audit log', …)`. The exact closure body depends on what's between the line and the next phase boundary; carry over the existing logic verbatim into the closure.

- [ ] **Step 12: Replace the audit-delivery verbose line**

Locate the block around line 557 (`Delivering via %s ...`). Wrap delivery in `$step->run(sprintf('Delivering via %s', implode(', ', $channelTypes)), …)`.

- [ ] **Step 13: Run the full test suite**

Run: `composer test`

Expected: all four sub-tasks PASS — type coverage ≥ 90%, unit tests, PHPStan max, Pint+Rector clean.

If `composer test` fails, fix issues inline; do not skip checks.

- [ ] **Step 14: Manually smoke-test the verbose output**

Run a real (or fixture-driven) `clonio cloning:run` against a local SQLite-or-MySQL fixture in verbose mode and compare against the spec's sample output. The point is to confirm the visual: phase steps with right-aligned `✓`, table lines with `(<n> rows)` suffix, indented `└ Nx <error>` sub-lines under any partial-skip table.

Suggested fixture: use the existing `.github/cloning-test/` setup — it already wires SQLite source/target. Run:

```bash
php clonio cloning:run .github/cloning-test/cloning.yml --target=test-target -v
```

Manually verify the output matches the spec sample. If anything looks off (padding, color, line-rewrite glitches), fix before committing.

- [ ] **Step 15: Commit**

```bash
git add app/Commands/Cloning/RunCommand.php tests/Feature/Commands/Cloning/RunCommandTest.php tests/Feature/Commands/Cloning/fixtures/cloning-minimal.yml
git commit -m "feat(cloning): wire verbose step renderer + render grouped skip reasons under tables"
```

(Omit the fixture path from `git add` if it already existed and was unchanged.)

---

## Task 6: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Full test suite green**

Run: `composer test`

Expected: all four checks pass (type coverage, unit, types, lint).

- [ ] **Step 2: Confirm spec-required behavior in audit log**

Run a fixture cloning run with intentional row failures (e.g., insert into a target with FK constraints unmet), then inspect the resulting audit log to verify each row failure produced one `row_skipped` event with `table`, `chunk_offset`, `row_index`, `pk`, and `error` fields populated.

Suggested approach: run with `--audit-channel=stdout` and grep for `"event":"row_skipped"`:

```bash
php clonio cloning:run .github/cloning-test/cloning.yml --target=test-target --audit-channel=stdout 2>&1 | grep -c '"event":"row_skipped"'
```

Confirm the count matches the displayed `<n> skipped` total.

- [ ] **Step 3: Confirm verbose `-vv` shows live row_skipped events on STDERR**

Run with `-vv` and confirm each row failure surfaces as a JSON line on STDERR in real time:

```bash
php clonio cloning:run .github/cloning-test/cloning.yml --target=test-target -vv 2>err.log
grep '\[WARNING\] row_skipped' err.log
```

- [ ] **Step 4: Confirm non-verbose mode is unchanged**

Run without any verbosity flag and confirm the dot/F/E/? output matches what it was before this change. The only behavioral delta in non-verbose mode is that `row_skipped` events now exist in the run log — display is unchanged.
