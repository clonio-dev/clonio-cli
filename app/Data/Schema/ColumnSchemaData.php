<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class ColumnSchemaData
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
        public bool $isPrimary,
        public bool $unsigned = false,
        // Length/precision metadata for DDL generation. Null when unknown or not
        // applicable (e.g. TEXT, INTEGER). `length` is the character maximum for
        // string types; `precision`/`scale` describe numeric/decimal columns.
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
    ) {}
}
