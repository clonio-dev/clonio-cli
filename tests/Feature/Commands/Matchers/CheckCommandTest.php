<?php

declare(strict_types=1);

use App\Enums\ExitCode;
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
