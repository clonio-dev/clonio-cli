<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Services\Cloning\AnonymizationEngine;

function makeColumn(
    string $strategy,
    ?string $fakerMethod = null,
    ?string $staticValue = null,
    ?string $hashAlgorithm = null,
    ?string $hashSalt = null,
    ?string $maskChar = null,
    ?int $visibleChars = null,
    ?bool $preserveFormat = null,
): ColumnCloningConfigData {
    return new ColumnCloningConfigData(
        columnName: 'test_col',
        strategy: $strategy,
        fakerMethod: $fakerMethod,
        fakerArguments: [],
        hashAlgorithm: $hashAlgorithm,
        hashSalt: $hashSalt,
        maskChar: $maskChar,
        visibleChars: $visibleChars,
        preserveFormat: $preserveFormat,
        staticValue: $staticValue,
    );
}

it('keeps value when strategy is keep', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('keep');
    expect($engine->transform('hello', $col))->toBe('hello');
});

it('returns null when strategy is null', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('null');
    expect($engine->transform('hello', $col))->toBeNull();
});

it('returns static value when strategy is static', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('static', staticValue: 'REDACTED');
    expect($engine->transform('hello', $col))->toBe('REDACTED');
});

it('hashes value with sha256', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('hash', hashAlgorithm: 'sha256', hashSalt: 'mysalt');
    $result = $engine->transform('testvalue', $col);
    expect($result)->toBe(hash('sha256', 'mysalttestvalue'));
});

it('masks value with default settings', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('mask', maskChar: '*', visibleChars: 2, preserveFormat: false);
    $result = $engine->transform('hello@world.com', $col);
    expect($result)->toBe('he*************');
});

it('masks value with preserve_format', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('mask', maskChar: '*', visibleChars: 2, preserveFormat: true);
    $result = $engine->transform('hello@world.com', $col);
    // Keeps structural chars (@, .), masks alphanumeric after first 2
    expect($result)->toBeString();
    expect($result)->toContain('@');
});

it('applies fake strategy with faker method', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('fake', fakerMethod: 'safeEmail');
    $result = $engine->transform('test@example.com', $col);
    expect($result)->toBeString();
    expect($result)->toContain('@');
});

it('returns value as-is for unknown strategy', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('unknown_strategy');
    expect($engine->transform('hello', $col))->toBe('hello');
});

it('returns null when faker method is null', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('fake', fakerMethod: null);
    expect($engine->transform('hello', $col))->toBeNull();
});

it('does not mask when value is shorter than visible chars', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('mask', maskChar: '*', visibleChars: 100, preserveFormat: false);
    expect($engine->transform('hi', $col))->toBe('hi');
});
