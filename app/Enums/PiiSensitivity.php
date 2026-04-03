<?php

declare(strict_types=1);

namespace App\Enums;

enum PiiSensitivity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'critical',
            self::High => 'high',
            self::Medium => 'medium',
            self::Low => 'low',
        };
    }
}
