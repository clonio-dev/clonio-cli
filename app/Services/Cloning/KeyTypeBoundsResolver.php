<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Schema\ColumnSchemaData;
use App\Exceptions\UnsupportedKeyColumnTypeException;

final class KeyTypeBoundsResolver
{
    public function ceilingFor(ColumnSchemaData $column): int
    {
        $type = strtolower($column->type);

        return match ($type) {
            'tinyint' => $column->unsigned ? 255 : 127,
            'smallint' => $column->unsigned ? 65535 : 32767,
            'mediumint' => $column->unsigned ? 16777215 : 8388607,
            'int', 'integer' => $column->unsigned ? 4294967295 : 2147483647,
            'bigint' => PHP_INT_MAX,
            default => throw new UnsupportedKeyColumnTypeException(
                sprintf("Column '%s' has type '%s' which is not supported for integer key remapping", $column->name, $column->type)
            ),
        };
    }
}
