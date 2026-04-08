<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnDumpData;
use App\Data\Cloning\DumpResultData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableDumpData;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\CloningYamlWriter;
use Illuminate\Support\Facades\Storage;

function makeDumpResult(string $connectionName = 'production-db', array $tables = []): DumpResultData
{
    return new DumpResultData(
        connectionName: $connectionName,
        tables: $tables,
        totalColumns: array_sum(array_map(fn (TableDumpData $t) => count($t->columns), $tables)),
        piiColumnsDetected: count(array_filter(
            array_merge(...array_map(fn (TableDumpData $t) => $t->columns, $tables ?: [])),
            fn (ColumnDumpData $c) => $c->piiDetected
        )),
        outputPath: $connectionName.'.cloning.yaml',
    );
}

function makeFakeColumn(string $name): ColumnDumpData
{
    return new ColumnDumpData(
        name: $name,
        strategy: 'fake',
        fakerMethod: 'safeEmail',
        fakerArguments: [],
        maskChar: null,
        visibleChars: null,
        preserveFormat: null,
        hashAlgorithm: null,
        hashSalt: null,
        staticValue: null,
        piiDetected: true,
        piiCategory: 'Email Address',
    );
}

function makeKeepColumn(string $name): ColumnDumpData
{
    return new ColumnDumpData(
        name: $name,
        strategy: 'keep',
        fakerMethod: null,
        fakerArguments: [],
        maskChar: null,
        visibleChars: null,
        preserveFormat: null,
        hashAlgorithm: null,
        hashSalt: null,
        staticValue: null,
        piiDetected: false,
        piiCategory: null,
    );
}

function makeHashColumn(string $name): ColumnDumpData
{
    return new ColumnDumpData(
        name: $name,
        strategy: 'hash',
        fakerMethod: null,
        fakerArguments: [],
        maskChar: null,
        visibleChars: null,
        preserveFormat: null,
        hashAlgorithm: 'sha256',
        hashSalt: 'mysalt',
        staticValue: null,
        piiDetected: true,
        piiCategory: 'Password / Secret',
    );
}

it('writes yaml language server comment at top', function (): void {
    $writer = new CloningYamlWriter;
    $result = makeDumpResult('prod', []);

    $yaml = $writer->write($result);

    expect($yaml)->toStartWith('# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json');
});

it('writes connection name', function (): void {
    $writer = new CloningYamlWriter;
    $result = makeDumpResult('my-connection', []);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('connection: my-connection');
});

it('writes version 1', function (): void {
    $writer = new CloningYamlWriter;
    $result = makeDumpResult('prod', []);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('version: "1"');
});

it('writes options block with all required fields', function (): void {
    $writer = new CloningYamlWriter;
    $result = makeDumpResult('prod', []);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('chunk_size: 1000');
    expect($yaml)->toContain('enforce_column_types: false');
    expect($yaml)->toContain('drop_unknown_tables: false');
    expect($yaml)->toContain('disable_foreign_key_checks: true');
    expect($yaml)->toContain('faker_locale: en_US');
});

it('writes fake strategy column with faker_method and arguments', function (): void {
    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'users',
        columns: [makeFakeColumn('email'), makeKeepColumn('id')],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('email:');
    expect($yaml)->toContain('strategy: fake');
    expect($yaml)->toContain('faker_method: safeEmail');
    expect($yaml)->toContain('faker_arguments: []');
    // keep columns should NOT appear
    expect($yaml)->not->toContain('id:');
});

it('writes hash strategy column with algorithm and salt', function (): void {
    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'users',
        columns: [makeHashColumn('password')],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('strategy: hash');
    expect($yaml)->toContain('algorithm: sha256');
    expect($yaml)->toContain('salt:');
});

it('writes PII comment above column when piiDetected is true', function (): void {
    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'users',
        columns: [makeFakeColumn('email')],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('# PII: Email Address');
});

it('writes no PII detected comment for table with all-keep columns', function (): void {
    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'orders',
        columns: [makeKeepColumn('id'), makeKeepColumn('total')],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('# no PII detected');
});

