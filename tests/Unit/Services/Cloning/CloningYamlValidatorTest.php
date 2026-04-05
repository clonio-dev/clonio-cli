<?php

declare(strict_types=1);

use App\Services\Cloning\CloningYamlValidator;

function makeValidConfig(): array
{
    return [
        'version' => '1',
        'connection' => 'production-db',
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
                    'email' => [
                        'strategy' => 'fake',
                        'faker_method' => 'safeEmail',
                        'faker_arguments' => [],
                    ],
                ],
            ],
        ],
    ];
}

it('passes validation for a valid config', function (): void {
    $validator = new CloningYamlValidator;
    $errors = $validator->validate(makeValidConfig());

    expect($errors)->toBe([]);
});

it('returns error for missing version field', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    unset($config['version']);

    $errors = $validator->validate($config);

    expect($errors)->toContain("Missing required field: 'version'");
});

it('returns error for missing connection field', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    unset($config['connection']);

    $errors = $validator->validate($config);

    expect($errors)->toContain("Missing required field: 'connection'");
});

it('returns error for missing options field', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    unset($config['options']);

    $errors = $validator->validate($config);

    expect($errors)->toContain("Missing required field: 'options'");
});

it('returns error for missing tables field', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    unset($config['tables']);

    $errors = $validator->validate($config);

    expect($errors)->toContain("Missing required field: 'tables'");
});

it('returns error when version is not 1', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['version'] = '2';

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('version');
});

it('returns error when connection name has invalid characters', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['connection'] = 'My Connection!';

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('connection');
});

it('returns error when tables is empty', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables'] = [];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('tables');
});

it('returns error for invalid row strategy', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows']['strategy'] = 'random';

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('strategy');
});

it('returns error when first strategy has no limit', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows'] = ['strategy' => 'first'];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('limit');
});

it('returns error when last strategy has limit less than 1', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows'] = ['strategy' => 'last', 'limit' => 0];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('limit');
});

it('passes when first strategy has a valid limit', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows'] = ['strategy' => 'first', 'limit' => 100];

    $errors = $validator->validate($config);

    expect($errors)->toBe([]);
});

it('returns error for invalid column strategy', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['email']['strategy'] = 'encrypt';

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('strategy');
});

it('returns error when fake strategy has no faker_method', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    unset($config['tables']['users']['columns']['email']['faker_method']);

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('faker_method');
});

it('returns error when fake strategy has no faker_arguments', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    unset($config['tables']['users']['columns']['email']['faker_arguments']);

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('faker_arguments');
});

it('returns error when hash strategy has invalid algorithm', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['password'] = [
        'strategy' => 'hash',
        'algorithm' => 'md2',
        'salt' => 'abc',
    ];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('algorithm');
});

it('returns error when hash strategy has no salt', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['password'] = [
        'strategy' => 'hash',
        'algorithm' => 'sha256',
    ];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('salt');
});

it('passes a valid hash column', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['password'] = [
        'strategy' => 'hash',
        'algorithm' => 'sha256',
        'salt' => 'mysalt',
    ];

    $errors = $validator->validate($config);

    expect($errors)->toBe([]);
});

it('returns error when mask strategy is missing required fields', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['phone'] = [
        'strategy' => 'mask',
    ];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('mask');
});

it('returns error when static strategy has no value', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['status'] = [
        'strategy' => 'static',
    ];

    $errors = $validator->validate($config);

    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('value');
});

it('passes a valid static column', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['status'] = [
        'strategy' => 'static',
        'value' => 'active',
    ];

    $errors = $validator->validate($config);

    expect($errors)->toBe([]);
});

// ─── key_remapping validation ─────────────────────────────────────────────────

it('passes validation for a valid key_remapping section with new_uuid strategy', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            [
                'table' => 'users',
                'primary_key' => 'id',
                'strategy' => 'new_uuid',
            ],
        ],
    ];

    $errors = $validator->validate($config);
    expect($errors)->toBe([]);
});

it('passes validation for a valid key_remapping section with random_integer strategy', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            [
                'table' => 'users',
                'primary_key' => 'id',
                'strategy' => 'random_integer',
                'range_min' => 100000,
                'range_max' => 9999999,
            ],
        ],
    ];

    $errors = $validator->validate($config);
    expect($errors)->toBe([]);
});

it('passes when key_remapping tables is empty', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = ['tables' => []];

    $errors = $validator->validate($config);
    expect($errors)->toBe([]);
});

it('returns error when key_remapping tables is not an array', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = ['tables' => 'not-an-array'];

    $errors = $validator->validate($config);
    expect($errors)->toContain("Field 'key_remapping.tables' must be a list");
});

