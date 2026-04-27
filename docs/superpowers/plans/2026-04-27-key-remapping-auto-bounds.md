# Key Remapping Auto-Bounds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace user-configured key-remapping ranges with type-aware automatic ID allocation (`MAX(id)+1` ascending, gap-fill fallback) so PK collisions on narrow column types (e.g. `SMALLINT UNSIGNED`) become impossible.

**Architecture:** A new `KeyTypeBoundsResolver` derives the integer ceiling from `ColumnSchemaData.type` + a new `unsigned` flag populated by `SchemaInspector`. `KeyRemappingService::generateTableMapping` reads `MAX(id)` from the source PK, allocates `[MAX(id)+1, typeMax]` ascending, falls back to filling gaps in `[1, MAX(id)]`, and throws `KeyRemappingExhaustedException` if neither covers the row count. All `min`/`max`/`range_min`/`range_max` knobs are removed from DTOs, YAML loader, validator, writer, and `cloning:dump`. Pre-1.0.0 — no backward compat; legacy range keys produce hard validation errors.

**Tech Stack:** PHP 8.5, Laravel Zero 12, Pest 4, PHPStan max, Larastan, Pint, Rector.

---

## File Structure

**New files:**
- `app/Services/Cloning/KeyTypeBoundsResolver.php` — pure helper: `ColumnSchemaData → int ceiling`
- `app/Exceptions/KeyRemappingExhaustedException.php` — runtime, raised when no slots remain
- `app/Exceptions/UnsupportedKeyColumnTypeException.php` — runtime, raised when column type is not an integer
- `tests/Unit/Services/Cloning/KeyTypeBoundsResolverTest.php`

**Modified files:**
- `app/Data/Schema/ColumnSchemaData.php` — add `bool $unsigned`
- `app/Services/Schema/SchemaInspector.php` — read `COLUMN_TYPE` for MySQL/MariaDB, set `unsigned`
- `app/Data/Cloning/KeyRemappingTableData.php` — drop `rangeMin`, `rangeMax`
- `app/Data/Cloning/ColumnCloningConfigData.php` — drop `remappingMin`, `remappingMax`
- `app/Services/Cloning/CloningYamlLoader.php` — drop range parsing (legacy + inline)
- `app/Services/Cloning/CloningYamlValidator.php` — hard-error on `min`/`max`/`range_min`/`range_max`
- `app/Services/Cloning/CloningYamlWriter.php` — stop emitting `- min:` / `- max:`
- `app/Commands/Cloning/DumpCommand.php` — drop `rangeMin`/`rangeMax` ctor args
- `app/Services/Cloning/KeyRemappingService.php` — accept `DatabaseSchemaData`, new strategy
- `app/Commands/Cloning/RunCommand.php` — pass `$sourceSchema` into `generateMappings()`
- `specs/PRD-cloning-key-remapping.md` — drop range refs, add auto-bounds section
- `tests/Unit/Data/Cloning/KeyRemappingDtoTest.php` — drop range assertions
- `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php` — drop range tests
- `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php` — replace range tests with rejection tests
- `tests/Unit/Services/Cloning/CloningYamlWriterTest.php` — drop range emission expectations
- `tests/Unit/Services/Cloning/KeyRemappingServiceTest.php` — new strategy tests

---

## Task 1: ColumnSchemaData.unsigned + SchemaInspector wiring

**Files:**
- Modify: `app/Data/Schema/ColumnSchemaData.php`
- Modify: `app/Services/Schema/SchemaInspector.php` (MySQL branch around lines 60-86)
- Test: existing inspector tests + a new MySQL-specific test (skipped if no MySQL fixture; covered indirectly by integration). For unit: add a `ColumnSchemaDataTest` field test.

- [ ] **Step 1: Add field to DTO**

```php
// app/Data/Schema/ColumnSchemaData.php
<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class ColumnSchemaData
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
        public bool $isPrimary,
        public bool $unsigned = false,
    ) {}
}
```

- [ ] **Step 2: Inspector reads COLUMN_TYPE for MySQL**

Modify the SQL on lines 60-63 of `app/Services/Schema/SchemaInspector.php` to also select `COLUMN_TYPE`, and propagate the `unsigned` flag at the `ColumnSchemaData` construction (around line 79):

