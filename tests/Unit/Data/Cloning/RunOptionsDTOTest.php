<?php

declare(strict_types=1);

use App\Data\Audit\AuditColumnRecordData;
use App\Data\Cloning\OrphanedMatcherEntryData;
use App\Data\Cloning\RunOptionsData;

it('creates RunOptionsData with expected properties', function (): void {
    $data = new RunOptionsData(
        yamlPath: 'test.cloning.yaml',
        targetConnectionName: 'staging',
        allowFailure: false,
        dryRun: false,
        skipSchema: false,
        skipTables: ['audit_logs'],
        onlyTables: [],
        auditChannels: ['local-main'],
    );

    expect($data->yamlPath)->toBe('test.cloning.yaml');
    expect($data->targetConnectionName)->toBe('staging');
    expect($data->skipTables)->toBe(['audit_logs']);
    expect($data->auditChannels)->toBe(['local-main']);
});

it('creates AuditColumnRecordData with expected properties', function (): void {
    $data = new AuditColumnRecordData(
        columnName: 'email',
        strategy: 'fake',
    );

    expect($data->columnName)->toBe('email');
    expect($data->strategy)->toBe('fake');
});

it('creates OrphanedMatcherEntryData with expected properties', function (): void {
    $data = new OrphanedMatcherEntryData(
        groupKey: 'contact',
        matcherKey: 'email_address',
        matcherName: 'Email Address',
    );

    expect($data->groupKey)->toBe('contact');
    expect($data->matcherKey)->toBe('email_address');
    expect($data->matcherName)->toBe('Email Address');
});
