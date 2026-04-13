# Schema Replication Robustness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make schema replication robust against FK-regex bugs, per-table failures, and AUTO_INCREMENT drift; add `--break-on-failure` flag to `cloning:run`.

**Architecture:** Fix the buggy FK-stripping regex in `SchemaReplicator::sanitiseNativeDdl()`, add a per-table native-DDL → inspector-based fallback, surface failures as `array<string,string>` back to the orchestrator, add `AUTO_INCREMENT` correction after data transfer, add `TableRunStatus::SkippedBySchemaFailure`, and wire `--break-on-failure` through `RunCommand` → `CloningRunOrchestrator`.

**Tech Stack:** PHP 8.3, Laravel Zero, PestPHP v4, Mockery

---

### Task 1: Fix `sanitiseNativeDdl()` regex and tighten the FK test

**Files:**
- Modify: `app/Services/Cloning/SchemaReplicator.php`
- Modify: `tests/Unit/Services/Cloning/SchemaReplicatorTest.php`

- [ ] **Step 1: Update the FK test to also assert no `REFERENCES` in output**

In `tests/Unit/Services/Cloning/SchemaReplicatorTest.php`, find the test `it strips FOREIGN KEY constraints from native DDL` and change the final assertion from:

```php
expect($capturedSql)
    ->not->toContain('FOREIGN KEY')
    ->not->toContain('CONSTRAINT')
    ->toContain('IF NOT EXISTS');
```

to:

```php
expect($capturedSql)
    ->not->toContain('FOREIGN KEY')
    ->not->toContain('CONSTRAINT')
    ->not->toContain('REFERENCES')
    ->toContain('IF NOT EXISTS');
```

- [ ] **Step 2: Run the test to confirm it now fails**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/SchemaReplicatorTest.php --filter="strips FOREIGN KEY"
```

Expected: **FAIL** — `capturedSql` contains `REFERENCES`

- [ ] **Step 3: Fix the regex in `SchemaReplicator::sanitiseNativeDdl()`**

In `app/Services/Cloning/SchemaReplicator.php`, find `sanitiseNativeDdl()` (around line 332) and replace the CONSTRAINT regex line:

```php
// Remove CONSTRAINT ... FOREIGN KEY lines
$ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY[^,)]+(?:REFERENCES[^,)]+)?/i', '', $ddl) ?? $ddl;
```

with:

```php
// Remove CONSTRAINT ... FOREIGN KEY lines (entire line, including REFERENCES clause)
$ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY.*$/im', '', $ddl) ?? $ddl;
```

The `m` flag makes `$` match end-of-line; `.` without `s` flag doesn't match newlines — the entire FK line is removed.

- [ ] **Step 4: Run all SchemaReplicator tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/SchemaReplicatorTest.php
```

Expected: all **PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cloning/SchemaReplicator.php tests/Unit/Services/Cloning/SchemaReplicatorTest.php
git commit -m "fix: strip entire FOREIGN KEY line including REFERENCES clause in native DDL sanitiser"
```

---

### Task 2: Change `replicate()` return type and add per-table fallback

**Files:**
- Modify: `app/Services/Cloning/SchemaReplicator.php`
- Modify: `tests/Unit/Services/Cloning/SchemaReplicatorTest.php`
- Modify: `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` (mock return value)

- [ ] **Step 1: Write failing tests for the fallback and failure-map behaviour**

Add to `tests/Unit/Services/Cloning/SchemaReplicatorTest.php`:

```php
it('falls back to buildCreateTableSql when native DDL execution fails on target', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');
    $sourceSchema = makeSimpleSourceSchema(); // has 'users' table

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);
    $showRow = new stdClass;
    $showRow->{'Create Table'} = "CREATE TABLE `users` (`id` int NOT NULL) ENGINE=InnoDB";

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    $fallbackCalled = false;

    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([$showRow]);
    DB::shouldReceive('purge')->with('source_conn')->andReturnNull();

    // First statement call (native DDL) throws; second (fallback) succeeds
    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')
        ->once()->andThrow(new RuntimeException('syntax error'));
    DB::shouldReceive('statement')
        ->once()->withArgs(static function (string $sql) use (&$fallbackCalled): bool {
            if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS') && str_contains($sql, 'INT')) {
                $fallbackCalled = true;
            }
            return true;
        })->andReturnTrue();
    DB::shouldReceive('purge')->with('target_conn')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $failures = $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['users'], false, false);

    expect($fallbackCalled)->toBeTrue();
    expect($failures)->toBeEmpty();
});

