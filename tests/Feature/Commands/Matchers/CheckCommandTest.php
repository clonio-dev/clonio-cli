<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Enums\ExitCode;
use App\Services\Cloning\AnonymizationEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('returns match info for email column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('email_address')
        ->expectsOutputToContain('contact')
        ->expectsOutputToContain('safeEmail')
        ->assertExitCode(ExitCode::Success->value);
});

it('returns no matcher found for created_at column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'created_at'])
        ->expectsOutputToContain('no matcher found')
        ->expectsOutputToContain('strategy: keep')
        ->assertExitCode(ExitCode::Success->value);
});

it('always exits with code 0', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'some_random_column_xyz'])
        ->assertExitCode(ExitCode::Success->value);
});

it('shows transformation details for a matched column', function (): void {
    Storage::fake('local');

    // employee_id keeps the hash strategy because it is often used as a
    // referential identifier inside the same run. The per-run random salt
    // applied by the engine defeats cross-run linkability.
    $this->artisan('matchers:check', ['column' => 'employee_id'])
        ->expectsOutputToContain('employee_id')
        ->expectsOutputToContain('hash')
        ->expectsOutputToContain('sha256')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows which pattern matched and its type', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('Matched by:')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows source as binary baseline when no file present', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('binary baseline')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows critical sensitivity for credit card column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'credit_card'])
        ->expectsOutputToContain('Sensitivity:    critical')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows high sensitivity for email column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('Sensitivity:    high')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows built-in example for credit card column', function (): void {
    Storage::fake('local');

    // credit_card is now anonymized via Faker (`creditCardNumber`), so the
    // generated output is randomized — assert only that the input is shown
    // and that Faker is the strategy used.
    $this->artisan('matchers:check', ['column' => 'credit_card'])
        ->expectsOutputToContain('Example:')
        ->expectsOutputToContain('Input:   4242424242424242')
        ->expectsOutputToContain('faker generates fresh data')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows built-in example for ip_address column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'ip'])
        ->expectsOutputToContain('Example:')
        ->expectsOutputToContain('Input:   192.168.1.100')
        ->expectsOutputToContain('faker generates fresh data')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows built-in example for iban column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'iban'])
        ->expectsOutputToContain('Example:')
        ->expectsOutputToContain('Input:   DE89370400440532013000')
        ->expectsOutputToContain('faker generates fresh data')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows static REDACTED output for password column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'password'])
        ->expectsOutputToContain('Example:')
        ->expectsOutputToContain('Input:   mysecretpassword')
        ->expectsOutputToContain('Output:  REDACTED')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows built-in example for fake strategy with faker note', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('Example:')
        ->expectsOutputToContain('Input:   john.doe@example.com')
        ->expectsOutputToContain('faker generates fresh data')
        ->assertExitCode(ExitCode::Success->value);
});

it('accepts a user-provided value and shows transformed output', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'credit_card', 'value' => '5555555555554444'])
        ->expectsOutputToContain('Input:   5555555555554444')
        ->expectsOutputToContain('faker generates fresh data')
        ->assertExitCode(ExitCode::Success->value);
});

it('applies user-provided value to hash strategy', function (): void {
    Storage::fake('local');

    // employee_id uses hash strategy with the engine's per-run random salt,
    // so the exact output is not deterministic across runs; assert only the
    // input echo and that the matcher details show a 64-char sha256 prefix.
    $this->artisan('matchers:check', ['column' => 'employee_id', 'value' => 'EMP-42'])
        ->expectsOutputToContain('Input:   EMP-42')
        ->expectsOutputToContain('Output:  ')
        ->expectsOutputToContain('algorithm:      sha256')
        ->assertExitCode(ExitCode::Success->value);
});

it('rejects empty value argument', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'credit_card', 'value' => '   '])
        ->expectsOutputToContain('cannot be empty')
        ->assertExitCode(ExitCode::Success->value);
});

it('rejects value that is too long', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'credit_card', 'value' => str_repeat('a', 10001)])
        ->expectsOutputToContain('too long')
        ->assertExitCode(ExitCode::Success->value);
});

