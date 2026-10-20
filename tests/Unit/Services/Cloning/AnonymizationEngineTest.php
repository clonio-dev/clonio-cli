<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Services\Cloning\AnonymizationEngine;
use Faker\Generator;

function makeColumn(
    string $strategy,
    ?string $fakerMethod = null,
    ?string $staticValue = null,
    ?string $hashAlgorithm = null,
    ?string $hashSalt = null,
    ?string $maskChar = null,
    ?int $visibleChars = null,
    ?bool $preserveFormat = null,
    ?string $template = null,
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
        template: $template,
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

it('hashes value with sha256 using explicit salt', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('hash', hashAlgorithm: 'sha256', hashSalt: 'mysalt');
    $result = $engine->transform('testvalue', $col);
    expect($result)->toBe(hash('sha256', 'mysalttestvalue'));
});

it('applies per-run random salt when hash config has no salt', function (): void {
    // Two separate engines should produce different hashes for the same
    // value, because each generates its own random run salt. This is the
    // GDPR-aligned default: cross-run linkability defeated.
    $engineA = new AnonymizationEngine;
    $engineB = new AnonymizationEngine;
    $col = makeColumn('hash', hashAlgorithm: 'sha256', hashSalt: null);

    $resultA = $engineA->transform('testvalue', $col);
    $resultB = $engineB->transform('testvalue', $col);

    expect($resultA)->toBeString();
    expect($resultB)->toBeString();
    expect($resultA)->not->toBe($resultB);
});

it('produces the same hash for the same value within a single run', function (): void {
    // Within one engine (one run), the same source value must hash to the
    // same output, so foreign-key joins on hashed columns stay valid.
    $engine = new AnonymizationEngine;
    $col = makeColumn('hash', hashAlgorithm: 'sha256', hashSalt: null);

    $first = $engine->transform('user-42', $col);
    $second = $engine->transform('user-42', $col);

    expect($first)->toBe($second);
});

it('uses the explicit run salt when provided to the constructor', function (): void {
    $engine = new AnonymizationEngine('en_US', 'fixed-test-salt');
    $col = makeColumn('hash', hashAlgorithm: 'sha256', hashSalt: null);

    $result = $engine->transform('testvalue', $col);

    expect($result)->toBe(hash('sha256', 'fixed-test-salttestvalue'));
});

it('exposes the run salt via getRunSalt', function (): void {
    $engine = new AnonymizationEngine('en_US', 'fixed-test-salt');

    expect($engine->getRunSalt())->toBe('fixed-test-salt');
});

it('generates a random run salt when none is provided', function (): void {
    $engineA = new AnonymizationEngine;
    $engineB = new AnonymizationEngine;

    expect($engineA->getRunSalt())->not->toBe($engineB->getRunSalt());
    // 32 bytes hex-encoded → 64 chars
    expect(strlen($engineA->getRunSalt()))->toBe(64);
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

it('expands template placeholders with faker output', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: '{userName}@acme.test');
    $result = $engine->transform('alice@somewhere.com', $col);

    expect($result)->toBeString();
    expect($result)->toEndWith('@acme.test');
    expect($result)->not->toBe('{userName}@acme.test');
});

it('keeps literal text outside placeholders', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: 'static-prefix-{uuid}-suffix');
    $result = $engine->transform('input', $col);

    expect($result)->toBeString();
    expect($result)->toStartWith('static-prefix-');
    expect($result)->toEndWith('-suffix');
});

it('returns empty string when template is null or empty', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: null);
    expect($engine->transform('input', $col))->toBe('');

    $col = makeColumn('template', template: '');
    expect($engine->transform('input', $col))->toBe('');
});

it('expands multiple placeholders in one template', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: '{firstName}.{lastName}@firma.de');
    $result = $engine->transform('input', $col);

    expect($result)->toBeString();
    expect($result)->toEndWith('@firma.de');
    expect($result)->toContain('.');
});

it('joins array faker output into a space-delimited string', function (): void {
    $engine = new AnonymizationEngine;
    // words() returns an array of words → engine implodes them
    $col = makeColumn('fake', fakerMethod: 'words');

    $result = $engine->transform(null, $col);

    expect($result)->toBeString()
        ->and(trim($result))->not->toBe('')
        ->and($result)->toContain(' ');
});

it('expands a template using a real generator method returning an array', function (): void {
    $engine = new AnonymizationEngine;
    // getProviders() is a real Generator method (method_exists true) returning an
    // array → exercises the resolve-and-join branch of the template callback.
    $col = makeColumn('template', template: 'p:{getProviders}:end');

    $result = $engine->transform(null, $col);

    expect($result)->toBeString()
        ->and($result)->toStartWith('p:')
        ->and($result)->toEndWith(':end');
});