it('returns table name in failure map when both native DDL and fallback fail', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');
    $sourceSchema = makeSimpleSourceSchema();

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);
    $showRow = new stdClass;
    $showRow->{'Create Table'} = "CREATE TABLE `users` (`id` int NOT NULL) ENGINE=InnoDB";

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([$showRow]);
    DB::shouldReceive('purge')->andReturnNull();

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    // Both native and fallback throw
    DB::shouldReceive('statement')->andThrow(new RuntimeException('table engine not supported'));

    $replicator = new SchemaReplicator($inspector, $connector);
    $failures = $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['users'], false, false);

    expect($failures)->toHaveKey('users');
    expect($failures['users'])->toContain('table engine not supported');
});
```

- [ ] **Step 2: Run these two new tests to confirm they fail**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/SchemaReplicatorTest.php --filter="falls back|returns table name in failure"
```

Expected: **FAIL** (return type mismatch or wrong behaviour)

- [ ] **Step 3: Rewrite `replicate()` with per-table fallback and `array<string,string>` return**

In `app/Services/Cloning/SchemaReplicator.php`, change the method signature from:

```php
public function replicate(
    ConnectionData $source,
    ConnectionData $target,
    DatabaseSchemaData $sourceSchema,
    array $tables,
    bool $enforceColumnTypes,
    bool $dropUnknownTables,
    bool $dropExtraColumns = false,
): void {
```

to:

```php
/**
 * Replicate source schema tables to target.
 *
 * @param  list<string>  $tables
 * @return array<string, string>  Map of tableName => errorMessage for tables that could not be created
 */
public function replicate(
    ConnectionData $source,
    ConnectionData $target,
    DatabaseSchemaData $sourceSchema,
    array $tables,
    bool $enforceColumnTypes,
    bool $dropUnknownTables,
    bool $dropExtraColumns = false,
): array {
```

Replace the inner `foreach ($tables as $tableName)` block (the part that handles missing target tables) with:

```php
/** @var array<string, string> $failedTables */
$failedTables = [];

foreach ($tables as $tableName) {
    $sourceTable = $sourceSchema->getTable($tableName);

    if (! $sourceTable instanceof TableSchemaData) {
        continue;
    }

    $targetTable = $targetSchema->getTable($tableName);

    if (! $targetTable instanceof TableSchemaData) {
        $created = false;

        if ($sameDbType) {
            $nativeSql = $this->fetchNativeCreateTableDdl($source, $tableName);

            if ($nativeSql !== null) {
                try {
                    DB::connection($targetConnName)->statement($nativeSql);
                    $created = true;
                } catch (Throwable) {
                    // native DDL failed — fall through to inspector-based fallback
                }
            }
        }

        if (! $created) {
            try {
                $fallbackSql = $this->buildCreateTableSql($tableName, $sourceTable->columns, $target->type);
                DB::connection($targetConnName)->statement($fallbackSql);
            } catch (Throwable $e) {
                $failedTables[$tableName] = $e->getMessage();
            }
        }
    } else {
        $sourceColNames = array_map(static fn (ColumnSchemaData $c): string => $c->name, $sourceTable->columns);
        $targetColNames = array_map(static fn (ColumnSchemaData $c): string => $c->name, $targetTable->columns);

        if ($enforceColumnTypes) {
            foreach ($sourceTable->columns as $col) {
                if (! in_array($col->name, $targetColNames, true)) {
                    $alterSql = $this->buildAddColumnSql($tableName, $col, $target->type);
                    DB::connection($targetConnName)->statement($alterSql);
                }
            }
        }

        if ($dropExtraColumns) {
            foreach ($targetTable->columns as $col) {
                if (! in_array($col->name, $sourceColNames, true)) {
                    $dropColSql = $this->buildDropColumnSql($tableName, $col->name, $target->type);
                    DB::connection($targetConnName)->statement($dropColSql);
                }
            }
        }
    }
}

if ($dropUnknownTables) {
    $sourceTableNames = array_map(static fn (TableSchemaData $t): string => $t->name, $sourceSchema->tables);

    foreach ($targetSchema->tables as $targetTable) {
        if (! in_array($targetTable->name, $sourceTableNames, true)) {
            $dropSql = $this->buildDropTableSql($targetTable->name, $target->type);
            DB::connection($targetConnName)->statement($dropSql);
        }
    }
}

return $failedTables;
```

