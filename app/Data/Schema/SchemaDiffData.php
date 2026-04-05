<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class SchemaDiffData
{
    /**
     * @param  list<string>  $missingTables  Tables in source but not in target
     * @param  list<string>  $extraTables  Tables in target but not in source
     * @param  list<TableDiffData>  $modifiedTables  Tables with structural differences
     */
    public function __construct(
        public array $missingTables,
        public array $extraTables,
        public array $modifiedTables,
    ) {}

    public function hasDifferences(): bool
    {
        return $this->missingTables !== [] || $this->extraTables !== [] || $this->modifiedTables !== [];
    }

    public function isIdentical(): bool
    {
        return ! $this->hasDifferences();
    }
}
