<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherSetData;
use App\Services\Pii\PiiMatcherBaselineProvider;

function makeTestMatcher(string $key, string $group, array $patterns, bool $enabled = true): PiiMatcherData
{
    return new PiiMatcherData(
        key: $key,
        group: $group,
        name: ucwords(str_replace('_', ' ', $key)),
        enabled: $enabled,
        patterns: $patterns,
        transformation: new ColumnCloningConfigData(
            columnName: $key,
            strategy: 'fake',
            fakerMethod: 'name',
            fakerArguments: [],
            hashAlgorithm: null,
            hashSalt: null,
            maskChar: null,
            visibleChars: null,
            preserveFormat: null,
            staticValue: null,
        ),
        isBaseline: true,
    );
}

it('matches email column to email_address matcher', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $result = $matcherSet->match('email');

    expect($result)->not->toBeNull();
    expect($result?->key)->toBe('email_address');
});

it('matches password column to password matcher', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $result = $matcherSet->match('password');

    expect($result)->not->toBeNull();
    expect($result?->key)->toBe('password');
});

it('returns null for created_at column', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $result = $matcherSet->match('created_at');

    expect($result)->toBeNull();
});

it('matches first_name column to first_name matcher', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $result = $matcherSet->match('first_name');

    expect($result)->not->toBeNull();
    expect($result?->key)->toBe('first_name');
});

it('does not match disabled api_token matcher', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    // api_token is disabled, so "token" should not match
    $result = $matcherSet->match('token');

    expect($result)->toBeNull();
});

it('matches glob pattern *_email', function (): void {
    $matcher = makeTestMatcher('email_address', 'contact', ['*_email']);
    $matcherSet = new PiiMatcherSetData([$matcher]);

    $result = $matcherSet->match('user_email');

    expect($result)->not->toBeNull();
    expect($result?->key)->toBe('email_address');
});

it('matches literal pattern case-insensitively', function (): void {
    $matcher = makeTestMatcher('reply_to', 'contact', ['reply_to']);
    $matcherSet = new PiiMatcherSetData([$matcher]);

    expect($matcherSet->match('reply_to'))->not->toBeNull();
    expect($matcherSet->match('REPLY_TO'))->not->toBeNull();
    expect($matcherSet->match('Reply_To'))->not->toBeNull();
});

it('does not match non-pii columns', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    expect($matcherSet->match('id'))->toBeNull();
    expect($matcherSet->match('created_at'))->toBeNull();
    expect($matcherSet->match('updated_at'))->toBeNull();
    expect($matcherSet->match('deleted_at'))->toBeNull();
});