Remove the old `unset($nativeSql)` line and old variable assignments.

- [ ] **Step 4: Fix the `makeOrchestrator()` mock in the orchestrator test**

In `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`, find:

```php
$replicator->shouldReceive('replicate')->andReturnNull();
```

and change to:

```php
$replicator->shouldReceive('replicate')->andReturn([]);
```

- [ ] **Step 5: Run all SchemaReplicator and orchestrator tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/SchemaReplicatorTest.php tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
```

Expected: all **PASS**

- [ ] **Step 6: Commit**

```bash
git add app/Services/Cloning/SchemaReplicator.php tests/Unit/Services/Cloning/SchemaReplicatorTest.php tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
git commit -m "feat: per-table schema fallback in replicate() with array<string,string> return"
```

---

### Task 3: Add `TableRunStatus::SkippedBySchemaFailure`

**Files:**
- Modify: `app/Data/Cloning/TableRunStatus.php`

- [ ] **Step 1: Add the new case**

In `app/Data/Cloning/TableRunStatus.php`, add after `NotFound`:

```php
enum TableRunStatus: string
{
    case Transferred = 'transferred';
    case SkippedByFlag = 'skipped_by_flag';
    case SkippedByCascade = 'skipped_by_cascade';
    case NotFound = 'not_found';
    case SkippedBySchemaFailure = 'skipped_by_schema_failure';
    case Failed = 'failed';
}
```

- [ ] **Step 2: Run the full suite to confirm no regressions**

```bash
./vendor/bin/pest tests/Unit/ tests/Feature/
```

Expected: all **PASS**

- [ ] **Step 3: Commit**

```bash
git add app/Data/Cloning/TableRunStatus.php
git commit -m "feat: add SkippedBySchemaFailure to TableRunStatus"
```

---

### Task 4: Add `correctAutoIncrement()` to `SchemaReplicator` and test it

**Files:**
- Modify: `app/Services/Cloning/SchemaReplicator.php`
- Modify: `tests/Unit/Services/Cloning/SchemaReplicatorTest.php`

- [ ] **Step 1: Write failing tests for `correctAutoIncrement()`**

Add to `tests/Unit/Services/Cloning/SchemaReplicatorTest.php`:

```php
it('sets AUTO_INCREMENT to max pk plus one', function (): void {
    $targetConn = makeReplicatorMysqlConnection('target');

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    $inspector = Mockery::mock(SchemaInspector::class);

    $maxRow = new stdClass;
    $maxRow->next_val = 51;

    $alterCalled = false;

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('select')->with(Mockery::pattern('/COALESCE.*MAX/i'))->andReturn([$maxRow]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$alterCalled): bool {
        if (str_contains($sql, 'AUTO_INCREMENT = 51')) {
            $alterCalled = true;
        }
        return true;
    })->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->correctAutoIncrement($targetConn, 'users', 'id');

    expect($alterCalled)->toBeTrue();
});

it('sets AUTO_INCREMENT to 1 when table is empty', function (): void {
    $targetConn = makeReplicatorMysqlConnection('target');

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    $inspector = Mockery::mock(SchemaInspector::class);

    $maxRow = new stdClass;
    $maxRow->next_val = 1; // COALESCE(MAX(id),0)+1 = 1 when table empty

    $capturedSql = null;

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([$maxRow]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$capturedSql): bool {
        $capturedSql = $sql;
        return true;
    })->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->correctAutoIncrement($targetConn, 'users', 'id');

    expect($capturedSql)->toContain('AUTO_INCREMENT = 1');
});

