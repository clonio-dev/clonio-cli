<?php

declare(strict_types=1);

use App\Enums\PiiSensitivity;
use App\Services\Pii\PiiMatcherBaselineProvider;

it('returns 10 groups', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    expect($groups)->toHaveCount(10);
});

it('returns the correct group keys in order', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $keys = array_map(fn ($g) => $g->key, $groups);

    expect($keys)->toBe([
        'government_ids',
        'personal_identity',
        'contact',
        'location',
        'financial',
        'medical',
        'biometric',
        'professional',
        'digital_identity',
        'authentication',
    ]);
});

it('returns at least 47 matchers in total', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $total = array_sum(array_map(fn ($g) => count($g->matchers), $groups));

    expect($total)->toBeGreaterThanOrEqual(47);
});

it('contains expected matchers in government_ids group', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $govIds = collect($groups)->firstWhere('key', 'government_ids');
    expect($govIds)->not->toBeNull();

    $matcherKeys = array_map(fn ($m) => $m->key, $govIds->matchers);
    expect($matcherKeys)->toContain('national_id');
    expect($matcherKeys)->toContain('passport_number');
    expect($matcherKeys)->toContain('drivers_license');
    expect($matcherKeys)->toContain('tax_id');
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
    expect($matcherKeys)->toContain('gender');
    expect($matcherKeys)->toContain('nationality');
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

it('contains medical and biometric groups', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $groupKeys = array_map(fn ($g) => $g->key, $groups);
    expect($groupKeys)->toContain('medical');
    expect($groupKeys)->toContain('biometric');
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

it('has race_ethnicity and religion disabled by default', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $groups = $provider->getGroups();

    $personal = collect($groups)->firstWhere('key', 'personal_identity');
    expect($personal)->not->toBeNull();

    $race = collect($personal->matchers)->firstWhere('key', 'race_ethnicity');
    expect($race)->not->toBeNull();
    expect($race->enabled)->toBeFalse();

    $religion = collect($personal->matchers)->firstWhere('key', 'religion');
    expect($religion)->not->toBeNull();
    expect($religion->enabled)->toBeFalse();
});

it('assigns critical sensitivity to financial and government matchers', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $creditCard = collect($matcherSet->matchers)->firstWhere('key', 'credit_card');
    expect($creditCard?->sensitivity)->toBe(PiiSensitivity::Critical);

    $nationalId = collect($matcherSet->matchers)->firstWhere('key', 'national_id');
    expect($nationalId?->sensitivity)->toBe(PiiSensitivity::Critical);

    $password = collect($matcherSet->matchers)->firstWhere('key', 'password');
    expect($password?->sensitivity)->toBe(PiiSensitivity::Critical);
});

it('assigns high sensitivity to direct personal identifiers', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $email = collect($matcherSet->matchers)->firstWhere('key', 'email_address');
    expect($email?->sensitivity)->toBe(PiiSensitivity::High);

    $firstName = collect($matcherSet->matchers)->firstWhere('key', 'first_name');
    expect($firstName?->sensitivity)->toBe(PiiSensitivity::High);
});

it('assigns medium or low sensitivity to indirect identifiers', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    $ipAddress = collect($matcherSet->matchers)->firstWhere('key', 'ip_address');
    expect($ipAddress?->sensitivity)->toBe(PiiSensitivity::Medium);

    $city = collect($matcherSet->matchers)->firstWhere('key', 'city');
    expect($city?->sensitivity)->toBe(PiiSensitivity::Low);
});

it('getMatcherSet returns a flat list in evaluation order starting with government_ids', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    expect($matcherSet->matchers)->not->toBeEmpty();
    expect($matcherSet->matchers[0]->key)->toBe('national_id');
});

it('all baseline matchers have isBaseline true', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    foreach ($matcherSet->matchers as $matcher) {
        expect($matcher->isBaseline)->toBeTrue();
    }
});

it('all baseline matchers have an example value', function (): void {
    $provider = new PiiMatcherBaselineProvider;
    $matcherSet = $provider->getMatcherSet();

    foreach ($matcherSet->matchers as $matcher) {
        expect($matcher->exampleValue)->not->toBeNull(
            "Matcher '{$matcher->key}' is missing an exampleValue"
        );
    }
});
