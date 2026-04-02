<?php

declare(strict_types=1);

namespace App\Enums;

enum KeyRemappingStrategy: string
{
    case RandomInteger = 'random_integer';
    case NewUuid = 'new_uuid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