it('returns error for missing table name in key_remapping entry', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            ['primary_key' => 'id', 'strategy' => 'new_uuid'],
        ],
    ];

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain("'table' is required");
});

it('returns error when table is not in the tables section', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            ['table' => 'unknown_table', 'primary_key' => 'id', 'strategy' => 'new_uuid'],
        ],
    ];

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain("not defined in the 'tables' section");
});

it('returns error for duplicate table in key_remapping', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            ['table' => 'users', 'primary_key' => 'id', 'strategy' => 'new_uuid'],
            ['table' => 'users', 'primary_key' => 'id', 'strategy' => 'new_uuid'],
        ],
    ];

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain('duplicate table');
});

it('returns error for invalid strategy in key_remapping', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            ['table' => 'users', 'primary_key' => 'id', 'strategy' => 'invalid_strategy'],
        ],
    ];

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain("'strategy' must be one of");
});

it('returns error when range_min >= range_max for random_integer strategy', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            [
                'table' => 'users',
                'primary_key' => 'id',
                'strategy' => 'random_integer',
                'range_min' => 9999999,
                'range_max' => 100000,
            ],
        ],
    ];

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain("'range_min' must be less than 'range_max'");
});

it('passes with valid foreign_keys in key_remapping', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            [
                'table' => 'users',
                'primary_key' => 'id',
                'strategy' => 'new_uuid',
                'foreign_keys' => [
                    ['table' => 'orders', 'column' => 'user_id'],
                ],
            ],
        ],
    ];

    $errors = $validator->validate($config);
    expect($errors)->toBe([]);
});

it('returns error when foreign_key is missing table', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['key_remapping'] = [
        'tables' => [
            [
                'table' => 'users',
                'primary_key' => 'id',
                'strategy' => 'new_uuid',
                'foreign_keys' => [
                    ['column' => 'user_id'],
                ],
            ],
        ],
    ];

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain("'table' is required");
});

it('passes validation when clear is false', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows']['clear'] = false;

    expect($validator->validate($config))->toBe([]);
});

it('passes validation when clear is truncate', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows']['clear'] = 'truncate';

    expect($validator->validate($config))->toBe([]);
});

it('passes validation when clear is delete', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows']['clear'] = 'delete';

    expect($validator->validate($config))->toBe([]);
});

it('returns error when clear has an invalid value', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['rows']['clear'] = 'drop';

    $errors = $validator->validate($config);
    expect(implode(' ', $errors))->toContain('rows.clear');
});

// ─── remapping column strategy validation ────────────────────────────────────

it('passes validation for a valid remapping column with random_integer', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['id'] = [
        'strategy' => 'remapping',
        'arguments' => [
            ['use' => 'random_integer'],
            ['min' => 100000],
            ['max' => 9999999],
            ['foreign_keys' => []],
        ],
    ];

    $errors = $validator->validate($config);
    expect($errors)->toBe([]);
});

it('returns error when remapping strategy has no arguments', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['id'] = [
        'strategy' => 'remapping',
    ];

    $errors = $validator->validate($config);
    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain("'remapping' strategy requires 'arguments' list");
});

it('returns error when remapping strategy has no use argument', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['id'] = [
        'strategy' => 'remapping',
        'arguments' => [
            ['min' => 100000],
            ['max' => 9999999],
        ],
    ];

    $errors = $validator->validate($config);
    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain("'remapping' requires argument 'use'");
});

it('returns error when remapping random_integer has min greater than or equal to max', function (): void {
    $validator = new CloningYamlValidator;
    $config = makeValidConfig();
    $config['tables']['users']['columns']['id'] = [
        'strategy' => 'remapping',
        'arguments' => [
            ['use' => 'random_integer'],
            ['min' => 9999999],
            ['max' => 100000],
        ],
    ];

    $errors = $validator->validate($config);
    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain("'min' must be less than 'max'");
});

it('accepts drop_extra_columns as optional boolean when present', function (): void {
    $data = [
        'version' => '1',
        'connection' => 'production-db',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'drop_extra_columns' => true,
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => ['users' => ['rows' => ['strategy' => 'full']]],
    ];

    $errors = (new CloningYamlValidator)->validate($data);

    expect($errors)->toBe([]);
});

it('rejects drop_extra_columns when not a boolean', function (): void {
    $data = [
        'version' => '1',
        'connection' => 'production-db',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'drop_extra_columns' => 'yes',
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => ['users' => ['rows' => ['strategy' => 'full']]],
    ];

    $errors = (new CloningYamlValidator)->validate($data);

    expect($errors)->toContain("Field 'options.drop_extra_columns' must be a boolean");
});
