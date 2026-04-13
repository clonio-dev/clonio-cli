<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\SchemaReplicator;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\DB;

function makeReplicatorMysqlConnection(string $name): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'testdb',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: false,
    );
}

function makeSimpleSourceSchema(): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'sourcedb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
}

it('creates missing tables in target via CREATE TABLE statement', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');
    $sourceSchema = makeSimpleSourceSchema();
    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    $statementCalled = false;

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$statementCalled): bool {
        if (str_contains($sql, 'CREATE TABLE') || str_contains($sql, 'IF OBJECT_ID')) {
            $statementCalled = true;

            return true;
        }

        return false;
    }))->once()->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['users'], false, false);

    expect($statementCalled)->toBeTrue();
});

it('adds missing columns when enforce_column_types is true', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');

    $sourceSchema = new DatabaseSchemaData(
        databaseName: 'sourcedb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'phone', type: 'varchar', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    // Target already has users table but is missing the phone column
    $targetSchema = new DatabaseSchemaData(
        databaseName: 'targetdb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($targetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    $alterCalled = false;

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$alterCalled): bool {
        if (str_contains($sql, 'ADD COLUMN') && str_contains($sql, 'phone')) {
            $alterCalled = true;

            return true;
        }

        return false;
    }))->once()->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['users'], true, false, false);

    expect($alterCalled)->toBeTrue();
});

it('drops extra columns when drop_extra_columns is true', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');

    $sourceSchema = new DatabaseSchemaData(
        databaseName: 'sourcedb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    // Target has an extra column (legacy_col) not in source
    $targetSchema = new DatabaseSchemaData(
        databaseName: 'targetdb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'legacy_col', type: 'varchar', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($targetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    $dropColumnCalled = false;

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$dropColumnCalled): bool {
        if (str_contains($sql, 'DROP COLUMN') && str_contains($sql, 'legacy_col')) {
            $dropColumnCalled = true;

            return true;
        }

        return false;
    }))->once()->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['users'], false, false, true);

    expect($dropColumnCalled)->toBeTrue();
});

it('does not drop extra columns when drop_extra_columns is false', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');

    $sourceSchema = makeSimpleSourceSchema();

    // Target has extra column
    $targetSchema = new DatabaseSchemaData(
        databaseName: 'targetdb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'extra_col', type: 'text', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($targetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('target_conn');

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    // No statement should be called (table already exists, enforceColumnTypes=false, dropExtraColumns=false)
    DB::shouldReceive('statement')->never();
    DB::shouldReceive('purge')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['users'], false, false, false);

    expect(true)->toBeTrue(); // no exception thrown
});

it('maps mediumint primary key to MEDIUMINT for MySQL target', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');

    $sourceSchema = new DatabaseSchemaData(
        databaseName: 'sourcedb',
        tables: [
            new TableSchemaData(
                name: 'orders',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'mediumint', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'total', type: 'decimal', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    // open() called twice: once for native DDL fetch from source, once to open target
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    $capturedSql = null;

    // source connection for SHOW CREATE TABLE (returns nothing → falls back to generic)
    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->with(Mockery::pattern('/SHOW CREATE TABLE/i'))->andReturn([]);
    DB::shouldReceive('purge')->with('source_conn')->andReturnNull();

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$capturedSql): bool {
        $capturedSql = $sql;

        return true;
    }))->once()->andReturnTrue();
    DB::shouldReceive('purge')->with('target_conn')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['orders'], false, false);

    expect($capturedSql)->toContain('MEDIUMINT');
});

it('uses native DDL from source when same DB type and SHOW CREATE TABLE succeeds', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');

    $sourceSchema = new DatabaseSchemaData(
        databaseName: 'sourcedb',
        tables: [
            new TableSchemaData(
                name: 'products',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'mediumint', nullable: false, default: null, isPrimary: true),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);

    $nativeDdl = "CREATE TABLE `products` (\n  `id` mediumint unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4";

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    $capturedSql = null;
    $showRow = new stdClass;
    $showRow->{'Create Table'} = $nativeDdl;

    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->with(Mockery::pattern('/SHOW CREATE TABLE/i'))->andReturn([$showRow]);
    DB::shouldReceive('purge')->with('source_conn')->andReturnNull();

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$capturedSql): bool {
        $capturedSql = $sql;

        return true;
    }))->once()->andReturnTrue();
    DB::shouldReceive('purge')->with('target_conn')->andReturnNull();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['products'], false, false);

    // Native DDL should be used — contains original mediumint unsigned type
    expect($capturedSql)
        ->toContain('IF NOT EXISTS')
        ->toContain('mediumint unsigned')
        ->not->toContain('AUTO_INCREMENT=42')
        ->not->toContain('CONSTRAINT')
        ->not->toContain('FOREIGN KEY');
});

