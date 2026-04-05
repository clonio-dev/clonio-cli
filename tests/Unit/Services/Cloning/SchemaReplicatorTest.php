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
