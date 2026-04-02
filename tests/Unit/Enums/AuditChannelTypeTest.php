<?php

declare(strict_types=1);

use App\Enums\AuditChannelType;

it('returns all six enum values', function (): void {
    expect(AuditChannelType::values())->toBe(['local', 's3', 'email', 'ms_teams', 'slack', 'ntfy']);
});

it('returns labels for all cases', function (): void {
    $labels = AuditChannelType::labels();
    expect($labels)->toHaveCount(6)
        ->and($labels)->toContain('Local filesystem')
        ->and($labels)->toContain('S3-compatible storage')
        ->and($labels)->toContain('Email (SMTP)')
        ->and($labels)->toContain('Microsoft Teams')
        ->and($labels)->toContain('Slack')
        ->and($labels)->toContain('ntfy.sh / self-hosted ntfy');
});

it('resolves a case from its label', function (): void {
    expect(AuditChannelType::fromLabel('Local filesystem'))->toBe(AuditChannelType::Local)
        ->and(AuditChannelType::fromLabel('Slack'))->toBe(AuditChannelType::Slack)
        ->and(AuditChannelType::fromLabel('ntfy.sh / self-hosted ntfy'))->toBe(AuditChannelType::Ntfy);
});

it('throws ValueError for an unknown label', function (): void {
    expect(fn () => AuditChannelType::fromLabel('Unknown'))->toThrow(ValueError::class);
});

it('defaultDeliversRunLog returns true only for local and s3', function (): void {
    expect(AuditChannelType::Local->defaultDeliversRunLog())->toBeTrue()
        ->and(AuditChannelType::S3->defaultDeliversRunLog())->toBeTrue()
        ->and(AuditChannelType::Email->defaultDeliversRunLog())->toBeFalse()
        ->and(AuditChannelType::MsTeams->defaultDeliversRunLog())->toBeFalse()
        ->and(AuditChannelType::Slack->defaultDeliversRunLog())->toBeFalse()
        ->and(AuditChannelType::Ntfy->defaultDeliversRunLog())->toBeFalse();
});

it('hasSecrets returns false only for local', function (): void {
    expect(AuditChannelType::Local->hasSecrets())->toBeFalse()
        ->and(AuditChannelType::S3->hasSecrets())->toBeTrue()
        ->and(AuditChannelType::Email->hasSecrets())->toBeTrue()
        ->and(AuditChannelType::MsTeams->hasSecrets())->toBeTrue()
        ->and(AuditChannelType::Slack->hasSecrets())->toBeTrue()
        ->and(AuditChannelType::Ntfy->hasSecrets())->toBeTrue();
});

it('label returns expected string for each case', function (): void {
    expect(AuditChannelType::Local->label())->toBe('Local filesystem')
        ->and(AuditChannelType::S3->label())->toBe('S3-compatible storage')
        ->and(AuditChannelType::Email->label())->toBe('Email (SMTP)')
        ->and(AuditChannelType::MsTeams->label())->toBe('Microsoft Teams')
        ->and(AuditChannelType::Slack->label())->toBe('Slack')
        ->and(AuditChannelType::Ntfy->label())->toBe('ntfy.sh / self-hosted ntfy');
});