it('resolves a magic-formatter Faker token (firstName) instead of rendering it empty', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: 'str={firstName} num={randomNumber}');

    $result = $engine->transform('irrelevant', $col);

    expect($result)->not->toContain('str= ')
        ->and($result)->toMatch('/^str=\S+ num=\d+$/');
});

it('resolves each documented string Faker token used in a template', function (string $token): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: "[{{$token}}]");

    $result = $engine->transform('irrelevant', $col);

    expect($result)->not->toBe('[]')
        ->and($result)->not->toBe('['.']');
})->with([
    'firstName',
    'lastName',
    'name',
    'safeEmail',
    'city',
    'streetName',
    'word',
    'url',
    'uuid',
    'countryCode',
    'postcode',
    'buildingNumber',
    'e164PhoneNumber',
]);

it('renders unknown faker method as empty string in template', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: 'before-{thisMethodDoesNotExist}-after');
    $result = $engine->transform('input', $col);

    expect($result)->toBe('before--after');
});

it('produces a real userName in the {userName}@acme.test template, not just the literal suffix', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: '{userName}@acme.test');

    $result = $engine->transform('alice@somewhere.com', $col);

    expect($result)->toBeString();
    $localPart = explode('@', (string) $result)[0];
    expect($localPart)->not->toBe('');
});

it('produces real first and last names in a multi-token template, not just literal separators', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('template', template: '{firstName}.{lastName}@firma.de');

    $result = $engine->transform('input', $col);

    expect($result)->toBeString();
    [$first, $lastAndDomain] = explode('.', (string) $result, 2);
    expect($first)->not->toBe('');
    expect($lastAndDomain)->not->toStartWith('@');
});

it('returns null for the exclude strategy (column is dropped before transform)', function (): void {
    expect((new AnonymizationEngine)->transform('secret', makeColumn('exclude')))->toBeNull();
});

function g3_anon_engine_with_provider(): AnonymizationEngine
{
    $engine = new AnonymizationEngine(runSalt: 'run-salt');

    /** @var Generator $faker */
    $faker = (new ReflectionProperty($engine, 'faker'))->getValue($engine);
    $faker->addProvider(new class
    {
        /** @return list<mixed> */
        public function g3MixedList(): array
        {
            return [1, null, 'b', new stdClass];
        }

        public function g3Nothing(): ?string
        {
            return null;
        }
    });

    return $engine;
}

it('hashes non-string scalar values by their string form', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('hash', hashSalt: 's');

    expect($engine->transform(42, $col))->toBe(hash('sha256', 's42'))
        ->and($engine->transform(null, $col))->toBe(hash('sha256', 's'));
});

it('hashes with the configured algorithm', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('hash', hashAlgorithm: 'md5', hashSalt: 's');

    expect($engine->transform('value', $col))->toBe(md5('svalue'));
});

it('masks non-string scalar values and treats non-scalars as empty', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('mask', visibleChars: 2);

    expect($engine->transform(12345, $col))->toBe('12***')
        ->and($engine->transform(null, $col))->toBe('');
});

it('returns null when faker method is an empty string', function (): void {
    $engine = new AnonymizationEngine;

    expect($engine->transform('hello', makeColumn('fake', fakerMethod: '')))->toBeNull();
});

it('joins array faker output and renders non-scalar items as empty strings', function (): void {
    $engine = g3_anon_engine_with_provider();

    expect($engine->transform('x', makeColumn('fake', fakerMethod: 'g3MixedList')))->toBe('1  b ')
        ->and($engine->transform('x', makeColumn('template', template: '<{g3MixedList}>')))->toBe('<1  b >');
});

it('renders a non-scalar faker result as empty string in template', function (): void {
    $engine = g3_anon_engine_with_provider();

    expect($engine->transform('x', makeColumn('template', template: 'a{g3Nothing}b')))->toBe('ab');
});

it('masks every character when no mask options are configured', function (): void {
    $engine = new AnonymizationEngine;

    expect($engine->transform('a-bc', makeColumn('mask')))->toBe('****');
});

it('masks with the configured mask character', function (): void {
    $engine = new AnonymizationEngine;

    expect($engine->transform('secret', makeColumn('mask', maskChar: '#', visibleChars: 1)))->toBe('s#####');
});

it('preserves separators and reveals exactly the first visible alphanumerics', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('mask', maskChar: '*', visibleChars: 3, preserveFormat: true);

    expect($engine->transform('ab-cd.ef', $col))->toBe('ab-c*.**')
        ->and($engine->transform('hello@world.com', $col))->toBe('hel**@*****.***');
});

it('masks every alphanumeric when preserving format without visible chars', function (): void {
    $engine = new AnonymizationEngine;
    $col = makeColumn('mask', preserveFormat: true);

    expect($engine->transform('ab-c', $col))->toBe('**-*');
});
