<?php

declare(strict_types=1);

namespace App\Services\Pii;

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;
use App\Data\Pii\PiiMatcherSetData;
use App\Enums\PiiSensitivity;

class PiiMatcherBaselineProvider
{
    /**
     * @return list<PiiMatcherGroupData>
     */
    public function getGroups(): array
    {
        return [
            $this->governmentIdsGroup(),
            $this->personalIdentityGroup(),
            $this->contactGroup(),
            $this->locationGroup(),
            $this->financialGroup(),
            $this->medicalGroup(),
            $this->biometricGroup(),
            $this->professionalGroup(),
            $this->digitalIdentityGroup(),
            $this->authenticationGroup(),
        ];
    }

    public function getMatcherSet(): PiiMatcherSetData
    {
        $matchers = [];

        foreach ($this->getGroups() as $group) {
            foreach ($group->matchers as $matcher) {
                $matchers[] = $matcher;
            }
        }

        return new PiiMatcherSetData($matchers);
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    private function governmentIdsGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'government_ids',
            name: 'Government-Issued Identifiers',
            matchers: [
                $this->make(
                    'national_id', 'government_ids', 'National ID / SSN', PiiSensitivity::Critical,
                    ['/^(ssn|social[-_]?security|national[-_]?id|personal[-_]?id)$/i'],
                    $this->fake('numerify', ['###-##-####']),
                    '123-45-6789',
                ),
                $this->make(
                    'passport_number', 'government_ids', 'Passport Number', PiiSensitivity::Critical,
                    ['/^(passport|passport[-_]?number|passport[-_]?no)$/i'],
                    $this->fake('bothify', ['?########']),
                    'A12345678',
                ),
                $this->make(
                    'drivers_license', 'government_ids', "Driver's License", PiiSensitivity::Critical,
                    ['/^(drivers?[-_]?licen[sc]e|dl[-_]?number|license[-_]?number|driving[-_]?licen[sc]e)$/i'],
                    $this->fake('bothify', ['DL-#########']),
                    'DL-123456789',
                ),
                $this->make(
                    'tax_id', 'government_ids', 'Tax ID / VAT Number', PiiSensitivity::Critical,
                    ['/^(tax[-_]?id|tax[-_]?number|tin|vat[-_]?number|vat[-_]?id|fiscal[-_]?id)$/i'],
                    $this->fake('numerify', ['##-#######']),
                    '12-3456789',
                ),
            ],
        );
    }

    private function personalIdentityGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'personal_identity',
            name: 'Personal Identity',
            matchers: [
                $this->make(
                    'first_name', 'personal_identity', 'First Name', PiiSensitivity::High,
                    ['/^(first[-_]?name|given[-_]?name|vorname|prenom)$/i'],
                    $this->fake('firstName'),
                    'John',
                ),
                $this->make(
                    'last_name', 'personal_identity', 'Last Name', PiiSensitivity::High,
                    ['/^(last[-_]?name|sur[-_]?name|family[-_]?name|nachname|nom)$/i'],
                    $this->fake('lastName'),
                    'Doe',
                ),
                $this->make(
                    'full_name', 'personal_identity', 'Person Name', PiiSensitivity::High,
                    ['/^(full[-_]?name|display[-_]?name|name|nick[-_]?name)$/i'],
                    $this->fake('name'),
                    'John Doe',
                ),
                $this->make(
                    'date_of_birth', 'personal_identity', 'Date of Birth', PiiSensitivity::High,
                    ['/^(birth[-_]?date|date[-_]?of[-_]?birth|dob|birthday|geburtsdatum)$/i'],
                    $this->fake('date', ['Y-m-d']),
                    '1990-05-15',
                ),
                $this->make(
                    'gender', 'personal_identity', 'Gender', PiiSensitivity::Medium,
                    ['/^(gender|sex|geschlecht)$/i'],
                    $this->nullify(),
                    'female',
                ),
                $this->make(
                    'place_of_birth', 'personal_identity', 'Place of Birth', PiiSensitivity::Medium,
                    ['/^(birth[-_]?place|place[-_]?of[-_]?birth|birthplace|birth[-_]?city|hometown)$/i'],
                    $this->fake('city'),
                    'Chicago',
                ),
                $this->make(
                    'nationality', 'personal_identity', 'Nationality / Citizenship', PiiSensitivity::Medium,
                    ['/^(nationality|citizenship|national[-_]?origin|staatsangehoerigkeit)$/i'],
                    $this->fake('countryCode'),
                    'US',
                ),
                $this->make(
                    'race_ethnicity', 'personal_identity', 'Race / Ethnicity', PiiSensitivity::High,
                    ['/^(race|ethnicity|ethnic[-_]?group|ethnic[-_]?origin)$/i'],
                    $this->nullify(),
                    'Asian',
                    enabled: false,
                ),
                $this->make(
                    'religion', 'personal_identity', 'Religion / Faith', PiiSensitivity::High,
                    ['/^(religion|faith|religious[-_]?affiliation|creed)$/i'],
                    $this->nullify(),
                    'Christian',
                    enabled: false,
                ),
            ],
        );
    }

    private function contactGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'contact',
            name: 'Contact Information',
            matchers: [
                $this->make(
                    'email_address', 'contact', 'Email Address', PiiSensitivity::High,
                    [
                        '/^(e[-_]?mail|email[-_]?addr(ess)?|user[-_]?email|contact[-_]?email)$/i',
                        'reply_to',
                        '*_email',
                    ],
                    $this->fake('safeEmail'),
                    'john.doe@example.com',
                ),
                $this->make(
                    'phone_number', 'contact', 'Phone Number', PiiSensitivity::High,
                    ['/^(phone|phone[-_]?number|tel(ephone)?|mobile|cell|fax)$/i'],
                    $this->fake('phoneNumber'),
                    '+1-555-123-4567',
                ),
                $this->make(
                    'username', 'contact', 'Username / Login', PiiSensitivity::Medium,
                    ['/^(username|user[-_]?name|login|benutzername|handle)$/i'],
                    $this->fake('userName'),
                    'john_doe_42',
                ),
            ],
        );
    }

    private function locationGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'location',
            name: 'Location',
            matchers: [
                $this->make(
                    'street_address', 'location', 'Street Address', PiiSensitivity::High,
                    ['/^(address|street|street[-_]?address|addr(ess)?[-_]?line[-_]?\d?)$/i'],
                    $this->fake('address'),
                    '123 Main Street',
                ),
                $this->make(
                    'city', 'location', 'City', PiiSensitivity::Low,
                    ['/^(city|town|ort|stadt|ville)$/i'],
                    $this->fake('city'),
                    'Springfield',
                ),
                $this->make(
                    'postal_code', 'location', 'Postal Code', PiiSensitivity::Medium,
                    ['/^(zip|zip[-_]?code|postal[-_]?code|postcode|plz)$/i'],
                    $this->fake('postcode'),
                    '12345',
                ),
                $this->make(
                    'country', 'location', 'Country', PiiSensitivity::Low,
                    ['/^(country|land|country[-_]?code|pays)$/i'],
                    $this->fake('countryCode'),
                    'US',
                ),
                $this->make(
                    'latitude', 'location', 'Latitude', PiiSensitivity::Medium,
                    ['/^(lat|latitude|geo[-_]?lat)$/i'],
                    $this->fake('latitude'),
                    '40.712776',
                ),
                $this->make(
                    'longitude', 'location', 'Longitude', PiiSensitivity::Medium,
                    ['/^(lon|lng|longitude|geo[-_]?lon|geo[-_]?lng)$/i'],
                    $this->fake('longitude'),
                    '-74.005974',
                ),
                $this->make(
                    'building_number', 'location', 'Building / Apartment Number', PiiSensitivity::Low,
                    ['/^(building[-_]?number|house[-_]?number|flat|apartment[-_]?number|unit[-_]?number)$/i'],
                    $this->fake('buildingNumber'),
                    '42B',
                ),
            ],
        );
    }

    private function financialGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'financial',
            name: 'Financial Data',
            matchers: [
                $this->make(
                    'credit_card', 'financial', 'Credit Card Number', PiiSensitivity::Critical,
                    ['/^(credit[-_]?card|card[-_]?number|cc[-_]?number|payment[-_]?card|pan)$/i'],
                    $this->fake('creditCardNumber'),
                    '4242424242424242',
                ),
                $this->make(
                    'iban', 'financial', 'IBAN / Bank Account', PiiSensitivity::Critical,
                    ['/^(iban|bank[-_]?account|kontonummer)$/i'],
                    $this->fake('iban'),
                    'DE89370400440532013000',
                ),
                $this->make(
                    'bank_routing_number', 'financial', 'Bank Routing / Sort Code', PiiSensitivity::Critical,
                    ['/^(routing[-_]?number|sort[-_]?code|aba[-_]?number|bank[-_]?code|bic|swift[-_]?code)$/i'],
                    $this->fake('numerify', ['#########']),
                    '021000021',
                ),
                $this->make(
                    'crypto_wallet', 'financial', 'Crypto Wallet Address', PiiSensitivity::High,
                    ['/^(wallet[-_]?address|crypto[-_]?wallet|bitcoin[-_]?address|eth[-_]?address|blockchain[-_]?address)$/i'],
                    $this->fake('sha256'),
                    '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
                    enabled: false,
                ),
                $this->make(
                    'salary', 'financial', 'Salary / Wage', PiiSensitivity::High,
                    ['/^(salary|wage|income|compensation|annual[-_]?pay|hourly[-_]?rate)$/i'],
                    $this->nullify(),
                    '75000',
                    enabled: false,
                ),
            ],
        );
    }

    private function medicalGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'medical',
            name: 'Medical & Health',
            matchers: [
                $this->make(
                    'medical_record_id', 'medical', 'Medical Record Number', PiiSensitivity::Critical,
                    ['/^(medical[-_]?record|mrn|medical[-_]?record[-_]?number|chart[-_]?number)$/i'],
                    $this->fake('bothify', ['MRN-######']),
                    'MRN-789456',
                ),
                $this->make(
                    'health_insurance_id', 'medical', 'Health Insurance ID', PiiSensitivity::Critical,
                    ['/^(insurance[-_]?id|insurance[-_]?number|health[-_]?plan|policy[-_]?number|member[-_]?id)$/i'],
                    $this->fake('bothify', ['INS-#########']),
                    'INS-123456789',
                ),
                $this->make(
                    'patient_id', 'medical', 'Patient ID', PiiSensitivity::Critical,
                    ['/^(patient[-_]?id|patient[-_]?number|patient[-_]?reference)$/i'],
                    $this->fake('bothify', ['PAT-######']),
                    'PAT-001234',
                    enabled: false,
                ),
                $this->make(
                    'diagnosis', 'medical', 'Diagnosis / Medical Condition', PiiSensitivity::Critical,
                    ['/^(diagnosis|diagnose|condition|icd[-_]?code|medical[-_]?condition|symptom)$/i'],
                    $this->nullify(),
                    'Type 2 Diabetes',
                    enabled: false,
                ),
                $this->make(
                    'blood_type', 'medical', 'Blood Type', PiiSensitivity::High,
                    ['/^(blood[-_]?type|blood[-_]?group|blutgruppe)$/i'],
                    $this->nullify(),
                    'A+',
                    enabled: false,
                ),
            ],
        );
    }

    private function biometricGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'biometric',
            name: 'Biometric Data',
            matchers: [
                $this->make(
                    'biometric_data', 'biometric', 'Biometric Identifier', PiiSensitivity::Critical,
                    ['/^(fingerprint|face[-_]?(id|encoding|hash|vector)|voice[-_]?print|biometric|retina[-_]?scan)$/i'],
                    $this->nullify(),
                    'base64-biometric-hash',
                ),
                $this->make(
                    'dna_profile', 'biometric', 'DNA / Genetic Data', PiiSensitivity::Critical,
                    ['/^(dna|dna[-_]?profile|genetic[-_]?data|genome)$/i'],
                    $this->nullify(),
                    'ATCGATCGATCG',
                    enabled: false,
                ),
            ],
        );
    }

    private function professionalGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'professional',
            name: 'Professional & Employment',
            matchers: [
                $this->make(
                    'company_name', 'professional', 'Company Name', PiiSensitivity::Low,
                    ['/^(company|company[-_]?name|organization|organisation|org[-_]?name|firma)$/i'],
                    $this->fake('company'),
                    'Acme Corporation',
                ),
                $this->make(
                    'job_title', 'professional', 'Job Title', PiiSensitivity::Low,
                    ['/^(job[-_]?title|title|position|role|occupation|beruf|fonction)$/i'],
                    $this->fake('jobTitle'),
                    'Software Engineer',
                ),
                $this->make(
                    'employee_id', 'professional', 'Employee ID', PiiSensitivity::Medium,
                    ['/^(employee[-_]?id|emp[-_]?id|staff[-_]?id|personnel[-_]?id|worker[-_]?id)$/i'],
                    $this->hash(),
                    'EMP-001234',
                ),
            ],
        );
    }

    private function digitalIdentityGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'digital_identity',
            name: 'Digital Identity',
            matchers: [
                $this->make(
                    'ip_address', 'digital_identity', 'IP Address', PiiSensitivity::Medium,
                    ['/^(ip|ip[-_]?addr(ess)?|client[-_]?ip|remote[-_]?ip|user[-_]?ip)$/i'],
                    $this->fake('ipv4'),
                    '192.168.1.100',
                ),
                $this->make(
                    'device_id', 'digital_identity', 'Device ID / UDID', PiiSensitivity::Medium,
                    ['/^(device[-_]?id|device[-_]?identifier|udid|imei|device[-_]?token)$/i'],
                    $this->fake('uuid'),
                    '550e8400-e29b-41d4-a716-446655440000',
                ),
                $this->make(
                    'session_id', 'digital_identity', 'Session ID', PiiSensitivity::High,
                    ['/^(session[-_]?id|session[-_]?token|session[-_]?key)$/i'],
                    $this->nullify(),
                    'sess_abc123xyz789',
                ),
                $this->make(
                    'mac_address', 'digital_identity', 'MAC Address', PiiSensitivity::Medium,
                    ['/^(mac[-_]?address|mac[-_]?addr|hardware[-_]?address|physical[-_]?address)$/i'],
                    $this->fake('macAddress'),
                    '00:1A:2B:3C:4D:5E',
                ),
                $this->make(
                    'user_agent', 'digital_identity', 'User Agent / Browser', PiiSensitivity::Low,
                    ['/^(user[-_]?agent|browser|client[-_]?info|http[-_]?user[-_]?agent)$/i'],
                    $this->nullify(),
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    enabled: false,
                ),
            ],
        );
    }

    private function authenticationGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'authentication',
            name: 'Authentication & Secrets',
            matchers: [
                $this->make(
                    'password', 'authentication', 'Password / Secret', PiiSensitivity::Critical,
                    ['/^(password|passwd|pwd|secret|passwort)$/i'],
                    $this->staticValue('REDACTED'),
                    'mysecretpassword',
                ),
                $this->make(
                    'oauth_token', 'authentication', 'OAuth / Access Token', PiiSensitivity::Critical,
                    ['/^(oauth[-_]?token|access[-_]?token|refresh[-_]?token|bearer[-_]?token|auth[-_]?token)$/i'],
                    $this->staticValue('REDACTED'),
                    'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
                ),
                $this->make(
                    'api_token', 'authentication', 'API Token / Key', PiiSensitivity::Critical,
                    ['/^(token|api[-_]?key)$/i'],
                    $this->staticValue('REDACTED'),
                    'sk_live_abc123xyz789',
                    enabled: false,
                ),
                $this->make(
                    'private_key', 'authentication', 'Private / Encryption Key', PiiSensitivity::Critical,
                    ['/^(private[-_]?key|secret[-_]?key|signing[-_]?key|encryption[-_]?key)$/i'],
                    $this->staticValue('REDACTED'),
                    '-----BEGIN RSA PRIVATE KEY-----',
                    enabled: false,
                ),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<string>  $patterns
     */
    private function make(
        string $key,
        string $group,
        string $name,
        PiiSensitivity $sensitivity,
        array $patterns,
        ColumnCloningConfigData $transformation,
        ?string $exampleValue = null,
        bool $enabled = true,
    ): PiiMatcherData {
        return new PiiMatcherData(
            key: $key,
            group: $group,
            name: $name,
            enabled: $enabled,
            patterns: $patterns,
            transformation: $transformation,
            isBaseline: true,
            exampleValue: $exampleValue,
            sensitivity: $sensitivity,
        );
    }

    /**
     * @param  list<scalar>  $args
     */
    private function fake(string $method, array $args = []): ColumnCloningConfigData
    {
        return new ColumnCloningConfigData(
            columnName: '',
            strategy: 'fake',
            fakerMethod: $method,
            fakerArguments: $args,
            hashAlgorithm: null,
            hashSalt: null,
            maskChar: null,
            visibleChars: null,
            preserveFormat: null,
            staticValue: null,
        );
    }

    /**
     * Build a hash transformation. Salt defaults to null so the engine's
     * per-run random salt is applied at transform time — required for
     * pseudonymization that defeats cross-run linkability (GDPR Art. 4 Nr. 5).
     * Pass an explicit salt only if reproducible hashes across runs are
     * required (e.g. fixture data).
     */
    private function hash(string $algorithm = 'sha256', ?string $salt = null): ColumnCloningConfigData
    {
        return new ColumnCloningConfigData(
            columnName: '',
            strategy: 'hash',
            fakerMethod: null,
            fakerArguments: [],
            hashAlgorithm: $algorithm,
            hashSalt: $salt,
            maskChar: null,
            visibleChars: null,
            preserveFormat: null,
            staticValue: null,
        );
    }

    private function staticValue(string $value): ColumnCloningConfigData
    {
        return new ColumnCloningConfigData(
            columnName: '',
            strategy: 'static',
            fakerMethod: null,
            fakerArguments: [],
            hashAlgorithm: null,
            hashSalt: null,
            maskChar: null,
            visibleChars: null,
            preserveFormat: null,
            staticValue: $value,
        );
    }

    private function nullify(): ColumnCloningConfigData
    {
        return new ColumnCloningConfigData(
            columnName: '',
            strategy: 'null',
            fakerMethod: null,
            fakerArguments: [],
            hashAlgorithm: null,
            hashSalt: null,
            maskChar: null,
            visibleChars: null,
            preserveFormat: null,
            staticValue: null,
        );
    }
}