it('rejects value with control characters', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'credit_card', 'value' => "bad\x00value"])
        ->expectsOutputToContain('control characters')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows disabled hint when column matches a disabled matcher', function (): void {
    Storage::fake('local');

    // "token" matches api_token which is disabled by default
    $this->artisan('matchers:check', ['column' => 'token'])
        ->expectsOutputToContain('matcher found but currently disabled')
        ->expectsOutputToContain('api_token')
        ->expectsOutputToContain('This matcher is disabled')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows disabled hint for blood_type column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'blood_type'])
        ->expectsOutputToContain('matcher found but currently disabled')
        ->expectsOutputToContain('blood_type')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows hint to pass a value when yaml matcher has no example', function (): void {
    Storage::fake('local');

    Storage::put('clonio.pii-matchers.yaml', <<<'YAML'
        version: "1"
        groups:
          custom:
            name: "Custom"
            matchers:
              my_field:
                name: "My Custom Field"
                enabled: true
                patterns:
                  - my_field
                transformation:
                  strategy: mask
                  visible_chars: 2
                  mask_char: "#"
        YAML);

    $this->artisan('matchers:check', ['column' => 'my_field'])
        ->expectsOutputToContain('pass a value as the 2nd argument')
        ->assertExitCode(ExitCode::Success->value);
});

function writeCheckMatchers(): void
{
    $yaml = <<<'YAML'
version: "1"
groups:
  custom:
    name: "Custom"
    matchers:
      ssn:
        name: "Social Security Number"
        enabled: true
        patterns:
          - "ssn_*"
        transformation:
          strategy: fake
          faker_method: numerify
          faker_arguments: []
      handle:
        name: "Handle"
        enabled: true
        patterns:
          - "/^handle$/i"
        transformation:
          strategy: template
          template: "{userName}"
      secret:
        name: "Secret"
        enabled: true
        patterns:
          - "/^secret$/i"
        transformation:
          strategy: "null"
YAML;
    Storage::disk('local')->put('clonio.pii-matchers.yaml', $yaml);
}

it('detects a glob pattern match and shows a fake example', function (): void {
    Storage::fake('local');
    writeCheckMatchers();

    $this->artisan('matchers:check', ['column' => 'ssn_number', 'value' => '123-45-6789'])
        ->expectsOutputToContain('glob')
        ->expectsOutputToContain('matched')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows template transformation details for a matched column', function (): void {
    Storage::fake('local');
    writeCheckMatchers();

    $this->artisan('matchers:check', ['column' => 'handle', 'value' => 'abc'])
        ->expectsOutputToContain('template:')
        ->assertExitCode(ExitCode::Success->value);
});

it('renders a null transformation output as (null)', function (): void {
    Storage::fake('local');
    writeCheckMatchers();

    $this->artisan('matchers:check', ['column' => 'secret', 'value' => 'topsecret'])
        ->expectsOutputToContain('(null)')
        ->assertExitCode(ExitCode::Success->value);
});

it('reports a load error for an invalid matchers file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', "groups: not-a-mapping\n");

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('Error loading matchers')
        ->assertExitCode(ExitCode::Success->value);
});

function g8_writeCheckFixture(string $path = 'clonio.pii-matchers.yaml'): void
{
    Storage::disk('local')->put($path, <<<'YAML'
version: "1"
groups:
  custom:
    name: "Custom"
    matchers:
      hs_md5:
        name: "Hash Md5"
        sensitivity: critical
        patterns: ["zzz", "aaa", "hs_col"]
        transformation:
          strategy: hash
          algorithm: md5
          salt: "pepper"
      hs_plain:
        name: "Hash Plain"
        sensitivity: low
        patterns: ["/^hs_plain$/i"]
        transformation:
          strategy: hash
      fk_args:
        name: "Fake Args"
        sensitivity: high
        patterns: ["fk_*"]
        transformation:
          strategy: fake
          faker_method: numerify
          faker_arguments: ["###"]
      fk_none:
        name: "Fake None"
        patterns: ["/^fakenone$/"]
        transformation:
          strategy: fake
      mk_full:
        name: "Mask Full"
        patterns: ["/^mk_full$/"]
        transformation:
          strategy: mask
          visible_chars: 2
          mask_char: "#"
          preserve_format: true
      mk_plain:
        name: "Mask Plain"
        patterns: ["/^mk_plain$/"]
        transformation:
          strategy: mask
      st_full:
        name: "Static Full"
        patterns: ["/^st_full$/"]
        transformation:
          strategy: static
          value: "FIXED"
      st_plain:
        name: "Static Plain"
        patterns: ["/^st_plain$/"]
        transformation:
          strategy: static
      tp_full:
        name: "Template Full"
        patterns: ["/^tp_full$/"]
        transformation:
          strategy: template
          template: "{a}"
      tp_plain:
        name: "Template Plain"
        patterns: ["/^tp_plain$/"]
        transformation:
          strategy: template
      dis:
        name: "Disabled One"
        enabled: false
        sensitivity: critical
        patterns: ["dis_col"]
        transformation:
          strategy: keep
YAML);
}

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function g8_check(array $params): array
{
    $exitCode = Artisan::call('matchers:check', $params);

    return [$exitCode, Artisan::output()];
}

