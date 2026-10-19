<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Schema\SchemaInspector;
use App\Services\SqlDump\DumpArchiver;
use App\Services\SqlDump\DumpDialectFactory;
use App\Services\SqlDump\SqlDumpService;
use Illuminate\Support\Facades\Storage;

// Issue #189: generated columns are never copied (the database computes them,
// and they may be derived from PII), and the 'exclude' strategy leaves a
// column out of the transfer. Exercised against real SQLite databases.

const BOOKINGS_DDL = <<<'SQL'
CREATE TABLE bookings (
    id INTEGER PRIMARY KEY,
    guest_name TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    day TEXT GENERATED ALWAYS AS (date(starts_at)) VIRTUAL,
    guest_label TEXT GENERATED ALWAYS AS (upper(guest_name)) STORED,
    internal_note TEXT
)
SQL;

function generatedSqlitePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'clonio_gen_').'.sqlite';
    touch($path);

    return $path;
}

function generatedSqliteConnection(string $name, string $path): ConnectionData
{
    return new ConnectionData($name, DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

function seedGeneratedSource(): string
{
    $path = generatedSqlitePath();
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec(BOOKINGS_DDL);
    $pdo->exec("INSERT INTO bookings (id, guest_name, starts_at, internal_note) VALUES
        (1, 'Alice Smith', '2026-01-15 10:00:00', 'VIP'),
        (2, 'Bob Jones', '2026-02-20 14:30:00', 'pays cash')");

    return $path;
}

function bookingsConfig(): CloningConfigData
{
    $col = static fn (string $name, string $strategy, ?string $faker = null): ColumnCloningConfigData => new ColumnCloningConfigData($name, $strategy, $faker, [], null, null, null, null, null, null);

    return new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(100, false, false, false, true, 'en_US'),
        tables: [new TableCloningConfigData('bookings', new TableRowConfigData('full', null, null), [
            $col('guest_name', 'fake', 'name'),
            $col('internal_note', 'exclude'),
        ])],
    );
}

function runBookings(ConnectionData $source, ConnectionData $target, bool $skipSchema, ?SqlDumpService $sink = null): RunResultData
{
    return resolve(CloningRunOrchestrator::class)->run(
        config: bookingsConfig(),
        source: $source,
        target: $target,
        sourceSchema: resolve(SchemaInspector::class)->inspect($source),
        skipSchema: $skipSchema,
        skipTables: [],
        onlyTables: [],
        onProgress: function (): void {},
        dumpSink: $sink,
    );
}

/** @return list<array<string, mixed>> */
function fetchBookings(string $path): array
{
    return (new PDO('sqlite:'.$path))->query('SELECT * FROM bookings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

it('leaves generated columns empty when the target gets plain columns', function (): void {
    $sourcePath = seedGeneratedSource();
    $targetPath = generatedSqlitePath();

    $result = runBookings(generatedSqliteConnection('source', $sourcePath), generatedSqliteConnection('target', $targetPath), false);
    $rows = fetchBookings($targetPath);

    expect($result->success)->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and(array_column($rows, 'day'))->toBe([null, null])
        ->and(array_column($rows, 'guest_label'))->toBe([null, null])
        ->and(array_column($rows, 'internal_note'))->toBe([null, null])
        ->and(array_column($rows, 'guest_name'))->not->toContain('Alice Smith')
        ->and($result->tables[0]->notes)->toBe([
            'Generated columns not copied and left empty: day, guest_label. The generation expression was not replicated to the target.',
            "Columns excluded by 'exclude' strategy: internal_note",
        ]);

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('lets the target compute generated columns from the anonymized values', function (): void {
    $sourcePath = seedGeneratedSource();
    $targetPath = generatedSqlitePath();
    (new PDO('sqlite:'.$targetPath))->exec(BOOKINGS_DDL);

    $result = runBookings(generatedSqliteConnection('source', $sourcePath), generatedSqliteConnection('target', $targetPath), true);
    $rows = fetchBookings($targetPath);

    expect($result->success)->toBeTrue()
        ->and(array_column($rows, 'day'))->toBe(['2026-01-15', '2026-02-20'])
        ->and(array_column($rows, 'internal_note'))->toBe([null, null])
        ->and($result->tables[0]->notes[0])->toBe('Generated columns not copied, computed by the target: day, guest_label');

    foreach ($rows as $row) {
        // Recomputed from the fake name, never the original PII.
        expect($row['guest_label'])->toBe(strtoupper((string) $row['guest_name']))
            ->and($row['guest_label'])->not->toBeIn(['ALICE SMITH', 'BOB JONES']);
    }

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('skips columns that are generated only in the target', function (): void {
    $sourcePath = generatedSqlitePath();
    $source = new PDO('sqlite:'.$sourcePath);
    $source->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY, guest_name TEXT NOT NULL, starts_at TEXT NOT NULL, day TEXT, guest_label TEXT, internal_note TEXT)');
    $source->exec("INSERT INTO bookings VALUES (1, 'Alice Smith', '2026-01-15 10:00:00', 'stale', 'ALICE SMITH', 'VIP')");
    $targetPath = generatedSqlitePath();
    (new PDO('sqlite:'.$targetPath))->exec(BOOKINGS_DDL);

    $result = runBookings(generatedSqliteConnection('source', $sourcePath), generatedSqliteConnection('target', $targetPath), true);
    $rows = fetchBookings($targetPath);

    expect($result->success)->toBeTrue()
        ->and($rows[0]['day'])->toBe('2026-01-15')
        ->and($rows[0]['guest_label'])->toBe(strtoupper((string) $rows[0]['guest_name']));

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('warns when an excluded column is NOT NULL without a default on the target', function (): void {
    $sourcePath = seedGeneratedSource();
    $targetPath = generatedSqlitePath();
    (new PDO('sqlite:'.$targetPath))->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY, guest_name TEXT NOT NULL, starts_at TEXT NOT NULL, day TEXT, guest_label TEXT, internal_note TEXT NOT NULL)');

    $result = runBookings(generatedSqliteConnection('source', $sourcePath), generatedSqliteConnection('target', $targetPath), true);

    expect($result->tables[0]->notes)->toContain('Excluded columns are NOT NULL without a default on the target, inserts may fail: internal_note');

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('dumps generated columns as empty nullable columns without values', function (): void {
    Storage::fake('local');
    $sourcePath = seedGeneratedSource();
    $dump = new ConnectionData('dump', DatabaseConnectionType::Dump, null, null, null, null, null, '', false, dialect: DatabaseConnectionType::Mysql);
    $sink = new SqlDumpService(new DumpDialectFactory, new DumpArchiver);

    $result = runBookings(generatedSqliteConnection('source', $sourcePath), $dump, false, $sink);
    $dumpResult = $sink->finish();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($dumpResult->zipFileName));
    $sql = (string) $zip->getFromIndex(0);
    $zip->close();

    expect($result->success)->toBeTrue()
        ->and($sql)->toContain('INSERT INTO `bookings` (`id`, `guest_name`, `starts_at`) VALUES')
        ->and($sql)->not->toContain('VIP')
        ->and($sql)->not->toContain('Alice Smith')
        ->and($sql)->not->toMatch('/`day` [^,\n]*NOT NULL/')
        ->and($result->tables[0]->notes[0])->toContain('generation expression was not replicated');

    @unlink($sourcePath);
});
