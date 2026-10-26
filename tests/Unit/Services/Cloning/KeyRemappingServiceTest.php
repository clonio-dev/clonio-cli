<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Enums\KeyRemappingStrategy;
use App\Exceptions\KeyRemappingExhaustedException;
use App\Services\Cloning\InMemoryKeyRemappingStore;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;

function makeKeyRemappingService(?InMemoryKeyRemappingStore $store = null): KeyRemappingService
{
    $connector = Mockery::mock(DatabaseConnectionService::class);

    return $store !== null
        ? new KeyRemappingService($connector, $store)
        : new KeyRemappingService($connector);
}

function makeKeyRemappingTable(string $table, string $pk = 'id', array $fks = []): KeyRemappingTableData
{
    return new KeyRemappingTableData(
        table: $table,
        primaryKey: $pk,
        strategy: KeyRemappingStrategy::NewUuid,
        foreignKeys: $fks,
    );
}

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

it('applyToRow does not modify row when store has no mappings', function (): void {
    $service = makeKeyRemappingService();
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 1, 'name' => 'Alice'];

    expect($service->applyToRow($row, 'users', $config))->toBe($row);
});

it('cleanup resets store mappings', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['1' => 'new-uuid']);

    $service = makeKeyRemappingService($store);
    $service->cleanup();

    expect($store->lookup('users', '1'))->toBeNull();
});

it('applyToRow remaps the primary key for a configured table', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['42' => 'new-uuid-for-42']);

    $service = makeKeyRemappingService($store);
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 42, 'name' => 'Alice'];
    $result = $service->applyToRow($row, 'users', $config);

    expect($result['id'])->toBe('new-uuid-for-42')
        ->and($result['name'])->toBe('Alice');
});

it('applyToRow keeps original PK value when no mapping exists', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', []);

    $service = makeKeyRemappingService($store);
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 99, 'name' => 'Bob'];
    $result = $service->applyToRow($row, 'users', $config);

    expect($result['id'])->toBe(99);
});

it('applyToRow remaps foreign key columns referencing another remapped table', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['10' => 'uuid-10']);

    $service = makeKeyRemappingService($store);

    $fk = new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false);
    $usersTable = new KeyRemappingTableData(
        table: 'users',
        primaryKey: 'id',
        strategy: KeyRemappingStrategy::NewUuid,
        foreignKeys: [$fk],
    );
    $config = new KeyRemappingConfigData([$usersTable]);

    $row = ['id' => 5, 'user_id' => 10, 'amount' => 99.99];
    $result = $service->applyToRow($row, 'orders', $config);

    expect($result['user_id'])->toBe('uuid-10')
        ->and($result['id'])->toBe(5);
});

it('applyToRow skips FK column that does not exist on the row', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['1' => 'uuid-1']);

    $service = makeKeyRemappingService($store);

    $fk = new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false);
    $usersTable = makeKeyRemappingTable('users', 'id', [$fk]);
    $config = new KeyRemappingConfigData([$usersTable]);

    $row = ['id' => 5, 'name' => 'test'];
    $result = $service->applyToRow($row, 'orders', $config);

    expect($result)->toBe(['id' => 5, 'name' => 'test']);
});

it('applyToRow skips foreign keys that belong to a different table', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['10' => 'uuid-10']);

    $service = makeKeyRemappingService($store);

    $fk = new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false);
    $usersTable = makeKeyRemappingTable('users', 'id', [$fk]);
    $config = new KeyRemappingConfigData([$usersTable]);

    // Applying for 'invoices' → the FK (owned by 'orders') is skipped
    $row = ['id' => 1, 'user_id' => 10];
    $result = $service->applyToRow($row, 'invoices', $config);

    expect($result)->toBe(['id' => 1, 'user_id' => 10]);
});

it('applyToRow does not modify row for unconfigured table', function (): void {
    $service = makeKeyRemappingService();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users')]);

    $row = ['id' => 1, 'name' => 'Alice'];
    $result = $service->applyToRow($row, 'unconfigured_table', $config);

    expect($result)->toBe($row);
});

