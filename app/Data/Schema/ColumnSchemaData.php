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
        // True for generated/computed columns (MySQL/MariaDB VIRTUAL/STORED,
        // PostgreSQL GENERATED ALWAYS AS, SQLite generated, SQL Server computed).
        // The database computes these values, so Clonio never copies them.
        public bool $generated = false,
    ) {}
}