it('throws when AUTO_INCREMENT correction fails', function (): void {
    $targetConn = makeReplicatorMysqlConnection('target');

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    $inspector = Mockery::mock(SchemaInspector::class);

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('select')->andThrow(new RuntimeException('query failed'));
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);

    expect(fn () => $replicator->correctAutoIncrement($targetConn, 'users', 'id'))
        ->toThrow(RuntimeException::class, 'query failed');
});

it('returns early without connecting for non-mysql target', function (): void {
    $targetConn = new ConnectionData(
        name: 'target',
        type: DatabaseConnectionType::PostgreSQL,
        host: 'localhost',
        port: 5432,
        database: 'testdb',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: false,
    );

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->never();

    $inspector = Mockery::mock(SchemaInspector::class);

    DB::shouldReceive('connection')->never();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->correctAutoIncrement($targetConn, 'users', 'id'); // should not throw or connect
});
```

- [ ] **Step 2: Run new tests to confirm they fail**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/SchemaReplicatorTest.php --filter="AUTO_INCREMENT|sets AUTO|throws when AUTO|returns early without"
```

Expected: **FAIL** (method does not exist)

- [ ] **Step 3: Implement `correctAutoIncrement()` in `SchemaReplicator`**

Add after `fetchNativeCreateTableDdl()` in `app/Services/Cloning/SchemaReplicator.php`:

```php
/**
 * Set AUTO_INCREMENT on a MySQL/MariaDB table to MAX(pkColumn)+1.
 * Only connects when target is MySQL or MariaDB; no-op otherwise.
 *
 * @throws Throwable if the query or ALTER fails
 */
public function correctAutoIncrement(ConnectionData $target, string $tableName, string $pkColumn): void
{
    if (! in_array($target->type, [DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB], true)) {
        return;
    }

    $connName = $this->connector->open($target);

    try {
        $quotedTable = '`'.$tableName.'`';
        $quotedCol = '`'.$pkColumn.'`';

        /** @var list<object> $rows */
        $rows = DB::connection($connName)->select(
            'SELECT COALESCE(MAX('.$quotedCol.'), 0) + 1 AS next_val FROM '.$quotedTable
        );

        $row = (array) ($rows[0] ?? new \stdClass);
        $nextVal = max(1, (int) ($row['next_val'] ?? 1));

        DB::connection($connName)->statement(
            'ALTER TABLE '.$quotedTable.' AUTO_INCREMENT = '.$nextVal
        );
    } finally {
        DB::purge($connName);
    }
}
```

- [ ] **Step 4: Run all SchemaReplicator tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/SchemaReplicatorTest.php
```

Expected: all **PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cloning/SchemaReplicator.php tests/Unit/Services/Cloning/SchemaReplicatorTest.php
git commit -m "feat: add correctAutoIncrement() to SchemaReplicator"
```

---

### Task 5: Update `CloningRunOrchestrator` — schema failures, break-on-failure, AUTO_INCREMENT

**Files:**
- Modify: `app/Services/Cloning/CloningRunOrchestrator.php`
- Modify: `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`

- [ ] **Step 1: Write failing tests for the new orchestrator behaviour**

Add to `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`:

```php
function makeOrchestratorWithSchemaFailures(array $schemaFailures): CloningRunOrchestrator
{
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn');

    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn($schemaFailures);
    $replicator->shouldReceive('correctAutoIncrement')->andReturnNull();

    $resolver = Mockery::mock(DependencyResolver::class);
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn([]);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);

    $runLog = new RunLogWriter;

    return new CloningRunOrchestrator($connector, $replicator, $resolver, $runLog);
}

it('marks table as skipped_by_schema_failure when schema could not be created', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestratorWithSchemaFailures(['users' => 'syntax error']);
    $result = $orchestrator->run($config, $source, $target, $schema, false, [], [], static fn (): null => null);

    expect($result->tables)->toHaveCount(1);
    expect($result->tables[0]->status->value)->toBe('skipped_by_schema_failure');
    expect($result->success)->toBeFalse();
});

it('continues with other tables after a schema failure', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');

    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: 'orders',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'users',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
        ],
    );

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'orders',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    // orders fails schema; users succeeds
    $orchestrator = makeOrchestratorWithSchemaFailures(['orders' => 'syntax error']);
    $result = $orchestrator->run($config, $source, $target, $schema, false, [], [], static fn (): null => null);

    $statusByTable = [];
    foreach ($result->tables as $tableResult) {
        $statusByTable[$tableResult->tableName] = $tableResult->status->value;
    }

    expect($statusByTable['orders'])->toBe('skipped_by_schema_failure');
    expect($statusByTable['users'])->toBe('transferred');
    expect($result->success)->toBeFalse();
});

it('aborts after first failure when break_on_failure is true', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');

    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: 'orders',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'users',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
        ],
    );

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'orders',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestratorWithSchemaFailures(['orders' => 'syntax error']);
    $result = $orchestrator->run($config, $source, $target, $schema, false, [], [], static fn (): null => null, breakOnFailure: true);

    // only orders result present — users was never processed
    $tableNames = array_map(static fn (TableRunResultData $t): string => $t->tableName, $result->tables);
    expect($tableNames)->toContain('orders');
    expect($tableNames)->not->toContain('users');
    expect($result->success)->toBeFalse();
});
```