function makeKrsSource(DatabaseConnectionType $type = DatabaseConnectionType::Mysql): ConnectionData
{
    return new ConnectionData(
        name: 'source',
        type: $type,
        host: 'localhost',
        port: 3306,
        database: 'src',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: false,
    );
}

function makeKrsSchema(string $table = 'users', string $pk = 'id', string $pkType = 'int'): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'src',
        tables: [
            new TableSchemaData(
                name: $table,
                columns: [
                    new ColumnSchemaData(name: $pk, type: $pkType, nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'name', type: 'varchar', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
}

/** @param list<int|string> $ids */
function krsRows(array $ids, string $col = 'id'): array
{
    return array_map(static function ($id) use ($col): stdClass {
        $row = new stdClass;
        $row->{$col} = $id;

        return $row;
    }, $ids);
}

it('generateMappings allocates UUIDs and skips unconfigured tables', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, $store);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows([1, 2, 3]));
    DB::shouldReceive('purge')->with('krs_conn')->andReturnNull();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    // 'orders' has no remapping config → skipped without error
    $counts = $service->generateMappings($config, makeKrsSource(), makeKrsSchema(), ['users', 'orders']);

    expect($counts)->toBe(['users' => 3]);
    expect($store->lookup('users', '1'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('generateMappings allocates random integers using the source schema column', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, $store);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows([1, 2, 3]));
    DB::shouldReceive('purge')->andReturnNull();

    $table = new KeyRemappingTableData(
        table: 'users',
        primaryKey: 'id',
        strategy: KeyRemappingStrategy::RandomInteger,
        foreignKeys: [],
    );
    $config = new KeyRemappingConfigData([$table]);

    $counts = $service->generateMappings($config, makeKrsSource(), makeKrsSchema('users', 'id', 'int'), ['users']);

    expect($counts)->toBe(['users' => 3]);
    // existingMax = 3 → new ids start at 4
    expect($store->lookup('users', '1'))->toBe('4');
    expect($store->lookup('users', '3'))->toBe('6');
});

it('generateTableMapping quotes identifiers per driver', function (): void {
    $captured = [];
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, new InMemoryKeyRemappingStore);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->withArgs(function (string $sql) use (&$captured): bool {
        $captured[] = $sql;

        return true;
    })->andReturn(krsRows([1]));
    DB::shouldReceive('purge')->andReturnNull();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $service->generateMappings($config, makeKrsSource(DatabaseConnectionType::PostgreSQL), makeKrsSchema(), ['users']);
    $service->generateMappings($config, makeKrsSource(DatabaseConnectionType::SqlServer), makeKrsSchema(), ['users']);

    expect($captured[0])->toContain('"id"')->toContain('"users"');
    expect($captured[1])->toContain('[id]')->toContain('[users]');
});

it('generateMappings throws when the primary key table is missing from the schema', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, new InMemoryKeyRemappingStore);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows([1]));
    DB::shouldReceive('purge')->andReturnNull();

    $table = new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::RandomInteger, []);
    $config = new KeyRemappingConfigData([$table]);
    $schemaWithoutTable = new DatabaseSchemaData(databaseName: 'src', tables: []);

    expect(fn () => $service->generateMappings($config, makeKrsSource(), $schemaWithoutTable, ['users']))
        ->toThrow(RuntimeException::class, 'not found in source schema');
});

it('generateMappings throws when the primary key column is missing from the schema', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, new InMemoryKeyRemappingStore);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows([1]));
    DB::shouldReceive('purge')->andReturnNull();

    $table = new KeyRemappingTableData('users', 'missing_pk', KeyRemappingStrategy::RandomInteger, []);
    $config = new KeyRemappingConfigData([$table]);

    expect(fn () => $service->generateMappings($config, makeKrsSource(), makeKrsSchema('users', 'id'), ['users']))
        ->toThrow(RuntimeException::class, 'not found on table');
});

it('allocateIntegerIds returns ascending ids starting at MAX(id)+1', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = ['10', '20', '30', '40', '50'];
    $existingMax = 200;

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, $existingMax, []);

    expect(array_values($result))->toBe(['201', '202', '203', '204', '205'])
        ->and(array_keys($result))->toBe([10, 20, 30, 40, 50]);
});

