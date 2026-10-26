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

it('returns the exact baseline matcher definitions', function (): void {
    $groups = (new PiiMatcherBaselineProvider)->getGroups();

    $actual = array_map(fn ($g): array => [$g->key, $g->name, array_map(function ($m): array {
        $t = $m->transformation;

        return [
            $m->key, $m->group, $m->name, $m->sensitivity->value, $m->enabled, $m->isBaseline, $m->patterns, $m->exampleValue,
            [$t->columnName, $t->strategy, $t->fakerMethod, $t->fakerArguments, $t->hashAlgorithm, $t->hashSalt, $t->maskChar, $t->visibleChars, $t->preserveFormat, $t->staticValue, $t->template, $t->remappingUse, $t->remappingForeignKeys],
        ];
    }, $g->matchers)], $groups);

    // Snapshot of the full baseline: [groupKey, groupName, [[key, group, name, sensitivity, enabled,
    // isBaseline, patterns, exampleValue, [columnName, strategy, fakerMethod, fakerArguments,
    // hashAlgorithm, hashSalt, maskChar, visibleChars, preserveFormat, staticValue, template,
    // remappingUse, remappingForeignKeys]], ...]]
    expect($actual)->toBe(
        [
            ['government_ids', 'Government-Issued Identifiers', [
                ['national_id', 'government_ids', 'National ID / SSN', 'critical', true, true, ['/^(ssn|social[-_]?security|national[-_]?id|personal[-_]?id)$/i'], '123-45-6789', ['', 'fake', 'numerify', ['###-##-####'], null, null, null, null, null, null, null, null, null]],
                ['passport_number', 'government_ids', 'Passport Number', 'critical', true, true, ['/^(passport|passport[-_]?number|passport[-_]?no)$/i'], 'A12345678', ['', 'fake', 'bothify', ['?########'], null, null, null, null, null, null, null, null, null]],
                ['drivers_license', 'government_ids', 'Driver\'s License', 'critical', true, true, ['/^(drivers?[-_]?licen[sc]e|dl[-_]?number|license[-_]?number|driving[-_]?licen[sc]e)$/i'], 'DL-123456789', ['', 'fake', 'bothify', ['DL-#########'], null, null, null, null, null, null, null, null, null]],
                ['tax_id', 'government_ids', 'Tax ID / VAT Number', 'critical', true, true, ['/^(tax[-_]?id|tax[-_]?number|tin|vat[-_]?number|vat[-_]?id|fiscal[-_]?id)$/i'], '12-3456789', ['', 'fake', 'numerify', ['##-#######'], null, null, null, null, null, null, null, null, null]],
            ]],
            ['personal_identity', 'Personal Identity', [
                ['first_name', 'personal_identity', 'First Name', 'high', true, true, ['/^(first[-_]?name|given[-_]?name|vorname|prenom)$/i'], 'John', ['', 'fake', 'firstName', [], null, null, null, null, null, null, null, null, null]],
                ['last_name', 'personal_identity', 'Last Name', 'high', true, true, ['/^(last[-_]?name|sur[-_]?name|family[-_]?name|nachname|nom)$/i'], 'Doe', ['', 'fake', 'lastName', [], null, null, null, null, null, null, null, null, null]],
                ['full_name', 'personal_identity', 'Person Name', 'high', true, true, ['/^(full[-_]?name|display[-_]?name|name|nick[-_]?name)$/i'], 'John Doe', ['', 'fake', 'name', [], null, null, null, null, null, null, null, null, null]],
                ['date_of_birth', 'personal_identity', 'Date of Birth', 'high', true, true, ['/^(birth[-_]?date|date[-_]?of[-_]?birth|dob|birthday|geburtsdatum)$/i'], '1990-05-15', ['', 'fake', 'date', ['Y-m-d'], null, null, null, null, null, null, null, null, null]],
                ['gender', 'personal_identity', 'Gender', 'medium', true, true, ['/^(gender|sex|geschlecht)$/i'], 'female', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
                ['place_of_birth', 'personal_identity', 'Place of Birth', 'medium', true, true, ['/^(birth[-_]?place|place[-_]?of[-_]?birth|birthplace|birth[-_]?city|hometown)$/i'], 'Chicago', ['', 'fake', 'city', [], null, null, null, null, null, null, null, null, null]],
                ['nationality', 'personal_identity', 'Nationality / Citizenship', 'medium', true, true, ['/^(nationality|citizenship|national[-_]?origin|staatsangehoerigkeit)$/i'], 'US', ['', 'fake', 'countryCode', [], null, null, null, null, null, null, null, null, null]],
                ['race_ethnicity', 'personal_identity', 'Race / Ethnicity', 'high', false, true, ['/^(race|ethnicity|ethnic[-_]?group|ethnic[-_]?origin)$/i'], 'Asian', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
                ['religion', 'personal_identity', 'Religion / Faith', 'high', false, true, ['/^(religion|faith|religious[-_]?affiliation|creed)$/i'], 'Christian', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['contact', 'Contact Information', [
                ['email_address', 'contact', 'Email Address', 'high', true, true, ['/^(e[-_]?mail|email[-_]?addr(ess)?|user[-_]?email|contact[-_]?email)$/i', 'reply_to', '*_email'], 'john.doe@example.com', ['', 'fake', 'safeEmail', [], null, null, null, null, null, null, null, null, null]],
                ['phone_number', 'contact', 'Phone Number', 'high', true, true, ['/^(phone|phone[-_]?number|tel(ephone)?|mobile|cell|fax)$/i'], '+1-555-123-4567', ['', 'fake', 'phoneNumber', [], null, null, null, null, null, null, null, null, null]],
                ['username', 'contact', 'Username / Login', 'medium', true, true, ['/^(username|user[-_]?name|login|benutzername|handle)$/i'], 'john_doe_42', ['', 'fake', 'userName', [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['location', 'Location', [
                ['street_address', 'location', 'Street Address', 'high', true, true, ['/^(address|street|street[-_]?address|addr(ess)?[-_]?line[-_]?\\d?)$/i'], '123 Main Street', ['', 'fake', 'address', [], null, null, null, null, null, null, null, null, null]],
                ['city', 'location', 'City', 'low', true, true, ['/^(city|town|ort|stadt|ville)$/i'], 'Springfield', ['', 'fake', 'city', [], null, null, null, null, null, null, null, null, null]],
                ['postal_code', 'location', 'Postal Code', 'medium', true, true, ['/^(zip|zip[-_]?code|postal[-_]?code|postcode|plz)$/i'], '12345', ['', 'fake', 'postcode', [], null, null, null, null, null, null, null, null, null]],
                ['country', 'location', 'Country', 'low', true, true, ['/^(country|land|country[-_]?code|pays)$/i'], 'US', ['', 'fake', 'countryCode', [], null, null, null, null, null, null, null, null, null]],
                ['latitude', 'location', 'Latitude', 'medium', true, true, ['/^(lat|latitude|geo[-_]?lat)$/i'], '40.712776', ['', 'fake', 'latitude', [], null, null, null, null, null, null, null, null, null]],
                ['longitude', 'location', 'Longitude', 'medium', true, true, ['/^(lon|lng|longitude|geo[-_]?lon|geo[-_]?lng)$/i'], '-74.005974', ['', 'fake', 'longitude', [], null, null, null, null, null, null, null, null, null]],
                ['building_number', 'location', 'Building / Apartment Number', 'low', true, true, ['/^(building[-_]?number|house[-_]?number|flat|apartment[-_]?number|unit[-_]?number)$/i'], '42B', ['', 'fake', 'buildingNumber', [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['financial', 'Financial Data', [
                ['credit_card', 'financial', 'Credit Card Number', 'critical', true, true, ['/^(credit[-_]?card|card[-_]?number|cc[-_]?number|payment[-_]?card|pan)$/i'], '4242424242424242', ['', 'fake', 'creditCardNumber', [], null, null, null, null, null, null, null, null, null]],
                ['iban', 'financial', 'IBAN / Bank Account', 'critical', true, true, ['/^(iban|bank[-_]?account|kontonummer)$/i'], 'DE89370400440532013000', ['', 'fake', 'iban', [], null, null, null, null, null, null, null, null, null]],
                ['bank_routing_number', 'financial', 'Bank Routing / Sort Code', 'critical', true, true, ['/^(routing[-_]?number|sort[-_]?code|aba[-_]?number|bank[-_]?code|bic|swift[-_]?code)$/i'], '021000021', ['', 'fake', 'numerify', ['#########'], null, null, null, null, null, null, null, null, null]],
                ['crypto_wallet', 'financial', 'Crypto Wallet Address', 'high', false, true, ['/^(wallet[-_]?address|crypto[-_]?wallet|bitcoin[-_]?address|eth[-_]?address|blockchain[-_]?address)$/i'], '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', ['', 'fake', 'sha256', [], null, null, null, null, null, null, null, null, null]],
                ['salary', 'financial', 'Salary / Wage', 'high', false, true, ['/^(salary|wage|income|compensation|annual[-_]?pay|hourly[-_]?rate)$/i'], '75000', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['medical', 'Medical & Health', [
                ['medical_record_id', 'medical', 'Medical Record Number', 'critical', true, true, ['/^(medical[-_]?record|mrn|medical[-_]?record[-_]?number|chart[-_]?number)$/i'], 'MRN-789456', ['', 'fake', 'bothify', ['MRN-######'], null, null, null, null, null, null, null, null, null]],
                ['health_insurance_id', 'medical', 'Health Insurance ID', 'critical', true, true, ['/^(insurance[-_]?id|insurance[-_]?number|health[-_]?plan|policy[-_]?number|member[-_]?id)$/i'], 'INS-123456789', ['', 'fake', 'bothify', ['INS-#########'], null, null, null, null, null, null, null, null, null]],
                ['patient_id', 'medical', 'Patient ID', 'critical', false, true, ['/^(patient[-_]?id|patient[-_]?number|patient[-_]?reference)$/i'], 'PAT-001234', ['', 'fake', 'bothify', ['PAT-######'], null, null, null, null, null, null, null, null, null]],
                ['diagnosis', 'medical', 'Diagnosis / Medical Condition', 'critical', false, true, ['/^(diagnosis|diagnose|condition|icd[-_]?code|medical[-_]?condition|symptom)$/i'], 'Type 2 Diabetes', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
                ['blood_type', 'medical', 'Blood Type', 'high', false, true, ['/^(blood[-_]?type|blood[-_]?group|blutgruppe)$/i'], 'A+', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['biometric', 'Biometric Data', [
                ['biometric_data', 'biometric', 'Biometric Identifier', 'critical', true, true, ['/^(fingerprint|face[-_]?(id|encoding|hash|vector)|voice[-_]?print|biometric|retina[-_]?scan)$/i'], 'base64-biometric-hash', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
                ['dna_profile', 'biometric', 'DNA / Genetic Data', 'critical', false, true, ['/^(dna|dna[-_]?profile|genetic[-_]?data|genome)$/i'], 'ATCGATCGATCG', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['professional', 'Professional & Employment', [
                ['company_name', 'professional', 'Company Name', 'low', true, true, ['/^(company|company[-_]?name|organization|organisation|org[-_]?name|firma)$/i'], 'Acme Corporation', ['', 'fake', 'company', [], null, null, null, null, null, null, null, null, null]],
                ['job_title', 'professional', 'Job Title', 'low', true, true, ['/^(job[-_]?title|title|position|role|occupation|beruf|fonction)$/i'], 'Software Engineer', ['', 'fake', 'jobTitle', [], null, null, null, null, null, null, null, null, null]],
                ['employee_id', 'professional', 'Employee ID', 'medium', true, true, ['/^(employee[-_]?id|emp[-_]?id|staff[-_]?id|personnel[-_]?id|worker[-_]?id)$/i'], 'EMP-001234', ['', 'hash', null, [], 'sha256', null, null, null, null, null, null, null, null]],
            ]],
            ['digital_identity', 'Digital Identity', [
                ['ip_address', 'digital_identity', 'IP Address', 'medium', true, true, ['/^(ip|ip[-_]?addr(ess)?|client[-_]?ip|remote[-_]?ip|user[-_]?ip)$/i'], '192.168.1.100', ['', 'fake', 'ipv4', [], null, null, null, null, null, null, null, null, null]],
                ['device_id', 'digital_identity', 'Device ID / UDID', 'medium', true, true, ['/^(device[-_]?id|device[-_]?identifier|udid|imei|device[-_]?token)$/i'], '550e8400-e29b-41d4-a716-446655440000', ['', 'fake', 'uuid', [], null, null, null, null, null, null, null, null, null]],
                ['session_id', 'digital_identity', 'Session ID', 'high', true, true, ['/^(session[-_]?id|session[-_]?token|session[-_]?key)$/i'], 'sess_abc123xyz789', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
                ['mac_address', 'digital_identity', 'MAC Address', 'medium', true, true, ['/^(mac[-_]?address|mac[-_]?addr|hardware[-_]?address|physical[-_]?address)$/i'], '00:1A:2B:3C:4D:5E', ['', 'fake', 'macAddress', [], null, null, null, null, null, null, null, null, null]],
                ['user_agent', 'digital_identity', 'User Agent / Browser', 'low', false, true, ['/^(user[-_]?agent|browser|client[-_]?info|http[-_]?user[-_]?agent)$/i'], 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', ['', 'null', null, [], null, null, null, null, null, null, null, null, null]],
            ]],
            ['authentication', 'Authentication & Secrets', [
                ['password', 'authentication', 'Password / Secret', 'critical', true, true, ['/^(password|passwd|pwd|secret|passwort)$/i'], 'mysecretpassword', ['', 'static', null, [], null, null, null, null, null, 'REDACTED', null, null, null]],
                ['oauth_token', 'authentication', 'OAuth / Access Token', 'critical', true, true, ['/^(oauth[-_]?token|access[-_]?token|refresh[-_]?token|bearer[-_]?token|auth[-_]?token)$/i'], 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', ['', 'static', null, [], null, null, null, null, null, 'REDACTED', null, null, null]],
                ['api_token', 'authentication', 'API Token / Key', 'critical', false, true, ['/^(token|api[-_]?key)$/i'], 'sk_live_abc123xyz789', ['', 'static', null, [], null, null, null, null, null, 'REDACTED', null, null, null]],
                ['private_key', 'authentication', 'Private / Encryption Key', 'critical', false, true, ['/^(private[-_]?key|secret[-_]?key|signing[-_]?key|encryption[-_]?key)$/i'], '-----BEGIN RSA PRIVATE KEY-----', ['', 'static', null, [], null, null, null, null, null, 'REDACTED', null, null, null]],
            ]],
        ]);
});

it('flattens every baseline group into the matcher set in order', function (): void {
    $provider = new PiiMatcherBaselineProvider;

    $expected = [];

    foreach ($provider->getGroups() as $group) {
        foreach ($group->matchers as $matcher) {
            $expected[] = $matcher->group.'.'.$matcher->key;
        }
    }

    $actual = array_map(fn ($m): string => $m->group.'.'.$m->key, $provider->getMatcherSet()->matchers);

    expect($actual)->toBe($expected);
});