- [ ] **Step 2: Run new tests to confirm they fail**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php --filter="skipped_by_schema|continues with other|aborts after first"
```

Expected: **FAIL**

- [ ] **Step 3: Update `CloningRunOrchestrator::run()` signature**

In `app/Services/Cloning/CloningRunOrchestrator.php`, add the `$breakOnFailure` parameter to `run()`:

```php
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
): RunResultData {
```

- [ ] **Step 4: Update the schema replication block to handle failures**

Find the schema replication block in `run()` (around line 71):

```php
if (! $skipSchema) {
    try {
        $this->replicator->replicate(...);
        $this->runLog->log('info', 'schema_replicated', ['tables' => $sortedTables]);
    } catch (Throwable $e) {
        $this->runLog->log('error', 'schema_replication_failed', ['error' => $e->getMessage()]);
    }
}
```

Replace it with:

```php
/** @var array<string, string> $schemaFailures */
$schemaFailures = [];

if (! $skipSchema) {
    $schemaFailures = $this->replicator->replicate(
        $source,
        $target,
        $sourceSchema,
        $sortedTables,
        $config->options->enforceColumnTypes,
        $config->options->dropUnknownTables,
        $config->options->dropExtraColumns,
    );

    if ($schemaFailures === []) {
        $this->runLog->log('info', 'schema_replicated', ['tables' => $sortedTables]);
    } else {
        foreach ($schemaFailures as $failedTable => $errorMsg) {
            $this->runLog->log('error', 'schema_table_failed', ['table' => $failedTable, 'error' => $errorMsg]);
        }
    }
}
```

- [ ] **Step 5: Add `SkippedBySchemaFailure` handling before each table transfer**

Find the `foreach ($sortedTables as $tableName)` loop. Before the call to `$this->transferTable(...)`, add:

```php
// Skip data transfer for tables whose schema could not be created
if (array_key_exists($tableName, $schemaFailures)) {
    $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedBySchemaFailure, 0, 0, 0.0, $schemaFailures[$tableName]);
    $this->runLog->log('warning', 'table_skipped_schema_failure', ['table' => $tableName]);
    $success = false;
    ($onProgress)($tableName, TableRunStatus::SkippedBySchemaFailure, 0, 0);

    if ($breakOnFailure) {
        break;
    }

    continue;
}
```

Also add `$breakOnFailure` break after data transfer failures. Find:

```php
if ($failed) {
    $success = false;
    $this->runLog->log('error', 'table_transfer_failed', ['table' => $tableName, 'reason' => $reason]);
} else {
```

and change to:

```php
if ($failed) {
    $success = false;
    $this->runLog->log('error', 'table_transfer_failed', ['table' => $tableName, 'reason' => $reason]);
} else {
```

After `($onProgress)($tableName, $status, $rows, $skipped);` add:

```php
if ($failed && $breakOnFailure) {
    break;
}
```

- [ ] **Step 6: Add AUTO_INCREMENT correction after successful transfer**

After the `($onProgress)` call (and the `$breakOnFailure` break check), add the AUTO_INCREMENT correction. You'll need a helper private method. Add after the `run()` method closes, before other private methods:

```php
/**
 * Return the single integer PK column name for a table, or null if not applicable.
 * Only applies to MySQL/MariaDB targets with a single-column integer PK.
 */
private function findIntegerPkColumn(ConnectionData $target, TableSchemaData $table): ?string
{
    if (! in_array($target->type, [DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB], true)) {
        return null;
    }

    $pkColumns = array_values(array_filter($table->columns, static fn (ColumnSchemaData $c): bool => $c->isPrimary));

    if (count($pkColumns) !== 1) {
        return null;
    }

    $intTypes = ['int', 'bigint', 'mediumint', 'smallint', 'tinyint', 'integer'];

    if (! in_array(strtolower($pkColumns[0]->type), $intTypes, true)) {
        return null;
    }

    return $pkColumns[0]->name;
}
```

Add the required `use` import at the top (add `ColumnSchemaData` to the import list in `CloningRunOrchestrator`):

```php
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\TableSchemaData;
```

Then in the transfer loop, replace:

```php
if ($failed && $breakOnFailure) {
    break;
}
```

with:

```php
if ($failed && $breakOnFailure) {
    break;
}

if (! $failed) {
    $sourceTable = $sourceSchema->getTable($tableName);

    if ($sourceTable instanceof TableSchemaData) {
        $pkColumn = $this->findIntegerPkColumn($target, $sourceTable);

        if ($pkColumn !== null) {
            try {
                $this->replicator->correctAutoIncrement($target, $tableName, $pkColumn);
            } catch (Throwable $e) {
                $this->runLog->log('warning', 'auto_increment_correction_failed', ['table' => $tableName, 'error' => $e->getMessage()]);
            }
        }
    }
}
```

- [ ] **Step 7: Run all orchestrator tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
```

Expected: all **PASS**

- [ ] **Step 8: Commit**

```bash
git add app/Services/Cloning/CloningRunOrchestrator.php tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
git commit -m "feat: handle schema failures, break-on-failure, and AUTO_INCREMENT correction in orchestrator"
```

---

### Task 6: Update `RunCommand` — `--break-on-failure` flag, progress and summary

**Files:**
- Modify: `app/Commands/Cloning/RunCommand.php`
- Modify: `tests/Feature/Commands/Cloning/RunCommandTest.php`

- [ ] **Step 1: Write a failing feature test for `--break-on-failure`**

Add to `tests/Feature/Commands/Cloning/RunCommandTest.php`. First, find any existing test that shows the full mock setup pattern (the `it returns success with dot progress` style test), and add:

```php
it('passes break_on_failure to orchestrator when --break-on-failure is set', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
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
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $capturedBreakOnFailure = null;

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')
        ->withArgs(static function ($cfg, $src, $tgt, $schema, $skip, $skipTbls, $only, $cb, $km, bool $bof) use (&$capturedBreakOnFailure): bool {
            $capturedBreakOnFailure = $bof;
            return true;
        })
        ->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
        '--break-on-failure' => true,
    ])->assertExitCode(ExitCode::Success->value);

    expect($capturedBreakOnFailure)->toBeTrue();
});
```

- [ ] **Step 2: Run the new test to confirm it fails**

```bash
./vendor/bin/pest tests/Feature/Commands/Cloning/RunCommandTest.php --filter="passes break_on_failure"
```

Expected: **FAIL** (unknown option)

- [ ] **Step 3: Add `--break-on-failure` to `RunCommand` signature**

In `app/Commands/Cloning/RunCommand.php`, add to the `$signature` string after `--no-disable-fk-checks`:

```php
{--break-on-failure         : Abort the run immediately on the first table failure (schema or data)}
```

- [ ] **Step 4: Pass `breakOnFailure` to `$orchestrator->run()`**

Find the `$orchestrator->run(...)` call in `RunCommand::handle()` and add the named argument:

```php
$result = $orchestrator->run(
    config: $config,
    source: $sourceConnection,
    target: $targetConnection,
    sourceSchema: $sourceSchema,
    skipSchema: $skipSchema,
    skipTables: $skipTables,
    onlyTables: $onlyTables,
    onProgress: function (...) { ... },
    keyRemapping: $keyRemappingService,
    breakOnFailure: (bool) $this->option('break-on-failure'),
);
```

- [ ] **Step 5: Handle `SkippedBySchemaFailure` in `onProgress`**

Inside the `onProgress` closure, add handling for the new status. The closure currently looks like:

```php
onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped) use ($verbose, &$notFoundTables): void {
    if ($verbose) {
        if ($status === TableRunStatus::Transferred) {
            $this->line(sprintf('  <info>✓</info>  %s  (%d rows)', $tableName, $rows));
        } elseif ($status === TableRunStatus::NotFound) {
            $this->line(sprintf('  <comment>✗</comment>  %s  — not found in source, skipped', $tableName));
            $notFoundTables[] = $tableName;
        } elseif ($status === TableRunStatus::Failed) {
            $this->line(sprintf('  <error>✗</error>  %s  — failed', $tableName));
        }
    } elseif ($status === TableRunStatus::Transferred) {
        $indicator = $skipped > 0 ? 'F' : '.';
        $this->output->write($indicator);
    } elseif ($status === TableRunStatus::Failed) {
        $this->output->write('E');
    } elseif ($status === TableRunStatus::NotFound) {
        $this->output->write('?');
        $notFoundTables[] = $tableName;
    }
},
```

Change to (add `SkippedBySchemaFailure` handling, and add `&$schemaFailureTables` to the `use` clause):

```php
/** @var list<string> $schemaFailureTables */
$schemaFailureTables = [];

// ... (existing $notFoundTables declaration stays)

onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped) use ($verbose, &$notFoundTables, &$schemaFailureTables): void {
    if ($verbose) {
        if ($status === TableRunStatus::Transferred) {
            $this->line(sprintf('  <info>✓</info>  %s  (%d rows)', $tableName, $rows));
        } elseif ($status === TableRunStatus::NotFound) {
            $this->line(sprintf('  <comment>✗</comment>  %s  — not found in source, skipped', $tableName));
            $notFoundTables[] = $tableName;
        } elseif ($status === TableRunStatus::Failed) {
            $this->line(sprintf('  <error>✗</error>  %s  — failed', $tableName));
        } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
            $this->line(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
            $schemaFailureTables[] = $tableName;
        }
    } elseif ($status === TableRunStatus::Transferred) {
        $indicator = $skipped > 0 ? 'F' : '.';
        $this->output->write($indicator);
    } elseif ($status === TableRunStatus::Failed) {
        $this->output->write('E');
    } elseif ($status === TableRunStatus::NotFound) {
        $this->output->write('?');
        $notFoundTables[] = $tableName;
    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
        $this->output->write('S');
        $schemaFailureTables[] = $tableName;
    }
},
```

- [ ] **Step 6: Add schema-failure summary in Phase 8**

After the `$notFoundTables` warning block in the summary section, add:

```php
// Derive schema failures from result (merging with any collected during progress)
$schemaFailuresFromResult = array_values(array_map(
    static fn (TableRunResultData $t): string => $t->tableName,
    array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::SkippedBySchemaFailure)
));
$schemaFailureTables = array_values(array_unique(array_merge($schemaFailureTables, $schemaFailuresFromResult)));

if ($schemaFailureTables !== []) {
    $this->line('');
    $this->line(sprintf(
        '  <error>Error: %d table%s skipped due to schema replication failure (%s)</error>',
        count($schemaFailureTables),
        count($schemaFailureTables) === 1 ? '' : 's',
        implode(', ', $schemaFailureTables),
    ));
}
```

- [ ] **Step 7: Run the feature test**

```bash
./vendor/bin/pest tests/Feature/Commands/Cloning/RunCommandTest.php --filter="passes break_on_failure"
```

Expected: **PASS**

- [ ] **Step 8: Run the full test suite**

```bash
composer test
```

Expected: all **PASS** (type coverage ≥ 90%, unit coverage ≥ 75%, PHPStan level max, Pint clean)

- [ ] **Step 9: Commit**

```bash
git add app/Commands/Cloning/RunCommand.php tests/Feature/Commands/Cloning/RunCommandTest.php
git commit -m "feat: add --break-on-failure flag to cloning:run and handle SkippedBySchemaFailure in output"
```

---

### Task 7: Update `docs/commands/cloning-run.md`

**Files:**
- Modify: `docs/commands/cloning-run.md`

- [ ] **Step 1: Add `--break-on-failure` to the Options table**

Find the Options table in `docs/commands/cloning-run.md` and add a row after `--no-disable-fk-checks`:

```markdown
| `--break-on-failure` | Abort the run immediately on the first table failure (schema or data); without this flag, the run continues through all tables and returns `success: false` at the end |
```

- [ ] **Step 2: Add a section for `--break-on-failure` behaviour**

Add after the Schema Synchronization section (or after the CLI flag reference table), a new subsection:

```markdown
### Error handling and `--break-on-failure`

By default, `cloning:run` transfers as much data as possible even when individual tables fail. If a table's schema cannot be created (e.g. due to unsupported syntax on the target), that table is skipped with status `skipped_by_schema_failure` and data transfer continues for the remaining tables. The run completes with `success: false` and a non-zero exit code.

Use `--break-on-failure` to abort the run immediately on the first failure:

```bash
clonio cloning:run production-db.cloning.yaml --target staging --break-on-failure
```

The audit log is written in both modes — including when the run is aborted early.

| Scenario | Default behaviour | With `--break-on-failure` |
|---|---|---|
| Schema creation fails for table A | Skip A, continue with remaining tables | Abort run |
| Data transfer fails for table B | Continue with remaining tables | Abort run |
| All tables OK | `success: true` | `success: true` |
| Partial failure | `success: false`, full result set | `success: false`, partial result set |
```

- [ ] **Step 3: Add `skipped_by_schema_failure` to the progress indicator table**

Find the progress symbol table (`.`, `F`, `E`, `?`) and add:

```markdown
| `S` | Table skipped — schema could not be replicated to target |
```

- [ ] **Step 4: Commit**

```bash
git add docs/commands/cloning-run.md
git commit -m "docs: document --break-on-failure flag and skipped_by_schema_failure status"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| Fix FK-stripping regex | Task 1 |
| Per-table native DDL → inspector fallback | Task 2 |
| `replicate()` returns `array<string,string>` | Task 2 |
| `TableRunStatus::SkippedBySchemaFailure` | Task 3 |
| `correctAutoIncrement()` method | Task 4 |
| Orchestrator handles schema failures | Task 5 |
| `breakOnFailure` parameter in orchestrator | Task 5 |
| `correctAutoIncrement()` called after transfer | Task 5 |
| `--break-on-failure` flag in RunCommand | Task 6 |
| `SkippedBySchemaFailure` in progress output | Task 6 |
| Schema failure summary in output | Task 6 |
| `success: false` on any `SkippedBySchemaFailure` | Task 5 |
| Audit log written even on failure | Existing behaviour, no change needed |
| `docs/commands/cloning-run.md` updated | Task 7 |

**Type consistency check:**
- `replicate()` returns `array<string, string>` — used in Task 2 (definition) and Task 5 (consumption) ✓
- `correctAutoIncrement(ConnectionData $target, string $tableName, string $pkColumn): void` — defined in Task 4, called in Task 5 ✓
- `run(..., bool $breakOnFailure = false)` — defined in Task 5, called in Task 6 ✓
- `TableRunStatus::SkippedBySchemaFailure` — defined in Task 3, used in Tasks 5 and 6 ✓
- `findIntegerPkColumn(ConnectionData $target, TableSchemaData $table): ?string` — private method in orchestrator, only used internally in Task 5 ✓
