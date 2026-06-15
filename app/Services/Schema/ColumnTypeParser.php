<?php

declare(strict_types=1);

namespace App\Services\Schema;

/**
 * Extracts length / precision / scale from a declared column type string such as
 * `varchar(255)`, `decimal(10,2)`, `int unsigned`, or `VARCHAR(36)`.
 *
 * Used where the database exposes the full type expression (MySQL `COLUMN_TYPE`,
 * SQLite `PRAGMA table_info`). Drivers that surface these as discrete metadata
 * columns (PostgreSQL, SQL Server) populate ColumnSchemaData directly instead.
 */
class ColumnTypeParser
{
    /**
     * @return array{length: ?int, precision: ?int, scale: ?int}
     */
    public static function parse(string $declaredType): array
    {
        $result = ['length' => null, 'precision' => null, 'scale' => null];

        if (preg_match('/\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)/', $declaredType, $m) !== 1) {
            return $result;
        }

        $first = (int) $m[1];

        // An unmatched trailing optional group is absent from $m, so isset() discriminates.
        if (isset($m[2])) {
            // Two arguments → numeric precision/scale, e.g. decimal(10,2).
            $result['precision'] = $first;
            $result['scale'] = (int) $m[2];

            return $result;
        }

        // Single argument: scale for the numeric family, otherwise a character length.
        $base = strtolower(trim($declaredType));
        $base = preg_replace('/\s*\(.*$/', '', $base) ?? $base;

        if (in_array($base, ['decimal', 'numeric', 'dec', 'number'], true)) {
            $result['precision'] = $first;
            $result['scale'] = 0;

            return $result;
        }

        $result['length'] = $first;

        return $result;
    }
}
