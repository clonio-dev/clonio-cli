<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;
use App\Enums\PiiSensitivity;
use App\Services\Pii\PiiMatcherYamlReader;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Support\Facades\Storage;

function makeMatcher(string $key, ColumnCloningConfigData $transformation, array $patterns = ['col'], bool $enabled = true): PiiMatcherData
{
    return new PiiMatcherData(
        key: $key,
        group: 'g',
        name: ucfirst($key),
        enabled: $enabled,
        patterns: $patterns,
        transformation: $transformation,
        isBaseline: false,
        sensitivity: PiiSensitivity::Medium,
    );
}

function keepTransformation(string $strategy, array $overrides = []): ColumnCloningConfigData
{
    return new ColumnCloningConfigData(
        columnName: $overrides['columnName'] ?? 'col',
        strategy: $strategy,
        fakerMethod: $overrides['fakerMethod'] ?? null,
        fakerArguments: $overrides['fakerArguments'] ?? [],
        hashAlgorithm: $overrides['hashAlgorithm'] ?? null,
        hashSalt: $overrides['hashSalt'] ?? null,
        maskChar: $overrides['maskChar'] ?? null,
        visibleChars: $overrides['visibleChars'] ?? null,
        preserveFormat: $overrides['preserveFormat'] ?? null,
        staticValue: $overrides['staticValue'] ?? null,
        template: $overrides['template'] ?? null,
    );
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->writer = new PiiMatcherYamlWriter;
    $this->reader = new PiiMatcherYamlReader;
});

it('writes a header and version', function (): void {
    $group = new PiiMatcherGroupData('g', 'Group', [
        makeMatcher('col', keepTransformation('keep')),
    ]);

    $this->writer->write([$group], 'clonio.pii-matchers.yaml');

    $content = Storage::disk('local')->get('clonio.pii-matchers.yaml');
    expect($content)->toContain('yaml-language-server');
    expect($content)->toContain("version: '1'");
});

it('round-trips every transformation strategy', function (): void {
    $group = new PiiMatcherGroupData('g', 'Group', [
        makeMatcher('fk', keepTransformation('fake', ['fakerMethod' => 'safeEmail', 'fakerArguments' => ['a', 1]])),
        makeMatcher('hs', keepTransformation('hash', ['hashAlgorithm' => 'sha256', 'hashSalt' => 'pepper'])),
        makeMatcher('mk', keepTransformation('mask', ['visibleChars' => 4, 'maskChar' => '#', 'preserveFormat' => true])),
        makeMatcher('st', keepTransformation('static', ['staticValue' => 'fixed'])),
        makeMatcher('tp', keepTransformation('template', ['template' => 'x-{id}'])),
    ]);

    $this->writer->write([$group], 'out.yaml');

    $reread = $this->reader->read((string) Storage::disk('local')->get('out.yaml'));
    $matchers = $reread[0]->matchers;

    expect($matchers[0]->transformation->fakerMethod)->toBe('safeEmail');
    expect($matchers[0]->transformation->fakerArguments)->toBe(['a', 1]);
    expect($matchers[1]->transformation->hashAlgorithm)->toBe('sha256');
    expect($matchers[1]->transformation->hashSalt)->toBe('pepper');
    expect($matchers[2]->transformation->visibleChars)->toBe(4);
    expect($matchers[2]->transformation->maskChar)->toBe('#');
    expect($matchers[2]->transformation->preserveFormat)->toBeTrue();
    expect($matchers[3]->transformation->staticValue)->toBe('fixed');
    expect($matchers[4]->transformation->template)->toBe('x-{id}');
});

it('omits the salt key when no explicit salt is set', function (): void {
    $group = new PiiMatcherGroupData('g', 'Group', [
        makeMatcher('hs', keepTransformation('hash', ['hashAlgorithm' => 'sha256'])),
    ]);

    $this->writer->write([$group], 'out.yaml');

    $content = (string) Storage::disk('local')->get('out.yaml');
    expect($content)->toContain('algorithm: sha256');
    expect($content)->not->toContain('salt:');
});

it('omits the patterns key when a matcher has no patterns', function (): void {
    $group = new PiiMatcherGroupData('g', 'Group', [
        makeMatcher('col', keepTransformation('keep'), patterns: []),
    ]);

    $this->writer->write([$group], 'out.yaml');

    $content = (string) Storage::disk('local')->get('out.yaml');
    expect($content)->not->toContain('patterns:');
});

it('falls back to empty strings for missing static value and template', function (): void {
    $group = new PiiMatcherGroupData('g', 'Group', [
        makeMatcher('st', keepTransformation('static')),
        makeMatcher('tp', keepTransformation('template')),
    ]);

    $this->writer->write([$group], 'out.yaml');

    $content = (string) Storage::disk('local')->get('out.yaml');
    expect($content)->toContain("value: ''");
    expect($content)->toContain("template: ''");
});
