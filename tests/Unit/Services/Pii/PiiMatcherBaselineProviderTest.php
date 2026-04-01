<?php

declare(strict_types=1);

use App\Services\Pii\PiiMatcherBaselineProvider;

it('returns 6 groups', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    expect($groups)->toHaveCount(6);
});

it('returns the correct group keys', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $keys = array_map(fn ($g) => $g->key, $groups);

    expect($keys)->toBe([
        'personal_identity',
        'contact',
        'location',
        'financial',
        'authentication',
        'network',
    ]);
});

it('returns the correct total number of matchers', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $total = array_sum(array_map(fn ($g) => count($g->matchers), $groups));

    // 5 personal_identity + 3 contact + 6 location + 3 financial + 2 authentication + 1 network = 20
    expect($total)->toBe(20);
});

it('contains expected matchers in personal_identity group', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $personalIdentity = collect($groups)->firstWhere('key', 'personal_identity');
    expect($personalIdentity)->not->toBeNull();

    $matcherKeys = array_map(fn ($m) => $m->key, $personalIdentity->matchers);
    expect($matcherKeys)->toContain('first_name');
    expect($matcherKeys)->toContain('last_name');
    expect($matcherKeys)->toContain('full_name');
    expect($matcherKeys)->toContain('date_of_birth');
    expect($matcherKeys)->toContain('national_id');
});

it('contains expected matchers in contact group', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $contact = collect($groups)->firstWhere('key', 'contact');
    expect($contact)->not->toBeNull();

    $matcherKeys = array_map(fn ($m) => $m->key, $contact->matchers);
    expect($matcherKeys)->toContain('email_address');
    expect($matcherKeys)->toContain('phone_number');
    expect($matcherKeys)->toContain('username');
});

it('has api_token disabled by default', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $auth = collect($groups)->firstWhere('key', 'authentication');
    expect($auth)->not->toBeNull();

    $apiToken = collect($auth->matchers)->firstWhere('key', 'api_token');
    expect($apiToken)->not->toBeNull();
    expect($apiToken->enabled)->toBeFalse();
});

it('getMatcherSet returns a flat list in evaluation order', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    expect($matcherSet->matchers)->not->toBeEmpty();
    expect($matcherSet->matchers[0]->key)->toBe('first_name');
});

it('all baseline matchers have isBaseline true', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    foreach ($matcherSet->matchers as $matcher) {
        expect($matcher->isBaseline)->toBeTrue();
    }
});
