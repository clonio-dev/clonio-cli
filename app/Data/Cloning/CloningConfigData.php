<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class CloningConfigData
{
    /**
     * @param  list<TableCloningConfigData>  $tables
     */
    public function __construct(
        public string $version,
        public string $connectionName,
        public CloningOptionsData $options,
        public array $tables,
        public ?KeyRemappingConfigData $keyRemapping = null,
    ) {}

    public function getTable(string $name): ?TableCloningConfigData
    {
        foreach ($this->tables as $table) {
            if ($table->tableName === $name) {
                return $table;
            }
        }

        return null;
    }
}