it('allocateIntegerIds falls back to gap-fill when upper region exhausted', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = ['1', '2', '3', '4', '5'];
    $existingMax = 253;
    $existingIds = [1, 2, 250, 251, 252, 253]; // gaps in [1,253] = [3..249] (lots of room)

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, $existingMax, $existingIds);

    // upper slots: 255 - 254 + 1 = 2 → IDs 254, 255
    // remaining 3: first 3 gaps (skipping existing 1,2) = 3, 4, 5
    expect(array_values($result))->toBe(['254', '255', '3', '4', '5']);
});

it('allocateIntegerIds throws when type ceiling cannot host row count', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = array_map(static fn (int $i): string => (string) $i, range(1, 300));
    $existingMax = 0;

    $service->allocateIntegerIds('big_table', $col, $sourceIds, $existingMax, []);
})->throws(KeyRemappingExhaustedException::class);

it('allocateIntegerIds starts at 1 when source table is empty (existingMax=0)', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int');

    $result = $service->allocateIntegerIds('users', $col, ['7'], 0, []);

    expect($result)->toBe([7 => '1']);
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
        100 => '1001',
        99 => '1002',
        500 => '1003',
        7 => '1004',
    ]);
});

it('allocateIntegerIds returns empty mapping for empty source', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int');

    $result = $service->allocateIntegerIds('users', $col, [], 0, []);

    expect($result)->toBe([]);
});

it('KeyRemappingExhaustedException builds a descriptive message for signed columns', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint'); // signed ceiling 127

    $existingIds = array_values(array_diff(range(1, 126), [5])); // single gap at 5

    try {
        $service->allocateIntegerIds('t', $col, ['a', 'b', 'c'], 126, $existingIds);
        $this->fail('Expected KeyRemappingExhaustedException');
    } catch (KeyRemappingExhaustedException $e) {
        expect($e->getMessage())->toBe(
            "Cannot remap table 't': column 'id' is TINYINT (signed, ceiling 127); 3 rows requested, only 2 slots available (1 above MAX(id), 1 gaps below)."
        )
            ->and($e->table)->toBe('t')
            ->and($e->upperSlots)->toBe(1)
            ->and($e->gapSlots)->toBe(1);
    }
});

it('KeyRemappingExhaustedException reports unsigned columns', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = array_map(static fn (int $i): string => (string) $i, range(1, 300));

    expect(fn () => $service->allocateIntegerIds('big_table', $col, $sourceIds, 0, []))
        ->toThrow(
            KeyRemappingExhaustedException::class,
            "Cannot remap table 'big_table': column 'id' is TINYINT (unsigned, ceiling 255); 300 rows requested, only 255 slots available (255 above MAX(id), 0 gaps below)."
        );
});

it('allocateIntegerIds returns an empty mapping for empty source without resolving the column type', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('varchar'); // unsupported by the bounds resolver

    expect($service->allocateIntegerIds('users', $col, [], 0, []))->toBe([]);
});

it('allocateIntegerIds starts at 1 when all existing ids are negative', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int');

    expect($service->allocateIntegerIds('users', $col, ['-5'], -5, [-5]))->toBe([-5 => '1']);
});

it('allocateIntegerIds uses the very last slot at the type ceiling', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    expect($service->allocateIntegerIds('users', $col, ['9'], 254, range(1, 254)))->toBe([9 => '255']);
});

it('allocateIntegerIds uses only gaps when MAX(id) already sits at the type ceiling', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $existingIds = array_values(array_diff(range(1, 255), [7]));

    expect($service->allocateIntegerIds('users', $col, ['9'], 255, $existingIds))->toBe([9 => '7']);
});

it('allocateIntegerIds fills the gap at id 1', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    expect($service->allocateIntegerIds('users', $col, ['9'], 255, range(2, 255)))->toBe([9 => '1']);
});

it('allocateIntegerIds succeeds when the gaps exactly cover the remaining rows', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    // upper slots: 255 only; gaps: 3 and 10
    $existingIds = array_values(array_diff(range(1, 254), [3, 10]));

    $result = $service->allocateIntegerIds('users', $col, ['a', 'b', 'c'], 254, $existingIds);

    expect($result)->toBe(['a' => '255', 'b' => '3', 'c' => '10']);
});