```php
/** @var list<stdClass> $columnRows */
$columnRows = DB::connection($connName)->select(
    'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
    [$tableName]
);

$columns = [];

foreach ($columnRows as $col) {
    /** @var string $colName */
    $colName = $col->COLUMN_NAME;
    /** @var string $dataType */
    $dataType = $col->DATA_TYPE;
    /** @var string $columnType */
    $columnType = $col->COLUMN_TYPE;
    /** @var string $isNullable */
    $isNullable = $col->IS_NULLABLE;
    $rawDefault = $col->COLUMN_DEFAULT ?? null;
    $colDefault = is_scalar($rawDefault) ? (string) $rawDefault : null;
    /** @var string $colKey */
    $colKey = $col->COLUMN_KEY;

    $columns[] = new ColumnSchemaData(
        name: $colName,
        type: $dataType,
        nullable: $isNullable === 'YES',
        default: $colDefault,
        isPrimary: $colKey === 'PRI',
        unsigned: str_contains(strtolower($columnType), 'unsigned'),
    );
}
```

Postgres, SQLite, SQL Server branches: leave the constructor calls as-is — they will pick up the default `unsigned: false`.

- [ ] **Step 3: Run existing tests to confirm nothing broke**

Run: `./vendor/bin/pest tests/Unit/Services/Schema --no-coverage`
Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add app/Data/Schema/ColumnSchemaData.php app/Services/Schema/SchemaInspector.php
git commit -m "feat(schema): add unsigned flag to ColumnSchemaData (MySQL/MariaDB)"
```

---

## Task 2: KeyTypeBoundsResolver + UnsupportedKeyColumnTypeException

**Files:**
- Create: `app/Exceptions/UnsupportedKeyColumnTypeException.php`
- Create: `app/Services/Cloning/KeyTypeBoundsResolver.php`
- Test: `tests/Unit/Services/Cloning/KeyTypeBoundsResolverTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Services/Cloning/KeyTypeBoundsResolverTest.php
<?php

declare(strict_types=1);

use App\Data\Schema\ColumnSchemaData;
use App\Exceptions\UnsupportedKeyColumnTypeException;
use App\Services\Cloning\KeyTypeBoundsResolver;

function makeIntCol(string $type, bool $unsigned = false): ColumnSchemaData
{
    return new ColumnSchemaData(
        name: 'id',
        type: $type,
        nullable: false,
        default: null,
        isPrimary: true,
        unsigned: $unsigned,
    );
}

it('returns 127 for signed tinyint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('tinyint')))->toBe(127);
});

it('returns 255 for unsigned tinyint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('tinyint', true)))->toBe(255);
});

it('returns 32767 for signed smallint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('smallint')))->toBe(32767);
});

it('returns 65535 for unsigned smallint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('smallint', true)))->toBe(65535);
});

it('returns 8388607 for signed mediumint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('mediumint')))->toBe(8388607);
});

it('returns 16777215 for unsigned mediumint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('mediumint', true)))->toBe(16777215);
});

it('returns 2147483647 for signed int', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('int')))->toBe(2147483647);
});

it('returns 4294967295 for unsigned int', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('int', true)))->toBe(4294967295);
});

it('treats integer alias same as int', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('integer')))->toBe(2147483647);
});

it('returns PHP_INT_MAX for bigint regardless of signedness', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('bigint')))->toBe(PHP_INT_MAX)
        ->and((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('bigint', true)))->toBe(PHP_INT_MAX);
});

it('is case-insensitive on type name', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('INT', true)))->toBe(4294967295);
});

it('throws UnsupportedKeyColumnTypeException for non-integer types', function (): void {
    (new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('varchar'));
})->throws(UnsupportedKeyColumnTypeException::class);

it('throws UnsupportedKeyColumnTypeException for char (UUID columns must use new_uuid)', function (): void {
    (new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('char'));
})->throws(UnsupportedKeyColumnTypeException::class);
```

- [ ] **Step 2: Run test, confirm fail**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/KeyTypeBoundsResolverTest.php --no-coverage`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement exception**

```php
// app/Exceptions/UnsupportedKeyColumnTypeException.php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class UnsupportedKeyColumnTypeException extends RuntimeException {}
```

- [ ] **Step 4: Implement resolver**

```php
// app/Services/Cloning/KeyTypeBoundsResolver.php
<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Schema\ColumnSchemaData;
use App\Exceptions\UnsupportedKeyColumnTypeException;

final class KeyTypeBoundsResolver
{
    public function ceilingFor(ColumnSchemaData $column): int
    {
        $type = strtolower($column->type);

        return match ($type) {
            'tinyint' => $column->unsigned ? 255 : 127,
            'smallint' => $column->unsigned ? 65535 : 32767,
            'mediumint' => $column->unsigned ? 16777215 : 8388607,
            'int', 'integer' => $column->unsigned ? 4294967295 : 2147483647,
            'bigint' => PHP_INT_MAX,
            default => throw new UnsupportedKeyColumnTypeException(
                sprintf("Column '%s' has type '%s' which is not supported for integer key remapping", $column->name, $column->type)
            ),
        };
    }
}
```

