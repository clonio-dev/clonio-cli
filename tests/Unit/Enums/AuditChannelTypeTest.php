<?php

declare(strict_types=1);

use App\Enums\AuditChannelType;

it('returns all nine enum values', function (): void {
    expect(AuditChannelType::values())->toBe(['local', 's3', 'email', 'ms_teams', 'slack', 'ntfy', 'stdout', 'stderr', 'stack']);
});

it('returns labels for all cases', function (): void {
    $labels = AuditChannelType::labels();
    expect($labels)->toHaveCount(9)
        ->and($labels)->toContain('Local filesystem')
        ->and($labels)->toContain('S3-compatible storage')
        ->and($labels)->toContain('Email (SMTP)')
        ->and($labels)->toContain('Microsoft Teams')
        ->and($labels)->toContain('Slack')
        ->and($labels)->toContain('ntfy.sh / self-hosted ntfy')
        ->and($labels)->toContain('Standard output')
        ->and($labels)->toContain('Standard error')
        ->and($labels)->toContain('Stack (fan-out to multiple channels)');
});

it('resolves a case from its label', function (): void {
    expect(AuditChannelType::fromLabel('Local filesystem'))->toBe(AuditChannelType::Local)
        ->and(AuditChannelType::fromLabel('Slack'))->toBe(AuditChannelType::Slack)
        ->and(AuditChannelType::fromLabel('ntfy.sh / self-hosted ntfy'))->toBe(AuditChannelType::Ntfy);
});

it('throws ValueError for an unknown label', function (): void {
    expect(fn () => AuditChannelType::fromLabel('Unknown'))->toThrow(ValueError::class);
});

it('defaultDeliversProcessLog returns true only for local and s3', function (): void {
    expect(AuditChannelType::Local->defaultDeliversProcessLog())->toBeTrue()
        ->and(AuditChannelType::S3->defaultDeliversProcessLog())->toBeTrue()
        ->and(AuditChannelType::Email->defaultDeliversProcessLog())->toBeFalse()
        ->and(AuditChannelType::MsTeams->defaultDeliversProcessLog())->toBeFalse()
        ->and(AuditChannelType::Slack->defaultDeliversProcessLog())->toBeFalse()
        ->and(AuditChannelType::Ntfy->defaultDeliversProcessLog())->toBeFalse()
        ->and(AuditChannelType::Stdout->defaultDeliversProcessLog())->toBeFalse()
        ->and(AuditChannelType::Stderr->defaultDeliversProcessLog())->toBeFalse()
        ->and(AuditChannelType::Stack->defaultDeliversProcessLog())->toBeFalse();
});

it('hasSecrets returns false only for local, stdout, stderr, and stack', function (): void {
    expect(AuditChannelType::Local->hasSecrets())->toBeFalse()
        ->and(AuditChannelType::Stdout->hasSecrets())->toBeFalse()
        ->and(AuditChannelType::Stderr->hasSecrets())->toBeFalse()
        ->and(AuditChannelType::Stack->hasSecrets())->toBeFalse()
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
        ->and(AuditChannelType::Ntfy->label())->toBe('ntfy.sh / self-hosted ntfy')
        ->and(AuditChannelType::Stdout->label())->toBe('Standard output')
        ->and(AuditChannelType::Stderr->label())->toBe('Standard error')
        ->and(AuditChannelType::Stack->label())->toBe('Stack (fan-out to multiple channels)');
});