/**
 * @param  list<string>  $lines
 */
function g8_lines(array $lines): string
{
    return implode("\n", $lines)."\n";
}

function g8_mockEngine(string $expectedInput, mixed $returns): void
{
    $engine = Mockery::mock(AnonymizationEngine::class);
    $engine->shouldReceive('transform')
        ->once()
        ->with($expectedInput, Mockery::type(ColumnCloningConfigData::class))
        ->andReturn($returns);
    app()->instance(AnonymizationEngine::class, $engine);
}

it('renders the exact output for a literal hash matcher with a user value', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();
    g8_mockEngine('abc', 'HASHED');

    [$exitCode, $output] = g8_check(['column' => 'hs_col', 'value' => 'abc']);

    expect($exitCode)->toBe(ExitCode::Success->value);
    expect($output)->toBe(g8_lines([
        '',
        '  Column "hs_col" matched:',
        '',
        '    Matcher:        hs_md5',
        '    Group:          custom',
        '    PII category:   "Hash Md5"',
        '    Sensitivity:    critical',
        '    Source:         clonio.pii-matchers.yaml',
        '    Matched by:     hs_col  (literal)',
        '',
        '    Transformation:',
        '      strategy:       hash',
        '      algorithm:      md5',
        '      salt:           "pepper"',
        '',
        '    Example:',
        '      Input:   abc',
        '      Output:  HASHED',
        '',
    ]));
});

it('renders hash defaults and regex pattern type without a value', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [, $output] = g8_check(['column' => 'hs_plain']);

    expect($output)->toBe(g8_lines([
        '',
        '  Column "hs_plain" matched:',
        '',
        '    Matcher:        hs_plain',
        '    Group:          custom',
        '    PII category:   "Hash Plain"',
        '    Sensitivity:    low',
        '    Source:         clonio.pii-matchers.yaml',
        '    Matched by:     /^hs_plain$/i  (regex)',
        '',
        '    Transformation:',
        '      strategy:       hash',
        '      algorithm:      sha256',
        '      salt:           ""',
        '',
        '    Example:',
        '      (pass a value as the 2nd argument to test the transformation)',
        '',
    ]));
});

it('renders fake details with arguments and the faker note', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();
    g8_mockEngine('999', '123');

    [, $output] = g8_check(['column' => 'fk_x', 'value' => '999']);

    expect($output)->toBe(g8_lines([
        '',
        '  Column "fk_x" matched:',
        '',
        '    Matcher:        fk_args',
        '    Group:          custom',
        '    PII category:   "Fake Args"',
        '    Sensitivity:    high',
        '    Source:         clonio.pii-matchers.yaml',
        '    Matched by:     fk_*  (glob)',
        '',
        '    Transformation:',
        '      strategy:       fake',
        '      faker_method:   numerify',
        '      faker_arguments: ["###"]',
        '',
        '    Example:',
        '      Input:   999',
        '      Output:  123  (faker generates fresh data — input value is not used)',
        '',
    ]));
});

it('renders fake defaults when no faker method is configured', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [, $output] = g8_check(['column' => 'fakenone']);

    expect($output)->toContain(g8_lines([
        '      strategy:       fake',
        '      faker_method:   ',
        '      faker_arguments: []',
        '',
        '    Example:',
    ]));
});

it('renders configured mask details', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [, $output] = g8_check(['column' => 'mk_full']);

    expect($output)->toContain(g8_lines([
        '      strategy:       mask',
        '      visible_chars:  2',
        '      mask_char:      "#"',
        '      preserve_format: true',
        '',
    ]));
});

