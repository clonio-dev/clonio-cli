<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class TableSchemaData
{
    /**
     * @param  list<ColumnSchemaData>  $columns
     * @param  list<ForeignKeyData>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $foreignKeys,
    ) {}
}
