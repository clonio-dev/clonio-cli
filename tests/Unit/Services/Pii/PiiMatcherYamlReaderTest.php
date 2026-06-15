<?php

declare(strict_types=1);

use App\Enums\PiiSensitivity;
use App\Services\Pii\PiiMatcherYamlReader;

beforeEach(function (): void {
    $this->reader = new PiiMatcherYamlReader;
});

it('parses a full matcher with every transformation field', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  personal_identity:
    name: "Personal Identity"
    matchers:
      email:
        name: "Email Address"
        sensitivity: high
        enabled: true
        patterns:
          - email
          - "*_email"
        transformation:
          strategy: fake
          faker_method: safeEmail
          faker_arguments: []
YAML;

    $groups = $this->reader->read($yaml);

    expect($groups)->toHaveCount(1);
    expect($groups[0]->key)->toBe('personal_identity');
    expect($groups[0]->name)->toBe('Personal Identity');
    expect($groups[0]->matchers)->toHaveCount(1);

    $matcher = $groups[0]->matchers[0];
    expect($matcher->key)->toBe('email');
    expect($matcher->group)->toBe('personal_identity');
    expect($matcher->name)->toBe('Email Address');
    expect($matcher->enabled)->toBeTrue();
    expect($matcher->isBaseline)->toBeFalse();
    expect($matcher->sensitivity)->toBe(PiiSensitivity::High);
    expect($matcher->patterns)->toBe(['email', '*_email']);
    expect($matcher->transformation->strategy)->toBe('fake');
    expect($matcher->transformation->fakerMethod)->toBe('safeEmail');
});

it('parses hash, mask, static and template transformation fields', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  g:
    name: "Group"
    matchers:
      password:
        name: "Password"
        transformation:
          strategy: hash
          algorithm: sha256
          salt: "pepper"
      ssn:
        name: "SSN"
        transformation:
          strategy: mask
          visible_chars: 4
          mask_char: "#"
          preserve_format: true
      role:
        name: "Role"
        transformation:
          strategy: static
          value: "user"
      handle:
        name: "Handle"
        transformation:
          strategy: template
          template: "user-{id}"
YAML;

    $matchers = $this->reader->read($yaml)[0]->matchers;

    $hash = $matchers[0]->transformation;
    expect($hash->strategy)->toBe('hash');
    expect($hash->hashAlgorithm)->toBe('sha256');
    expect($hash->hashSalt)->toBe('pepper');

    $mask = $matchers[1]->transformation;
    expect($mask->strategy)->toBe('mask');
    expect($mask->visibleChars)->toBe(4);
    expect($mask->maskChar)->toBe('#');
    expect($mask->preserveFormat)->toBeTrue();

    expect($matchers[2]->transformation->staticValue)->toBe('user');
    expect($matchers[3]->transformation->template)->toBe('user-{id}');
});

it('defaults enabled to true and sensitivity to medium when omitted', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  g:
    name: "Group"
    matchers:
      x:
        name: "X"
        transformation:
          strategy: keep
YAML;

    $matcher = $this->reader->read($yaml)[0]->matchers[0];

    expect($matcher->enabled)->toBeTrue();
    expect($matcher->sensitivity)->toBe(PiiSensitivity::Medium);
});

it('falls back to medium for an unknown sensitivity value', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  g:
    name: "Group"
    matchers:
      x:
        name: "X"
        sensitivity: bogus
        transformation:
          strategy: keep
YAML;

    $matcher = $this->reader->read($yaml)[0]->matchers[0];

    expect($matcher->sensitivity)->toBe(PiiSensitivity::Medium);
});

it('filters out non-string patterns', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  g:
    name: "Group"
    matchers:
      x:
        name: "X"
        patterns:
          - keep_me
          - 42
          - true
        transformation:
          strategy: keep
YAML;

    $matcher = $this->reader->read($yaml)[0]->matchers[0];

    expect($matcher->patterns)->toBe(['keep_me']);
});

it('returns a default keep transformation when transformation is missing', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  g:
    name: "Group"
    matchers:
      x:
        name: "X"
        enabled: false
YAML;

    $matcher = $this->reader->read($yaml)[0]->matchers[0];

    expect($matcher->enabled)->toBeFalse();
    expect($matcher->transformation->strategy)->toBe('keep');
    expect($matcher->transformation->columnName)->toBe('x');
    expect($matcher->transformation->fakerMethod)->toBeNull();
});

it('throws on invalid YAML', function (): void {
    $this->reader->read("groups:\n  - [unbalanced");
})->throws(RuntimeException::class, 'Failed to parse');

it('throws when the top level is not a mapping', function (): void {
    $this->reader->read('just a scalar string');
})->throws(RuntimeException::class, 'must be a YAML mapping');

it('throws when the groups key is missing', function (): void {
    $this->reader->read("version: \"1\"\n");
})->throws(RuntimeException::class, 'must contain a "groups" key');

it('throws when a group is not a mapping', function (): void {
    $yaml = <<<'YAML'
groups:
  g: "not a mapping"
YAML;

    $this->reader->read($yaml);
})->throws(RuntimeException::class, 'must be a mapping');

it('throws when a group has no name', function (): void {
    $yaml = <<<'YAML'
groups:
  g:
    matchers: {}
YAML;

    $this->reader->read($yaml);
})->throws(RuntimeException::class, 'must have a non-empty "name" field');

it('throws when a group has no matchers key', function (): void {
    $yaml = <<<'YAML'
groups:
  g:
    name: "Group"
YAML;

    $this->reader->read($yaml);
})->throws(RuntimeException::class, 'must have a "matchers" key');

it('throws when a matcher is not a mapping', function (): void {
    $yaml = <<<'YAML'
groups:
  g:
    name: "Group"
    matchers:
      x: "not a mapping"
YAML;

    $this->reader->read($yaml);
})->throws(RuntimeException::class, 'must be a mapping');

it('throws when a matcher has no name', function (): void {
    $yaml = <<<'YAML'
groups:
  g:
    name: "Group"
    matchers:
      x:
        enabled: true
YAML;

    $this->reader->read($yaml);
})->throws(RuntimeException::class, 'must have a non-empty "name" field');

it('throws when a transformation strategy is not a string', function (): void {
    $yaml = <<<'YAML'
groups:
  g:
    name: "Group"
    matchers:
      x:
        name: "X"
        transformation:
          strategy: [1, 2]
YAML;

    $this->reader->read($yaml);
})->throws(RuntimeException::class, 'must have a string "strategy"');