it('renders mask defaults', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [, $output] = g8_check(['column' => 'mk_plain']);

    expect($output)->toContain(g8_lines([
        '      strategy:       mask',
        '      visible_chars:  0',
        '      mask_char:      "*"',
        '      preserve_format: false',
        '',
    ]));
});

it('renders static and template values and their defaults', function (string $column, string $line): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [, $output] = g8_check(['column' => $column]);

    expect($output)->toContain("\n".$line."\n\n    Example:\n");
})->with([
    'static' => ['st_full', '      value:          "FIXED"'],
    'static default' => ['st_plain', '      value:          ""'],
    'template' => ['tp_full', '      template:       "{a}"'],
    'template default' => ['tp_plain', '      template:       ""'],
]);

it('renders the exact output for a disabled matcher from the file', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [$exitCode, $output] = g8_check(['column' => 'dis_col']);

    expect($exitCode)->toBe(ExitCode::Success->value);
    expect($output)->toBe(g8_lines([
        '',
        '  Column "dis_col" — matcher found but currently disabled:',
        '',
        '    Matcher:        dis',
        '    Group:          custom',
        '    PII category:   "Disabled One"',
        '    Sensitivity:    critical',
        '    Source:         clonio.pii-matchers.yaml',
        '',
        '    This matcher is disabled. Enable it in clonio.pii-matchers.yaml to activate it.',
        '    The column will be treated as strategy: keep by cloning:dump until enabled.',
        '',
    ]));
});

it('renders the exact output when no matcher is found', function (): void {
    Storage::fake('local');

    [$exitCode, $output] = g8_check(['column' => 'created_at']);

    expect($exitCode)->toBe(ExitCode::Success->value);
    expect($output)->toBe(g8_lines([
        '',
        '  Column "created_at" — no matcher found',
        '',
        '  This column will be treated as strategy: keep by cloning:dump.',
        '',
    ]));
});

it('loads matchers from the --path option', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture('custom.yaml');

    [, $output] = g8_check(['column' => 'hs_col', '--path' => 'custom.yaml']);

    expect($output)->toContain('    Matcher:        hs_md5');
});

it('falls back to the default file when --path is empty', function (): void {
    Storage::fake('local');
    g8_writeCheckFixture();

    [, $output] = g8_check(['column' => 'hs_col', '--path' => '']);

    expect($output)->toContain('    Matcher:        hs_md5');
});

it('uses the built-in example when the value argument is an empty string', function (): void {
    Storage::fake('local');
    g8_mockEngine('john.doe@example.com', 'fake@example.net');

    [, $output] = g8_check(['column' => 'email', 'value' => '']);

    expect($output)->toContain(g8_lines([
        '    Example:',
        '      Input:   john.doe@example.com',
        '      Output:  fake@example.net  (faker generates fresh data — input value is not used)',
        '',
    ]));
    expect($output)->not->toContain('cannot be empty');
});

it('stops after an invalid user value without rendering matcher details', function (string $value, string $error): void {
    Storage::fake('local');

    [$exitCode, $output] = g8_check(['column' => 'credit_card', 'value' => $value]);

    expect($exitCode)->toBe(ExitCode::Success->value);
    expect($output)->toBe(g8_lines(['', $error]));
})->with([
    'whitespace' => ['   ', '  Value cannot be empty or whitespace only.'],
    'too long' => [str_repeat('a', 10001), '  Value is too long (max 10,000 characters).'],
    'control characters' => ["bad\x01value", '  Value contains invalid control characters.'],
]);

it('accepts a value of exactly 10,000 characters', function (): void {
    Storage::fake('local');
    $value = str_repeat('a', 10000);
    g8_mockEngine($value, 'X');

    [, $output] = g8_check(['column' => 'password', 'value' => $value]);

    expect($output)->toContain('      Input:   '.$value."\n      Output:  X\n");
    expect($output)->not->toContain('too long');
});

it('treats regex metacharacters in glob patterns literally when finding the matched pattern', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', <<<'YAML'
version: "1"
groups:
  custom:
    name: "Custom"
    matchers:
      dotted:
        name: "Dotted"
        patterns: ["user.name*", "userxname_first"]
        transformation:
          strategy: keep
YAML);

    [, $output] = g8_check(['column' => 'userxname_first']);

    expect($output)->toContain('    Matched by:     userxname_first  (literal)');
});