it('writes mask strategy column', function (): void {
    $writer = new CloningYamlWriter;
    $maskCol = new ColumnDumpData(
        name: 'phone',
        strategy: 'mask',
        fakerMethod: null,
        fakerArguments: [],
        maskChar: '*',
        visibleChars: 4,
        preserveFormat: true,
        hashAlgorithm: null,
        hashSalt: null,
        staticValue: null,
        piiDetected: true,
        piiCategory: 'Phone Number',
    );
    $table = new TableDumpData(
        name: 'contacts',
        columns: [$maskCol],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('strategy: mask');
    expect($yaml)->toContain('visible_chars: 4');
    expect($yaml)->toContain('mask_char: "*"');
    expect($yaml)->toContain('preserve_format: true');
});

it('writes static strategy column', function (): void {
    $writer = new CloningYamlWriter;
    $staticCol = new ColumnDumpData(
        name: 'status',
        strategy: 'static',
        fakerMethod: null,
        fakerArguments: [],
        maskChar: null,
        visibleChars: null,
        preserveFormat: null,
        hashAlgorithm: null,
        hashSalt: null,
        staticValue: 'anonymized',
        piiDetected: false,
        piiCategory: null,
    );
    $table = new TableDumpData(
        name: 'users',
        columns: [$staticCol],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('strategy: static');
    expect($yaml)->toContain('value: anonymized');
});

it('writes rows with limit when rowStrategy is first', function (): void {
    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'users',
        columns: [makeFakeColumn('email')],
        rowStrategy: 'first',
        rowLimit: 100,
        sortBy: null,
    );
    $result = makeDumpResult('prod', [$table]);

    $yaml = $writer->write($result);

    expect($yaml)->toContain('strategy: first');
    expect($yaml)->toContain('limit: 100');
});

it('writes to file using Storage facade', function (): void {
    Storage::fake('local');

    $writer = new CloningYamlWriter;
    $result = makeDumpResult('prod', []);

    $writer->writeToFile($result, 'output.yaml');

    expect(Storage::disk('local')->exists('output.yaml'))->toBeTrue();
    $content = Storage::disk('local')->get('output.yaml');
    expect($content)->toContain('connection: prod');
});

it('writes remapping strategy as inline column not key_remapping section', function (): void {
    Storage::fake('local');

    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'users',
        columns: [makeFakeColumn('email'), makeKeepColumn('id')],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $keyRemapping = new KeyRemappingConfigData(tables: [
        new KeyRemappingTableData(
            table: 'users',
            primaryKey: 'id',
            strategy: KeyRemappingStrategy::RandomInteger,
            rangeMin: 100000,
            rangeMax: 9999999,
            foreignKeys: [],
        ),
    ]);
    $result = new DumpResultData(
        connectionName: 'prod',
        tables: [$table],
        totalColumns: 2,
        piiColumnsDetected: 1,
        outputPath: 'prod.cloning.yaml',
        fakerLocale: 'en_US',
        keyRemapping: $keyRemapping,
    );

    $yaml = $writer->write($result);

    // Remapping must be inline in columns
    expect($yaml)->toContain('strategy: remapping');
    expect($yaml)->toContain('- use: random_integer');
    expect($yaml)->toContain('- min: 100000');
    expect($yaml)->toContain('- max: 9999999');
    expect($yaml)->toContain('- foreign_keys: []');
    // The old top-level section must NOT appear
    expect($yaml)->not->toContain('key_remapping:');
});

it('does not duplicate primary key column when key remapping is active', function (): void {
    $writer = new CloningYamlWriter;
    $table = new TableDumpData(
        name: 'users',
        columns: [makeKeepColumn('id'), makeFakeColumn('email')],
        rowStrategy: 'full',
        rowLimit: null,
        sortBy: null,
    );
    $keyRemapping = new KeyRemappingConfigData(tables: [
        new KeyRemappingTableData(
            table: 'users',
            primaryKey: 'id',
            strategy: KeyRemappingStrategy::RandomInteger,
            rangeMin: 100000,
            rangeMax: 9999999,
            foreignKeys: [],
        ),
    ]);
    $result = new DumpResultData(
        connectionName: 'prod',
        tables: [$table],
        totalColumns: 2,
        piiColumnsDetected: 1,
        outputPath: 'prod.cloning.yaml',
        fakerLocale: 'en_US',
        keyRemapping: $keyRemapping,
        includeKeepColumns: true,
    );

    $yaml = $writer->write($result);

    // The primary key should appear exactly once as a remapping entry
    expect(substr_count($yaml, 'id:'))->toBe(1)
        ->and($yaml)->toContain('strategy: remapping');
});
