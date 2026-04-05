<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class TableDiffData
{
    /**
     * @param  list<string>  $missingColumns  Columns in source but not in target
     * @param  list<string>  $extraColumns  Columns in target but not in source
     * @param  list<ColumnDiffData>  $modifiedColumns  Columns with type/nullability differences
     */
    public function __construct(
        public string $tableName,
        public array $missingColumns,
        public array $extraColumns,
        public array $modifiedColumns,
    ) {}

    public function hasDifferences(): bool
    {
        return $this->missingColumns !== [] || $this->extraColumns !== [] || $this->modifiedColumns !== [];
    }
}
