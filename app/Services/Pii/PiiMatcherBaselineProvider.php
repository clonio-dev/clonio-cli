<?php

declare(strict_types=1);

namespace App\Services\Pii;

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;
use App\Data\Pii\PiiMatcherSetData;

class PiiMatcherBaselineProvider
{
    /**
     * @return list<PiiMatcherGroupData>
     */
    public function getGroups(): array
    {
        return [
            $this->personalIdentityGroup(),
            $this->contactGroup(),
            $this->locationGroup(),
            $this->financialGroup(),
            $this->authenticationGroup(),
            $this->networkGroup(),
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

    private function personalIdentityGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'personal_identity',
            name: 'Personal Identity',
            matchers: [
                new PiiMatcherData(
                    key: 'first_name',
                    group: 'personal_identity',
                    name: 'First Name',
                    enabled: true,
                    patterns: ['/^(first[-_]?name|given[-_]?name|vorname|prenom)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'first_name',
                        strategy: 'fake',
                        fakerMethod: 'firstName',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'John',
                ),
                new PiiMatcherData(
                    key: 'last_name',
                    group: 'personal_identity',
                    name: 'Last Name',
                    enabled: true,
                    patterns: ['/^(last[-_]?name|sur[-_]?name|family[-_]?name|nachname|nom)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'last_name',
                        strategy: 'fake',
                        fakerMethod: 'lastName',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'Doe',
                ),
                new PiiMatcherData(
                    key: 'full_name',
                    group: 'personal_identity',
                    name: 'Person Name',
                    enabled: true,
                    patterns: ['/^(full[-_]?name|display[-_]?name|name|user[-_]?name|nick[-_]?name)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'full_name',
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
                    exampleValue: 'John Doe',
                ),
                new PiiMatcherData(
                    key: 'date_of_birth',
                    group: 'personal_identity',
                    name: 'Date of Birth',
                    enabled: true,
                    patterns: ['/^(birth[-_]?date|date[-_]?of[-_]?birth|dob|birthday|geburtsdatum)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'date_of_birth',
                        strategy: 'fake',
                        fakerMethod: 'date',
                        fakerArguments: ['Y-m-d'],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '1990-05-15',
                ),
                new PiiMatcherData(
                    key: 'national_id',
                    group: 'personal_identity',
                    name: 'National ID / SSN',
                    enabled: true,
                    patterns: ['/^(ssn|social[-_]?security|national[-_]?id|tax[-_]?id|personal[-_]?id)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'national_id',
                        strategy: 'hash',
                        fakerMethod: null,
                        fakerArguments: [],
                        hashAlgorithm: 'sha256',
                        hashSalt: '',
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '123-45-6789',
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
                new PiiMatcherData(
                    key: 'email_address',
                    group: 'contact',
                    name: 'Email Address',
                    enabled: true,
                    patterns: [
                        '/^(e[-_]?mail|email[-_]?addr(ess)?|user[-_]?email|contact[-_]?email)$/i',
                        'reply_to',
                        '*_email',
                    ],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'email_address',
                        strategy: 'fake',
                        fakerMethod: 'safeEmail',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'john.doe@example.com',
                ),
                new PiiMatcherData(
                    key: 'phone_number',
                    group: 'contact',
                    name: 'Phone Number',
                    enabled: true,
                    patterns: ['/^(phone|phone[-_]?number|tel(ephone)?|mobile|cell|fax)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'phone_number',
                        strategy: 'fake',
                        fakerMethod: 'phoneNumber',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '+1-555-123-4567',
                ),
                new PiiMatcherData(
                    key: 'username',
                    group: 'contact',
                    name: 'Username / Login',
                    enabled: true,
                    patterns: ['/^(username|user[-_]?name|login|benutzername|handle)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'username',
                        strategy: 'fake',
                        fakerMethod: 'userName',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'john_doe_42',
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
                new PiiMatcherData(
                    key: 'street_address',
                    group: 'location',
                    name: 'Street Address',
                    enabled: true,
                    patterns: ['/^(address|street|street[-_]?address|addr(ess)?[-_]?line[-_]?\d?)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'street_address',
                        strategy: 'fake',
                        fakerMethod: 'address',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '123 Main Street',
                ),
                new PiiMatcherData(
                    key: 'city',
                    group: 'location',
                    name: 'City',
                    enabled: true,
                    patterns: ['/^(city|town|ort|stadt|ville)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'city',
                        strategy: 'fake',
                        fakerMethod: 'city',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'Springfield',
                ),
                new PiiMatcherData(
                    key: 'postal_code',
                    group: 'location',
                    name: 'Postal Code',
                    enabled: true,
                    patterns: ['/^(zip|zip[-_]?code|postal[-_]?code|postcode|plz)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'postal_code',
                        strategy: 'fake',
                        fakerMethod: 'postcode',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '12345',
                ),
                new PiiMatcherData(
                    key: 'country',
                    group: 'location',
                    name: 'Country',
                    enabled: true,
                    patterns: ['/^(country|land|country[-_]?code|pays)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'country',
                        strategy: 'fake',
                        fakerMethod: 'countryCode',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'US',
                ),
                new PiiMatcherData(
                    key: 'latitude',
                    group: 'location',
                    name: 'Latitude',
                    enabled: true,
                    patterns: ['/^(lat|latitude|geo[-_]?lat)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'latitude',
                        strategy: 'fake',
                        fakerMethod: 'latitude',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '40.712776',
                ),
                new PiiMatcherData(
                    key: 'longitude',
                    group: 'location',
                    name: 'Longitude',
                    enabled: true,
                    patterns: ['/^(lon|lng|longitude|geo[-_]?lon|geo[-_]?lng)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'longitude',
                        strategy: 'fake',
                        fakerMethod: 'longitude',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '-74.005974',
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
                new PiiMatcherData(
                    key: 'credit_card',
                    group: 'financial',
                    name: 'Credit Card Number',
                    enabled: true,
                    patterns: ['/^(credit[-_]?card|card[-_]?number|cc[-_]?number|payment[-_]?card|pan)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'credit_card',
                        strategy: 'mask',
                        fakerMethod: null,
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: '*',
                        visibleChars: 4,
                        preserveFormat: false,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '4242424242424242',
                ),
                new PiiMatcherData(
                    key: 'iban',
                    group: 'financial',
                    name: 'IBAN / Bank Account',
                    enabled: true,
                    patterns: ['/^(iban|bank[-_]?account|kontonummer|bic|swift)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'iban',
                        strategy: 'mask',
                        fakerMethod: null,
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: '*',
                        visibleChars: 4,
                        preserveFormat: false,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'DE89370400440532013000',
                ),
                new PiiMatcherData(
                    key: 'company_name',
                    group: 'financial',
                    name: 'Company Name',
                    enabled: true,
                    patterns: ['/^(company|company[-_]?name|organization|org[-_]?name|firma)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'company_name',
                        strategy: 'fake',
                        fakerMethod: 'company',
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'Acme Corporation',
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
                new PiiMatcherData(
                    key: 'password',
                    group: 'authentication',
                    name: 'Password / Secret',
                    enabled: true,
                    patterns: ['/^(password|passwd|pwd|secret|passwort)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'password',
                        strategy: 'hash',
                        fakerMethod: null,
                        fakerArguments: [],
                        hashAlgorithm: 'sha256',
                        hashSalt: '',
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'mysecretpassword',
                ),
                new PiiMatcherData(
                    key: 'api_token',
                    group: 'authentication',
                    name: 'API Token / Key',
                    enabled: false,
                    patterns: ['/^(token|api[-_]?key|access[-_]?token|refresh[-_]?token|auth[-_]?token)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'api_token',
                        strategy: 'hash',
                        fakerMethod: null,
                        fakerArguments: [],
                        hashAlgorithm: 'sha256',
                        hashSalt: '',
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: 'sk_live_abc123xyz789',
                ),
            ],
        );
    }

    private function networkGroup(): PiiMatcherGroupData
    {
        return new PiiMatcherGroupData(
            key: 'network',
            name: 'Network & Technical',
            matchers: [
                new PiiMatcherData(
                    key: 'ip_address',
                    group: 'network',
                    name: 'IP Address',
                    enabled: true,
                    patterns: ['/^(ip|ip[-_]?addr(ess)?|client[-_]?ip|remote[-_]?ip|user[-_]?ip)$/i'],
                    transformation: new ColumnCloningConfigData(
                        columnName: 'ip_address',
                        strategy: 'mask',
                        fakerMethod: null,
                        fakerArguments: [],
                        hashAlgorithm: null,
                        hashSalt: null,
                        maskChar: '*',
                        visibleChars: 0,
                        preserveFormat: true,
                        staticValue: null,
                    ),
                    isBaseline: true,
                    exampleValue: '192.168.1.100',
                ),
            ],
        );
    }
}