it('allocateIntegerIds treats MAX(id) itself as a gap candidate when it is not in the existing ids', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = array_map(static fn (int $i): string => 's'.$i, range(1, 255));

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, 1, []);

    // 254 upper slots (2..255) + gap 1
    expect($result['s1'])->toBe('2')
        ->and($result['s254'])->toBe('255')
        ->and($result['s255'])->toBe('1');
});

it('generateMappings keeps processing tables after an unconfigured one', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, $store);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows([1, 2]));
    DB::shouldReceive('purge')->once()->with('krs_conn')->andReturnNull();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $counts = $service->generateMappings($config, makeKrsSource(), makeKrsSchema(), ['orders', 'users']);

    expect($counts)->toBe(['users' => 2]);
});

it('generateTableMapping quotes identifiers with backticks for mysql and mariadb', function (): void {
    $captured = [];
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, new InMemoryKeyRemappingStore);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->withArgs(function (string $sql) use (&$captured): bool {
        $captured[] = $sql;

        return true;
    })->andReturn(krsRows([1], 'uid'));
    DB::shouldReceive('purge')->andReturnNull();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'uid')]);

    $service->generateMappings($config, makeKrsSource(DatabaseConnectionType::Mysql), makeKrsSchema(), ['users']);
    $service->generateMappings($config, makeKrsSource(DatabaseConnectionType::PostgreSQL), makeKrsSchema(), ['users']);
    $service->generateMappings($config, makeKrsSource(DatabaseConnectionType::SqlServer), makeKrsSchema(), ['users']);

    expect($captured)->toBe([
        'SELECT `uid` FROM `users`',
        'SELECT "uid" FROM "users"',
        'SELECT [uid] FROM [users]',
    ]);
});

it('generateMappings maps null and fractional primary keys by their string form', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, $store);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows([null, 1.5]));
    DB::shouldReceive('purge')->andReturnNull();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $service->generateMappings($config, makeKrsSource(), makeKrsSchema(), ['users']);

    expect($store->lookup('users', ''))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($store->lookup('users', '1.5'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($store->lookup('users', 'PEST Mutator was here!'))->toBeNull();
});

it('generateMappings starts random integers at 1 when no source id is numeric', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('krs_conn');
    $service = new KeyRemappingService($connector, $store);

    DB::shouldReceive('connection')->with('krs_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(krsRows(['abc', 'def']));
    DB::shouldReceive('purge')->andReturnNull();

    $table = new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::RandomInteger, []);
    $config = new KeyRemappingConfigData([$table]);

    $service->generateMappings($config, makeKrsSource(), makeKrsSchema(), ['users']);

    expect($store->lookup('users', 'abc'))->toBe('1')
        ->and($store->lookup('users', 'def'))->toBe('2');
});

it('applyToRow maps null primary and foreign keys through their empty-string mapping', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['' => 'uuid-empty']);

    $service = makeKeyRemappingService($store);

    $fk = new KeyRemappingForeignKeyData(table: 'users', column: 'parent_id', selfReferential: true);
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id', [$fk])]);

    $result = $service->applyToRow(['id' => null, 'parent_id' => null], 'users', $config);

    expect($result)->toBe(['id' => 'uuid-empty', 'parent_id' => 'uuid-empty']);
});

it('applyToRow continues with later foreign keys after skipping non-matching ones', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['10' => 'uuid-10', '20' => 'uuid-20']);

    $service = makeKeyRemappingService($store);

    $usersTable = makeKeyRemappingTable('users', 'id', [
        new KeyRemappingForeignKeyData(table: 'invoices', column: 'user_id', selfReferential: false),
        new KeyRemappingForeignKeyData(table: 'orders', column: 'missing_col', selfReferential: false),
        new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false),
        new KeyRemappingForeignKeyData(table: 'orders', column: 'approver_id', selfReferential: false),
    ]);
    $config = new KeyRemappingConfigData([$usersTable]);

    $result = $service->applyToRow(['id' => 1, 'user_id' => 10, 'approver_id' => 20], 'orders', $config);

    expect($result)->toBe(['id' => 1, 'user_id' => 'uuid-10', 'approver_id' => 'uuid-20']);
});