- [ ] **Step 5: Run tests, confirm pass**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/KeyTypeBoundsResolverTest.php --no-coverage`
Expected: 12 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Cloning/KeyTypeBoundsResolver.php app/Exceptions/UnsupportedKeyColumnTypeException.php tests/Unit/Services/Cloning/KeyTypeBoundsResolverTest.php
git commit -m "feat(cloning): add KeyTypeBoundsResolver for type-aware integer ceilings"
```

---

## Task 3: KeyRemappingExhaustedException

**Files:**
- Create: `app/Exceptions/KeyRemappingExhaustedException.php`

- [ ] **Step 1: Implement exception**

```php
// app/Exceptions/KeyRemappingExhaustedException.php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class KeyRemappingExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $columnType,
        public readonly bool $unsigned,
        public readonly int $typeMax,
        public readonly int $rowCount,
        public readonly int $upperSlots,
        public readonly int $gapSlots,
    ) {
        parent::__construct(sprintf(
            "Cannot remap table '%s': column '%s' is %s (%s, ceiling %d); %d rows requested, only %d slots available (%d above MAX(id), %d gaps below).",
            $table,
            $column,
            strtoupper($columnType),
            $unsigned ? 'unsigned' : 'signed',
            $typeMax,
            $rowCount,
            $upperSlots + $gapSlots,
            $upperSlots,
            $gapSlots,
        ));
    }
}
```

- [ ] **Step 2: Commit (combined with Task 4 commit, no separate commit)**

(No standalone commit — exception is exercised by Task 4 tests.)

---

## Task 4: Rewrite KeyRemappingService for type-aware allocation

**Files:**
- Modify: `app/Services/Cloning/KeyRemappingService.php`
- Modify: `tests/Unit/Services/Cloning/KeyRemappingServiceTest.php`

We can't easily unit-test `generateTableMapping` end-to-end without real DB access (it issues `SELECT` queries via `DB::connection`). So we extract the pure allocation logic into a method that takes the source IDs as input, and write unit tests against that method. The DB-fetching wrapper stays thin and is exercised by feature tests later (out of scope for this plan).

- [ ] **Step 1: Write failing test for the pure allocator**

```php
// Add to tests/Unit/Services/Cloning/KeyRemappingServiceTest.php
use App\Data\Schema\ColumnSchemaData;
use App\Exceptions\KeyRemappingExhaustedException;

function makePkCol(string $type, bool $unsigned = false): ColumnSchemaData
{
    return new ColumnSchemaData(
        name: 'id',
        type: $type,
        nullable: false,
        default: null,
        isPrimary: true,
        unsigned: $unsigned,
    );
}

it('allocateIntegerIds returns ascending ids starting at MAX(id)+1', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = ['10', '20', '30', '40', '50'];
    $existingMax = 200;

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, $existingMax, []);

    expect(array_values($result))->toBe(['201', '202', '203', '204', '205'])
        ->and(array_keys($result))->toBe(['10', '20', '30', '40', '50']);
});

it('allocateIntegerIds falls back to gap-fill when upper region exhausted', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = ['1', '2', '3', '4', '5'];
    $existingMax = 253;
    $existingIds = [1, 2, 250, 251, 252, 253]; // gaps in [1,253] = [3..249] (lots of room)

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, $existingMax, $existingIds);

    // upper slots: 255 - 254 + 1 = 2 → IDs 254, 255
    // remaining 3: first 3 gaps = 3, 4, 5
    expect(array_values($result))->toBe(['254', '255', '3', '4', '5']);
});

it('allocateIntegerIds throws when type ceiling cannot host row count', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = array_map(static fn (int $i): string => (string) $i, range(1, 300));
    $existingMax = 0;
    $existingIds = [];

    $service->allocateIntegerIds('big_table', $col, $sourceIds, $existingMax, $existingIds);
})->throws(KeyRemappingExhaustedException::class);

it('allocateIntegerIds starts at 1 when source table is empty', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int');

    $result = $service->allocateIntegerIds('users', $col, ['7'], 0, []);

    expect($result)->toBe(['7' => '1']);
});

it('allocateIntegerIds for signed int never returns negative ids', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int'); // signed ceiling 2147483647

    $result = $service->allocateIntegerIds('users', $col, ['1', '2'], 0, []);

    expect(array_values($result))->toBe(['1', '2']);
});

it('allocateIntegerIds preserves source order when assigning new ids', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int', true);

    $sourceIds = ['100', '99', '500', '7'];

    $result = $service->allocateIntegerIds('t', $col, $sourceIds, 1000, []);

    expect($result)->toBe([
        '100' => '1001',
        '99' => '1002',
        '500' => '1003',
        '7' => '1004',
    ]);
});
```

