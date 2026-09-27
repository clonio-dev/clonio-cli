<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Transport security for a network connection. Case order is the prompt order.
 */
enum SslMode: string
{
    case Require = 'require';
    case Verify = 'verify';
    case Disable = 'disable';

    public function label(): string
    {
        return match ($this) {
            self::Require => 'Require (encrypted, not verified)',
            self::Verify => 'Verify (encrypted + certificate check)',
            self::Disable => 'Disable (plaintext)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
