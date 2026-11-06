<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunOptionsData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;

function runOptionsConfig(bool $value): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'prod',
        options: new CloningOptionsData(
            chunkSize: 250,
            enforceColumnTypes: $value,
            dropUnknownTables: $value,
            dropExtraColumns: $value,
            disableForeignKeyChecks: $value,
            fakerLocale: 'de_DE',
            remapKeys: $value,
            recreateTables: $value,
        ),
        tables: [new TableCloningConfigData('users', new TableRowConfigData('full', null, null), [])],
        keyRemapping: new KeyRemappingConfigData([]),
        skipTables: ['logs'],
    );
}

it('gives defaults when no option is set', function (): void {
    $options = RunOptionsData::fromOptions('c.yaml', []);

    expect($options)->toEqual(new RunOptionsData(yamlPath: 'c.yaml'));
});

it('reads every flag and list', function (): void {
    $options = RunOptionsData::fromOptions('c.yaml', [
        'target' => 'staging', 'allow-failure' => true, 'dry-run' => true, 'ci' => true, 'skip-schema' => true,
        'break-on-failure' => true, 'no-memory-limit' => true, 'file-based' => true,
        'skip-tables' => ' users , ,orders', 'only-tables' => '', 'audit-channel' => 'a,b',
    ]);

    expect($options)->toEqual(new RunOptionsData(
        yamlPath: 'c.yaml',
        targetConnectionName: 'staging',
        allowFailure: true,
        dryRun: true,
        ci: true,
        skipSchema: true,
        breakOnFailure: true,
        noMemoryLimit: true,
        fileBased: true,
        skipTables: ['users', 'orders'],
        auditChannels: ['a', 'b'],
    ));
});

it('treats an empty or non-scalar target as missing', function (mixed $target): void {
    expect(RunOptionsData::fromOptions('c.yaml', ['target' => $target])->targetConnectionName)->toBeNull();
})->with(['empty' => '', 'null' => null, 'array' => [['x']]]);

it('casts a scalar list value to a string', function (): void {
    expect(RunOptionsData::fromOptions('c.yaml', ['only-tables' => 7])->onlyTables)->toBe(['7']);
});

it('sets an override from its flag', function (array $flags, string $field, bool $value): void {
    expect(RunOptionsData::fromOptions('c.yaml', $flags)->{$field})->toBe($value);
})->with([
    'enforce on' => [['enforce-column-types' => true], 'enforceColumnTypes', true],
    'enforce off' => [['no-enforce-column-types' => true], 'enforceColumnTypes', false],
    'drop tables on' => [['drop-unknown-tables' => true], 'dropUnknownTables', true],
    'drop tables off' => [['no-drop-unknown-tables' => true], 'dropUnknownTables', false],
    'drop columns on' => [['drop-extra-columns' => true], 'dropExtraColumns', true],
    'drop columns off' => [['no-drop-extra-columns' => true], 'dropExtraColumns', false],
    'recreate on' => [['recreate-tables' => true], 'recreateTables', true],
    'recreate off' => [['no-recreate-tables' => true], 'recreateTables', false],
    'fk on' => [['disable-fk-checks' => true], 'disableForeignKeyChecks', true],
    'fk off' => [['no-disable-fk-checks' => true], 'disableForeignKeyChecks', false],
    'remap on' => [['remap-keys' => true], 'remapKeys', true],
    'remap off' => [['no-remap-keys' => true], 'remapKeys', false],
    'remap alias off' => [['skip-remapping-keys' => true], 'remapKeys', false],
]);

it('remembers the flag that turned key remapping off', function (array $flags, ?string $flag): void {
    expect(RunOptionsData::fromOptions('c.yaml', $flags)->remapKeysDisabledBy)->toBe($flag);
})->with([
    'none' => [[], null],
    'on' => [['remap-keys' => true], null],
    'no-remap-keys' => [['no-remap-keys' => true], '--no-remap-keys'],
    'alias' => [['skip-remapping-keys' => true], '--skip-remapping-keys'],
    'both off flags' => [['no-remap-keys' => true, 'skip-remapping-keys' => true], '--no-remap-keys'],
    'false flag' => [['no-remap-keys' => false, 'skip-remapping-keys' => true], '--skip-remapping-keys'],
]);

it('rejects invalid options', function (array $options, string $message): void {
    expect(fn (): RunOptionsData => RunOptionsData::fromOptions('c.yaml', $options))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'skip and only' => [['skip-tables' => 'a', 'only-tables' => 'b'], '--skip-tables and --only-tables are mutually exclusive.'],
    'blank skip' => [['skip-tables' => ' , '], '--skip-tables needs at least one name.'],
    'blank audit' => [['audit-channel' => ','], '--audit-channel needs at least one name.'],
    'pattern' => [['only-tables' => 'users,/^logs_/'], '--only-tables takes table names, not patterns ("/^logs_/"). Write table patterns in cloning.yaml.'],
    'enforce' => [['enforce-column-types' => true, 'no-enforce-column-types' => true], '--enforce-column-types and --no-enforce-column-types are mutually exclusive.'],
    'remap alias' => [['remap-keys' => true, 'skip-remapping-keys' => true], '--remap-keys and --skip-remapping-keys are mutually exclusive.'],
    'remap both off' => [['remap-keys' => true, 'no-remap-keys' => true, 'skip-remapping-keys' => true], '--remap-keys and --no-remap-keys are mutually exclusive.'],
]);

it('keeps the config when no override is set', function (bool $value): void {
    $config = runOptionsConfig($value);

    expect(new RunOptionsData('c.yaml')->applyTo($config))->toEqual($config);
})->with([true, false]);

it('applies every override on top of the config', function (bool $value): void {
    $options = new RunOptionsData(
        yamlPath: 'c.yaml',
        enforceColumnTypes: ! $value,
        dropUnknownTables: ! $value,
        dropExtraColumns: ! $value,
        recreateTables: ! $value,
        disableForeignKeyChecks: ! $value,
        remapKeys: ! $value,
    );

    expect($options->applyTo(runOptionsConfig($value)))->toEqual(runOptionsConfig(! $value));
})->with([true, false]);