Update the existing helper `makeKeyRemappingTable` so it no longer passes `rangeMin` / `rangeMax` (those args are removed in Task 5). For now, keep the helper as-is — Task 5 will fix it. After Task 5, the existing tests at lines 22-32, 86-94 must be updated to drop range args.

- [ ] **Step 2: Run test, confirm fail**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/KeyRemappingServiceTest.php --no-coverage`
Expected: FAIL — `allocateIntegerIds` does not exist on service.

- [ ] **Step 3: Add allocator + restructure service**

Replace `generateUniqueInteger` (lines 110-135) with the new `allocateIntegerIds` (public for testability) and rewrite `generateTableMapping` (lines 53-107) to call it after fetching source IDs and `MAX(id)`. The service signature for `generateMappings` gains a `DatabaseSchemaData` parameter.

```php
// app/Services/Cloning/KeyRemappingService.php
<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Enums\KeyRemappingStrategy;
use App\Exceptions\KeyRemappingExhaustedException;
use App\Services\Cloning\KeyTypeBoundsResolver;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class KeyRemappingService
{
    public function __construct(
        private readonly DatabaseConnectionService $connector,
        private readonly KeyRemappingStoreInterface $store = new InMemoryKeyRemappingStore,
        private readonly KeyTypeBoundsResolver $boundsResolver = new KeyTypeBoundsResolver,
    ) {}

    /**
     * @param  list<string>  $orderedTables
     * @return array<string, int>
     */
    public function generateMappings(
        KeyRemappingConfigData $config,
        ConnectionData $source,
        DatabaseSchemaData $sourceSchema,
        array $orderedTables,
    ): array {
        $counts = [];

        foreach ($orderedTables as $tableName) {
            $tableConfig = $config->getTable($tableName);
            if (! $tableConfig instanceof KeyRemappingTableData) {
                continue;
            }

            $counts[$tableName] = $this->generateTableMapping($tableConfig, $source, $sourceSchema);
        }

        return $counts;
    }

    /**
     * Pure allocator. Returns oldId(string) => newId(string), preserving source order.
     *
     * @param  list<string>  $sourceIds
     * @param  list<int>  $existingIds  Sorted or unsorted; will be deduped + sorted internally.
     * @return array<string, string>
     */
    public function allocateIntegerIds(
        string $table,
        ColumnSchemaData $column,
        array $sourceIds,
        int $existingMax,
        array $existingIds,
    ): array {
        $rowCount = count($sourceIds);
        if ($rowCount === 0) {
            return [];
        }

        $typeMax = $this->boundsResolver->ceilingFor($column);
        $upperStart = max($existingMax + 1, 1);
        $upperSlots = $upperStart > $typeMax ? 0 : ($typeMax - $upperStart + 1);

        $targets = [];

        if ($upperSlots >= $rowCount) {
            for ($i = 0; $i < $rowCount; $i++) {
                $targets[] = $upperStart + $i;
            }
        } else {
            for ($i = 0; $i < $upperSlots; $i++) {
                $targets[] = $upperStart + $i;
            }

            $needed = $rowCount - $upperSlots;
            $gaps = $this->findGaps($existingIds, 1, $existingMax);

            if (count($gaps) < $needed) {
                throw new KeyRemappingExhaustedException(
                    table: $table,
                    column: $column->name,
                    columnType: $column->type,
                    unsigned: $column->unsigned,
                    typeMax: $typeMax,
                    rowCount: $rowCount,
                    upperSlots: $upperSlots,
                    gapSlots: count($gaps),
                );
            }

            for ($i = 0; $i < $needed; $i++) {
                $targets[] = $gaps[$i];
            }
        }

        $mapping = [];
        foreach ($sourceIds as $i => $oldId) {
            $mapping[$oldId] = (string) $targets[$i];
        }

        return $mapping;
    }

    /**
     * @param  list<int>  $existingIds
     * @return list<int>
     */
    private function findGaps(array $existingIds, int $lo, int $hi): array
    {
        if ($hi < $lo) {
            return [];
        }

        $set = array_flip($existingIds);
        $gaps = [];
        for ($i = $lo; $i <= $hi; $i++) {
            if (! isset($set[$i])) {
                $gaps[] = $i;
            }
        }

        return $gaps;
    }

    private function generateTableMapping(
        KeyRemappingTableData $config,
        ConnectionData $source,
        DatabaseSchemaData $sourceSchema,
    ): int {
        $connName = $this->connector->open($source);
        $pkCol = $config->primaryKey;
        $table = $config->table;

        try {
            $driver = $source->type->value;
            $quotedTable = match ($driver) {
                'mysql', 'mariadb' => '`'.$table.'`',
                'sqlsrv' => '['.$table.']',
                default => '"'.$table.'"',
            };
            $quotedCol = match ($driver) {
                'mysql', 'mariadb' => '`'.$pkCol.'`',
                'sqlsrv' => '['.$pkCol.']',
                default => '"'.$pkCol.'"',
            };

            /** @var list<object> $rows */
            $rows = DB::connection($connName)->select(
                sprintf('SELECT %s FROM %s', $quotedCol, $quotedTable)
            );

            $sourceIds = [];
            $existingIdsInt = [];
            foreach ($rows as $row) {
                $rowArray = (array) $row;
                $rawValue = $rowArray[$pkCol] ?? null;
                $oldValue = is_scalar($rawValue) ? (string) $rawValue : '';
                $sourceIds[] = $oldValue;
                if (is_numeric($oldValue)) {
                    $existingIdsInt[] = (int) $oldValue;
                }
            }

            $existingMax = $existingIdsInt === [] ? 0 : max($existingIdsInt);

            $tableMappings = match ($config->strategy) {
                KeyRemappingStrategy::NewUuid => $this->allocateUuids($sourceIds),
                KeyRemappingStrategy::RandomInteger => $this->allocateIntegerIds(
                    $table,
                    $this->resolvePkColumn($sourceSchema, $table, $pkCol),
                    $sourceIds,
                    $existingMax,
                    $existingIdsInt,
                ),
            };

            $this->store->storeTable($table, $tableMappings);

            return count($tableMappings);
        } finally {
            DB::purge($connName);
        }
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, string>
     */
    private function allocateUuids(array $sourceIds): array
    {
        $mapping = [];
        foreach ($sourceIds as $oldId) {
            $mapping[$oldId] = (string) Str::uuid();
        }

        return $mapping;
    }

    private function resolvePkColumn(DatabaseSchemaData $schema, string $table, string $column): ColumnSchemaData
    {
        $tableSchema = $schema->getTable($table);
        if ($tableSchema === null) {
            throw new RuntimeException(sprintf("Table '%s' not found in source schema", $table));
        }

        foreach ($tableSchema->columns as $col) {
            if ($col->name === $column) {
                return $col;
            }
        }

        throw new RuntimeException(sprintf("Primary key column '%s' not found on table '%s' in source schema", $column, $table));
    }

    /**
     * Apply PK and FK remapping to a single row during transfer.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function applyToRow(array $row, string $tableName, KeyRemappingConfigData $config): array
    {
        $tableConfig = $config->getTable($tableName);
        if ($tableConfig instanceof KeyRemappingTableData) {
            $pkCol = $tableConfig->primaryKey;
            if (array_key_exists($pkCol, $row)) {
                $rawPk = $row[$pkCol];
                $oldPk = is_scalar($rawPk) ? (string) $rawPk : '';
                $row[$pkCol] = $this->store->lookup($tableName, $oldPk) ?? $row[$pkCol];
            }
        }

        foreach ($config->tables as $remappedTable) {
            foreach ($remappedTable->foreignKeys as $fk) {
                if ($fk->table !== $tableName) {
                    continue;
                }

                if (! array_key_exists($fk->column, $row)) {
                    continue;
                }

                $rawFk = $row[$fk->column];
                $fkValue = is_scalar($rawFk) ? (string) $rawFk : '';
                $row[$fk->column] = $this->store->lookup($remappedTable->table, $fkValue) ?? $row[$fk->column];
            }
        }

        return $row;
    }

    public function cleanup(): void
    {
        $this->store->cleanup();
    }
}
```

Note: this introduces a behaviour change vs. the old code — the previous `generateTableMapping` swallowed all `Throwable` and returned 0. We drop that broad catch. Allocation errors must surface so the run fails loud (this is the entire point of the spec). The DB connection is still purged in `finally`.

- [ ] **Step 4: Run tests, confirm new tests pass**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/KeyRemappingServiceTest.php --no-coverage`
Expected: new allocator tests pass. Existing tests calling `makeKeyRemappingTable` may still pass (range args ignored) but will break in Task 5.

- [ ] **Step 5: Commit**

```bash
git add app/Exceptions/KeyRemappingExhaustedException.php app/Services/Cloning/KeyRemappingService.php tests/Unit/Services/Cloning/KeyRemappingServiceTest.php
git commit -m "feat(cloning): rewrite key remapping for type-aware MAX(id)+1 allocation"
```

---

## Task 5: Drop range fields from data layer

**Files:**
- Modify: `app/Data/Cloning/KeyRemappingTableData.php`
- Modify: `app/Data/Cloning/ColumnCloningConfigData.php`
- Modify: `tests/Unit/Data/Cloning/KeyRemappingDtoTest.php`

- [ ] **Step 1: Update KeyRemappingTableData**

```php
// app/Data/Cloning/KeyRemappingTableData.php
<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use App\Enums\KeyRemappingStrategy;

final readonly class KeyRemappingTableData
{
    /** @param list<KeyRemappingForeignKeyData> $foreignKeys */
    public function __construct(
        public string $table,
        public string $primaryKey,
        public KeyRemappingStrategy $strategy,
        public array $foreignKeys,
    ) {}
}
```

- [ ] **Step 2: Update ColumnCloningConfigData**

```php
// app/Data/Cloning/ColumnCloningConfigData.php
<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class ColumnCloningConfigData
{
    /**
     * @param  list<scalar>  $fakerArguments
     * @param  list<KeyRemappingForeignKeyData>|null  $remappingForeignKeys
     */
    public function __construct(
        public string $columnName,
        public string $strategy,
        public ?string $fakerMethod,
        public array $fakerArguments,
        public ?string $hashAlgorithm,
        public ?string $hashSalt,
        public ?string $maskChar,
        public ?int $visibleChars,
        public ?bool $preserveFormat,
        public ?string $staticValue,
        public ?string $remappingUse = null,
        public ?array $remappingForeignKeys = null,
    ) {}
}
```

- [ ] **Step 3: Update KeyRemappingDtoTest**

Open `tests/Unit/Data/Cloning/KeyRemappingDtoTest.php` and remove `rangeMin: ...,` and `rangeMax: ...,` lines from the constructor calls. Remove any assertion lines like `->and($table->rangeMin)->toBe(...)` and `->and($table->rangeMax)->toBe(...)`.

- [ ] **Step 4: Run unit tests for the data layer**

Run: `./vendor/bin/pest tests/Unit/Data/Cloning --no-coverage`
Expected: pass.

- [ ] **Step 5: Commit (deferred — combine with Task 6 since callers will be broken until both done)**

(No commit yet. Validator/loader/writer/dump references are about to be cleaned up.)

---

## Task 6: Drop range parsing from CloningYamlLoader

**Files:**
- Modify: `app/Services/Cloning/CloningYamlLoader.php`
- Modify: `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php`

- [ ] **Step 1: Inline-remapping branch — drop range pass-through**

Edit lines 95-103 of `app/Services/Cloning/CloningYamlLoader.php`:

```php
$inlineRemappingTables[] = new KeyRemappingTableData(
    table: $tableData->tableName,
    primaryKey: $column->columnName,
    strategy: KeyRemappingStrategy::tryFrom($column->remappingUse ?? 'random_integer') ?? KeyRemappingStrategy::RandomInteger,
    foreignKeys: $column->remappingForeignKeys ?? [],
);
```

- [ ] **Step 2: Legacy `key_remapping:` branch — drop range fields**

Edit lines 207-244 of `mapKeyRemappingConfig()`. Remove the `$rangeMin = ...` and `$rangeMax = ...` lines (originally lines 209-210), and drop them from the `KeyRemappingTableData` constructor call (around line 237):

```php
$tables[] = new KeyRemappingTableData(
    table: $table,
    primaryKey: $primaryKey,
    strategy: $strategy,
    foreignKeys: $fks,
);
```

- [ ] **Step 3: Inline column parsing — drop `min` / `max` arguments**

Edit `mapColumnConfig()`. Remove the `$remappingMin = null;` and `$remappingMax = null;` declarations (lines 270-271), the `case 'min':` and `case 'max':` branches inside the foreach (lines 314-320), and the trailing `remappingMin: ...,` / `remappingMax: ...,` constructor args (lines 362-363).

- [ ] **Step 4: Update CloningYamlLoaderTest**

Open `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php` and:

1. Remove `range_min:` and `range_max:` lines from YAML literals at lines 275-276, 532-533.
2. Remove assertion lines like `expect($table?->rangeMin)->toBe(...)` and `expect($table?->rangeMax)->toBe(...)` at lines 287-288, 459-460, 542-543.
3. Remove inline-arg test assertions for `remappingMin` / `remappingMax` at lines 467-468.

If a whole test exists solely to assert defaulting of range fields, delete that test entirely.

- [ ] **Step 5: Run loader tests**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlLoaderTest.php --no-coverage`
Expected: pass.

---

## Task 7: Drop range validation + add legacy-key rejection

**Files:**
- Modify: `app/Services/Cloning/CloningYamlValidator.php`
- Modify: `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php`

- [ ] **Step 1: Replace legacy range validation with rejection**

In `validateKeyRemapping()` (around lines 318-332), replace the entire `if (($strategy ?? '') === 'random_integer') { ... }` block with rejection:

```php
foreach (['range_min', 'range_max'] as $legacyKey) {
    if (array_key_exists($legacyKey, $entry)) {
        $errors[] = sprintf(
            "%s: '%s' is no longer supported (auto-bounds is computed from column type — remove this line)",
            $prefix,
            $legacyKey,
        );
    }
}
```

- [ ] **Step 2: Reject inline `min` / `max` arguments**

In `validateColumnStrategy()` `case 'remapping':` (lines 424-468), remove the `if ($use === 'random_integer') { ... }` block (the `$min`, `$max` checks). Add legacy-key rejection after the flatten loop, before the `$use` check:

```php
foreach (['min', 'max'] as $legacyKey) {
    if (array_key_exists($legacyKey, $args)) {
        $errors[] = sprintf(
            "%s: 'remapping' argument '%s' is no longer supported (auto-bounds is computed from column type — remove this line)",
            $prefix,
            $legacyKey,
        );
    }
}
```

- [ ] **Step 3: Update CloningYamlValidatorTest**

Open `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php`:

1. In tests around lines 295-310 that include `'range_min' => 100000, 'range_max' => 9999999` in valid YAML: remove those lines.
2. Replace the test at line 381 (`returns error when range_min >= range_max for random_integer strategy`) with a new test that asserts the new rejection message:

```php
it('returns error when legacy range_min/range_max keys are present', function (): void {
    $validator = new CloningYamlValidator;

    $errors = $validator->validate([
        'version' => '1',
        'connection' => 'src',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => [
            'users' => ['rows' => ['strategy' => 'full']],
        ],
        'key_remapping' => [
            'tables' => [
                [
                    'table' => 'users',
                    'primary_key' => 'id',
                    'strategy' => 'random_integer',
                    'range_min' => 100000,
                    'range_max' => 9999999,
                ],
            ],
        ],
    ]);

    expect(implode(' ', $errors))
        ->toContain("'range_min' is no longer supported")
        ->and(implode(' ', $errors))
        ->toContain("'range_max' is no longer supported");
});

it('returns error when legacy inline min/max remapping arguments are present', function (): void {
    $validator = new CloningYamlValidator;

    $errors = $validator->validate([
        'version' => '1',
        'connection' => 'src',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => [
            'users' => [
                'rows' => ['strategy' => 'full'],
                'columns' => [
                    'id' => [
                        'strategy' => 'remapping',
                        'arguments' => [
                            ['use' => 'random_integer'],
                            ['min' => 100000],
                            ['max' => 9999999],
                            ['foreign_keys' => []],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect(implode(' ', $errors))
        ->toContain("'min' is no longer supported")
        ->and(implode(' ', $errors))
        ->toContain("'max' is no longer supported");
});
```

- [ ] **Step 4: Run validator tests**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlValidatorTest.php --no-coverage`
Expected: pass.

---

## Task 8: Drop range emission from CloningYamlWriter + DumpCommand

**Files:**
- Modify: `app/Services/Cloning/CloningYamlWriter.php` (lines 79-80)
- Modify: `app/Commands/Cloning/DumpCommand.php` (lines 350-360 area)
- Modify: `tests/Unit/Services/Cloning/CloningYamlWriterTest.php`

- [ ] **Step 1: Remove range lines from writer**

Delete lines 79-80 of `app/Services/Cloning/CloningYamlWriter.php`:

```php
// DELETE these two lines:
// $lines[] = sprintf('          - min: %d', $krTable->rangeMin);
// $lines[] = sprintf('          - max: %d', $krTable->rangeMax);
```

- [ ] **Step 2: Remove range args from DumpCommand**

Edit `app/Commands/Cloning/DumpCommand.php`. Find the `KeyRemappingTableData` construction near line 350 and remove the `rangeMin: 100000,` / `rangeMax: 9999999,` lines (currently lines 356-357).

- [ ] **Step 3: Update CloningYamlWriterTest**

Open `tests/Unit/Services/Cloning/CloningYamlWriterTest.php`:

1. Remove `rangeMin: 100000,` and `rangeMax: 9999999,` from `KeyRemappingTableData` constructor calls (lines 326-327, 367-368).
2. Adjust expected YAML strings: any test asserting `- min:` / `- max:` lines in the rendered output must drop those lines from the expected string.

- [ ] **Step 4: Run writer + dump tests**

Run: `./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlWriterTest.php tests/Feature/Commands/Cloning --no-coverage`
Expected: pass.

- [ ] **Step 5: Commit (Task 5–8 combined)**

```bash
git add app/Data/Cloning/KeyRemappingTableData.php app/Data/Cloning/ColumnCloningConfigData.php app/Services/Cloning/CloningYamlLoader.php app/Services/Cloning/CloningYamlValidator.php app/Services/Cloning/CloningYamlWriter.php app/Commands/Cloning/DumpCommand.php tests/Unit/Data/Cloning/KeyRemappingDtoTest.php tests/Unit/Services/Cloning/CloningYamlLoaderTest.php tests/Unit/Services/Cloning/CloningYamlValidatorTest.php tests/Unit/Services/Cloning/CloningYamlWriterTest.php
git commit -m "refactor(cloning): drop user-configurable key-remapping ranges"
```

---

## Task 9: Wire sourceSchema into RunCommand

**Files:**
- Modify: `app/Commands/Cloning/RunCommand.php` (line 329)

- [ ] **Step 1: Update generateMappings call**

Find the call near line 329:

```php
fn (): array => $keyRemappingService->generateMappings($keyRemappingConfig, $sourceConnection, $sortedForMapping),
```

Change to:

```php
fn (): array => $keyRemappingService->generateMappings($keyRemappingConfig, $sourceConnection, $sourceSchema, $sortedForMapping),
```

`$sourceSchema` is already in scope (read at line 301).

- [ ] **Step 2: Run feature tests**

Run: `./vendor/bin/pest tests/Feature/Commands/Cloning --no-coverage`
Expected: pass. If any feature test mocks `KeyRemappingService::generateMappings`, the mock signature needs the extra `DatabaseSchemaData` arg — fix in place.

- [ ] **Step 3: Commit**

```bash
git add app/Commands/Cloning/RunCommand.php
git commit -m "feat(cloning): pass source schema to key remapping for type-aware bounds"
```

---

## Task 10: Update PRD and project memory

**Files:**
- Modify: `specs/PRD-cloning-key-remapping.md`

- [ ] **Step 1: Edit PRD**

Apply these edits to `specs/PRD-cloning-key-remapping.md`:

1. Bump version header to `0.3` and date `2026-04-27`.
2. In §3.1 example YAML: remove the `- min: 100000` and `- max: 9999999` lines from each `arguments:` block.
3. In §3.2 field reference table: remove the `min` and `max` rows.
4. In §3.3 legacy YAML example: remove `range_min: 100000` and `range_max: 9999999`.
5. In §3.4 validation rules: remove the `range_min must be ≥ 1 and < range_max` bullet. Add: `Legacy keys (min, max, range_min, range_max) produce a hard validation error directing the user to remove them.`
6. In §4.1 Phase 5b strategy: replace bullet 2's `random_integer` description with:
   > `random_integer`: allocate IDs ascending starting at `MAX(source.pk) + 1`, capped at the column's type ceiling (TINYINT=255 unsigned/127 signed, SMALLINT=65535/32767, MEDIUMINT=16777215/8388607, INT=4294967295/2147483647, BIGINT=PHP_INT_MAX). If the upper region is too small, fall back to filling unused gaps in `[1, MAX(id)]`. If neither has room for the row count, abort with `KeyRemappingExhaustedException` (`ExitCode::GeneralError`).
7. In §6 Dry-run output example: change `key_remapping  users.id → random_integer [100000–9999999]` to `key_remapping  users.id → random_integer (auto, INT UNSIGNED ceiling 4294967295)`.
8. In §7 audit log: remove `range_min` and `range_max` keys from the example JSON.
9. In §9 error handling table: change `Range exhausted (random_integer)` row to `Type ceiling cannot host row count` with the new exception name.
10. In §10 constraints: remove the `range must be large enough` bullet. Add: `If the source PK column is narrower than the row count requires, the run aborts before any data is written. Choose a wider column type or reduce row scope.`

- [ ] **Step 2: Commit**

```bash
git add specs/PRD-cloning-key-remapping.md docs/superpowers/plans/2026-04-27-key-remapping-auto-bounds.md specs/2026-04-27-key-remapping-auto-bounds.md
git commit -m "docs(specs): document auto-bounds key remapping (no user-configured ranges)"
```

---

## Task 11: Full verification

- [ ] **Step 1: Lint**

Run: `composer lint`
Expected: 0 changes / clean.

- [ ] **Step 2: Static analysis**

Run: `composer test:types`
Expected: 0 errors.

- [ ] **Step 3: Type coverage**

Run: `composer test:type-coverage`
Expected: ≥ 90%.

- [ ] **Step 4: Unit + feature tests**

Run: `composer test:unit`
Expected: all pass, coverage ≥ 75%.

- [ ] **Step 5: Full suite**

Run: `composer test`
Expected: all green.

- [ ] **Step 6: Manual smoke check (skip if no real MySQL fixture available)**

If a local MySQL with the original failing schema is available, re-run a small `cloning:run` against a subset and confirm the duplicate-entry error is gone. Otherwise, document the manual test in the PR description for reviewer follow-up.

- [ ] **Step 7: Final commit if any cleanups were made**

```bash
git status
# if clean, no commit needed.
```
