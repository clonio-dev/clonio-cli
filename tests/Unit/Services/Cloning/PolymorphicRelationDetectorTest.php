<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\PolymorphicRelationData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\PolymorphicRelationDetector;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\DB;

function prdColumn(string $name, string $type, bool $primary = false): ColumnSchemaData
{
    return new ColumnSchemaData($name, $type, false, null, $primary);
}

/**
 * @param  list<ColumnSchemaData>  $columns
 * @param  list<ForeignKeyData>  $foreignKeys
 */
function prdTable(string $name, array $columns, array $foreignKeys = []): TableSchemaData
{
    return new TableSchemaData($name, $columns, $foreignKeys);
}

/**
 * A SQLite source with users, companies, addresses (addressable) and
 * comments (commentable, which can point at comments).
 */
function prdSource(): ConnectionData
{
    $path = tempnam(sys_get_temp_dir(), 'clonio_prd_').'.sqlite';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE addresses (id INTEGER PRIMARY KEY, addressable_type VARCHAR(255), addressable_id INTEGER, city TEXT)');
    $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, commentable_type VARCHAR(255), commentable_id INTEGER)');
    $pdo->exec("INSERT INTO addresses VALUES (1, 'App\\Models\\User', 1, 'Berlin'), (2, 'company', 1, 'Hamburg'), (3, 'App\\Models\\User', 2, 'Munich'), (4, 'team', 1, 'Cologne'), (5, NULL, NULL, 'Bonn'), (6, '', 1, 'Kiel')");
    $pdo->exec("INSERT INTO comments VALUES (1, 'users', 1), (2, 'App\\Models\\Comment', 1), (3, 'person', 1)");

    return new ConnectionData('source', DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

function prdDetector(): PolymorphicRelationDetector
{
    return app(PolymorphicRelationDetector::class);
}

describe('candidates', function (): void {
    it('finds a string _type column next to an integer _id column in every table', function (): void {
        $schema = new DatabaseSchemaData('db', [
            prdTable('addresses', [
                prdColumn('id', 'integer', true),
                prdColumn('addressable_type', 'varchar'),
                prdColumn('addressable_id', 'bigint'),
                prdColumn('locatable_type', 'text'),
                prdColumn('locatable_id', 'int'),
            ]),
            prdTable('comments', [
                prdColumn('commentable_type', 'character varying'),
                prdColumn('commentable_id', 'integer'),
            ]),
            prdTable('things', [
                prdColumn('thing_type', 'string'),
                prdColumn('thing_id', 'serial'),
            ]),
        ]);

        expect(prdDetector()->candidates($schema))->toEqual([
            new PolymorphicRelationData('addresses', 'addressable_type', 'addressable_id'),
            new PolymorphicRelationData('addresses', 'locatable_type', 'locatable_id'),
            new PolymorphicRelationData('comments', 'commentable_type', 'commentable_id'),
            new PolymorphicRelationData('things', 'thing_type', 'thing_id'),
        ]);
    });

    it('skips pairs that do not look polymorphic', function (): void {
        $schema = new DatabaseSchemaData('db', [
            prdTable('a', [prdColumn('owner_type', 'integer'), prdColumn('owner_id', 'integer')]),
            prdTable('b', [prdColumn('owner_type', 'varchar'), prdColumn('owner_id', 'char(36)')]),
            prdTable('c', [prdColumn('owner_type', 'varchar')]),
            prdTable('d', [prdColumn('owner_kind', 'varchar'), prdColumn('owner_id', 'integer')]),
            prdTable('e', [prdColumn('owner_type', 'varchar'), prdColumn('owner_id', 'integer')], [
                new ForeignKeyData('owner_id', 'users', 'id'),
            ]),
            prdTable('f', [prdColumn('type', 'varchar'), prdColumn('id', 'integer', true)]),
        ]);

        expect(prdDetector()->candidates($schema))->toBe([]);
    });

    it('keeps looking in a table after a pair that does not match', function (): void {
        $schema = new DatabaseSchemaData('db', [
            prdTable('a', [
                prdColumn('owner_type', 'varchar'),
                prdColumn('owner_id', 'char(36)'),
                prdColumn('other_type', 'varchar'),
                prdColumn('other_id', 'integer'),
            ]),
        ]);

        expect(prdDetector()->candidates($schema))->toEqual([
            new PolymorphicRelationData('a', 'other_type', 'other_id'),
        ]);
    });

    it('reads the bigserial id type as integer', function (): void {
        $schema = new DatabaseSchemaData('db', [
            prdTable('a', [prdColumn('owner_type', 'VARCHAR'), prdColumn('owner_id', 'BIGSERIAL')]),
        ]);

        expect(prdDetector()->candidates($schema))->toHaveCount(1);
    });
});

describe('detect', function (): void {
    it('maps each type value to a remapped table', function (): void {
        $connection = prdSource();
        $schema = app(SchemaInspector::class)->inspect($connection);

        $detection = prdDetector()->detect($connection, $schema, [
            'users' => 'id',
            'companies' => 'id',
            'comments' => 'id',
            'person' => 'id',
        ]);

        expect($detection->foreignKeysByTable)->toEqual([
            'users' => [
                new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'App\Models\User'),
                new KeyRemappingForeignKeyData('comments', 'commentable_id', false, 'commentable_type', 'users'),
            ],
            'companies' => [
                new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'company'),
            ],
            'comments' => [
                new KeyRemappingForeignKeyData('comments', 'commentable_id', true, 'commentable_type', 'App\Models\Comment'),
            ],
            'person' => [
                new KeyRemappingForeignKeyData('comments', 'commentable_id', false, 'commentable_type', 'person'),
            ],
        ])
            ->and($detection->unresolved)->toBe(["addresses.addressable_type = 'team'"]);
    });

    it('closes the source connection when done', function (): void {
        $connection = prdSource();
        $schema = app(SchemaInspector::class)->inspect($connection);
        $before = array_keys(DB::getConnections());

        prdDetector()->detect($connection, $schema, ['users' => 'id']);

        expect(array_keys(DB::getConnections()))->toBe($before);
    });

    it('reads at most 100 type values per column', function (): void {
        $connection = prdSource();
        $pdo = new PDO('sqlite:'.$connection->database);

        for ($i = 0; $i < 101; $i++) {
            $pdo->exec(sprintf("INSERT INTO comments (commentable_type, commentable_id) VALUES ('type%03d', 1)", $i));
        }

        $schema = app(SchemaInspector::class)->inspect($connection);
        $detection = prdDetector()->detect($connection, $schema, ['nothing' => 'id']);

        $comments = array_filter($detection->unresolved, static fn (string $entry): bool => str_starts_with($entry, 'comments.'));

        expect($comments)->toHaveCount(100);
    });

    it('does not open the source without candidate pairs or remapped tables', function (array $tables, array $remapped): void {
        $connector = Mockery::mock(DatabaseConnectionService::class);
        $connector->shouldNotReceive('open');

        $detection = new PolymorphicRelationDetector($connector)->detect(
            new ConnectionData('source', DatabaseConnectionType::Sqlite, null, null, ':memory:', null, null, '', false),
            new DatabaseSchemaData('db', $tables),
            $remapped,
        );

        expect($detection->foreignKeysByTable)->toBe([])
            ->and($detection->unresolved)->toBe([]);
    })->with([
        'no candidates' => [[prdTable('users', [prdColumn('id', 'integer', true)])], ['users' => 'id']],
        'no remapped tables' => [[prdTable('a', [prdColumn('owner_type', 'varchar'), prdColumn('owner_id', 'integer')])], []],
    ]);
});
