<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\DumpOptionsData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Enums\ClearMode;

it('CloningOptionsData stores all fields correctly', function (): void {
    $opts = new CloningOptionsData(
        chunkSize: 500,
        enforceColumnTypes: true,
        dropUnknownTables: false,
        dropExtraColumns: false,
        disableForeignKeyChecks: true,
        fakerLocale: 'de_DE',
    );

    expect($opts->chunkSize)->toBe(500);
    expect($opts->enforceColumnTypes)->toBeTrue();
    expect($opts->dropUnknownTables)->toBeFalse();
    expect($opts->disableForeignKeyChecks)->toBeTrue();
    expect($opts->fakerLocale)->toBe('de_DE');
});

it('TableRowConfigData stores all fields correctly', function (): void {
    $rows = new TableRowConfigData(
        strategy: 'first',
        limit: 100,
        sortBy: 'created_at',
    );

    expect($rows->strategy)->toBe('first');
    expect($rows->limit)->toBe(100);
    expect($rows->sortBy)->toBe('created_at');
    expect($rows->clear)->toBe(ClearMode::None);
});

it('TableRowConfigData stores clear as truncate', function (): void {
    $rows = new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::Truncate);

    expect($rows->clear)->toBe(ClearMode::Truncate);
});

it('TableRowConfigData stores clear as delete', function (): void {
    $rows = new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::Delete);

    expect($rows->clear)->toBe(ClearMode::Delete);
});

it('TableCloningConfigData getColumn returns the correct column', function (): void {
    $col = new ColumnCloningConfigData(
        columnName: 'email',
        strategy: 'fake',
        fakerMethod: 'safeEmail',
        fakerArguments: [],
        hashAlgorithm: null,
        hashSalt: null,
        maskChar: null,
        visibleChars: null,
        preserveFormat: null,
        staticValue: null,
    );

    $table = new TableCloningConfigData(
        tableName: 'users',
        rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null),
        columns: [$col],
    );

    expect($table->getColumn('email'))->toBe($col);
    expect($table->getColumn('nonexistent'))->toBeNull();
});

it('CloningConfigData getTable returns the correct table', function (): void {
    $table = new TableCloningConfigData(
        tableName: 'users',
        rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null),
        columns: [],
    );

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'prod',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: true,
            fakerLocale: 'en_US',
        ),
        tables: [$table],
    );

    expect($config->getTable('users'))->toBe($table);
    expect($config->getTable('orders'))->toBeNull();
});

it('DumpOptionsData stores all fields correctly', function (): void {
    $opts = new DumpOptionsData(
        connectionName: 'prod',
        outputPath: 'prod.cloning.yaml',
        force: true,
        onlyPii: false,
        fakerLocale: 'en_US',
    );

    expect($opts->connectionName)->toBe('prod');
    expect($opts->outputPath)->toBe('prod.cloning.yaml');
    expect($opts->force)->toBeTrue();
    expect($opts->onlyPii)->toBeFalse();
    expect($opts->fakerLocale)->toBe('en_US');
});
