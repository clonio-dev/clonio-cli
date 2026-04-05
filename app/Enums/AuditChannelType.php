<?php

declare(strict_types=1);

namespace App\Enums;

use ValueError;

enum AuditChannelType: string
{
    case Local = 'local';
    case S3 = 's3';
    case Email = 'email';
    case MsTeams = 'ms_teams';
    case Slack = 'slack';
    case Ntfy = 'ntfy';
    case Stdout = 'stdout';
    case Stderr = 'stderr';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local filesystem',
            self::S3 => 'S3-compatible storage',
            self::Email => 'Email (SMTP)',
            self::MsTeams => 'Microsoft Teams',
            self::Slack => 'Slack',
            self::Ntfy => 'ntfy.sh / self-hosted ntfy',
            self::Stdout => 'Standard output',
            self::Stderr => 'Standard error',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(static fn (self $case): string => $case->label(), self::cases());
    }

    public static function fromLabel(string $label): self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        throw new ValueError(sprintf("'%s' is not a valid label for ", $label).self::class);
    }

    /** Default: should this type deliver run logs? */
    public function defaultDeliversRunLog(): bool
    {
        return match ($this) {
            self::Local, self::S3 => true,
            default => false,
        };
    }

    /** Does this type have secrets that need encryption? */
    public function hasSecrets(): bool
    {
        return match ($this) {
            self::Local, self::Stdout, self::Stderr => false,
            default => true,
        };
    }
}