it('strips FOREIGN KEY constraints from native DDL', function (): void {
    $ddl = <<<'SQL'
CREATE TABLE `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `orders_user_id_foreign` (`user_id`),
  CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
SQL;

    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');
    $sourceSchema = new DatabaseSchemaData(databaseName: 'sourcedb', tables: [
        new TableSchemaData(
            name: 'orders',
            columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
            foreignKeys: [],
        ),
    ]);

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);
    $showRow = new stdClass;
    $showRow->{'Create Table'} = $ddl;

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    $capturedSql = null;
    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([$showRow]);
    DB::shouldReceive('purge')->andReturnNull();

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$capturedSql): bool {
        $capturedSql = $sql;

        return true;
    }))->once()->andReturnTrue();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['orders'], false, false);

    expect($capturedSql)
        ->not->toContain('FOREIGN KEY')
        ->not->toContain('CONSTRAINT')
        ->not->toContain('REFERENCES')
        ->toContain('IF NOT EXISTS');
});

it('strips FOREIGN KEY constraints with ON DELETE and multiple columns from native DDL', function (): void {
    $ddl = <<<'SQL'
CREATE TABLE `order_items` (
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  PRIMARY KEY (`order_id`,`product_id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB
SQL;

    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');
    $sourceSchema = new DatabaseSchemaData(databaseName: 'sourcedb', tables: [
        new TableSchemaData(
            name: 'order_items',
            columns: [new ColumnSchemaData(name: 'order_id', type: 'int', nullable: false, default: null, isPrimary: true)],
            foreignKeys: [],
        ),
    ]);

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);
    $showRow = new stdClass;
    $showRow->{'Create Table'} = $ddl;

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    $capturedSql = null;
    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([$showRow]);
    DB::shouldReceive('purge')->andReturnNull();

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    DB::shouldReceive('statement')->with(Mockery::on(static function ($sql) use (&$capturedSql): bool {
        $capturedSql = $sql;

        return true;
    }))->once()->andReturnTrue();

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->replicate($sourceConn, $targetConn, $sourceSchema, ['order_items'], false, false);

    expect($capturedSql)
        ->not->toContain('FOREIGN KEY')
        ->not->toContain('CONSTRAINT')
        ->not->toContain('REFERENCES')
        ->not->toContain('ON DELETE')
        ->toContain('IF NOT EXISTS');
});

it('falls back to buildCreateTableSql when native DDL execution fails on target', function (): void {
    $sourceConn = makeReplicatorMysqlConnection('source');
    $targetConn = makeReplicatorMysqlConnection('target');
    $sourceSchema = makeSimpleSourceSchema(); // has 'users' table with id (int) column

    $emptyTargetSchema = new DatabaseSchemaData(databaseName: 'targetdb', tables: []);
    $showRow = new stdClass;
    $showRow->{'Create Table'} = 'CREATE TABLE `users` (`id` int NOT NULL) ENGINE=InnoDB';

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($emptyTargetSchema);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source_conn', 'target_conn');

    $fallbackCalled = false;

    DB::shouldReceive('connection')->with('source_conn')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([$showRow]);
    DB::shouldReceive('purge')->with('source_conn')->andReturnNull();

    DB::shouldReceive('connection')->with('target_conn')->andReturnSelf();
    // First statement call (native DDL) throws; second (fallback) succeeds
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
    $showRow->{'Create Table'} = 'CREATE TABLE `users` (`id` int NOT NULL) ENGINE=InnoDB';

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
    $maxRow->next_val = 1;

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

it('throws when AUTO_INCREMENT correction query fails', function (): void {
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

it('does not connect to target for non-mysql AUTO_INCREMENT correction', function (): void {
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

    $replicator = new SchemaReplicator($inspector, $connector);
    $replicator->correctAutoIncrement($targetConn, 'users', 'id');

    expect(true)->toBeTrue(); // no exception, no connection opened
});
