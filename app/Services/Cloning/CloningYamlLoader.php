<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Enums\KeyRemappingStrategy;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class CloningYamlLoader
{
    public function load(string $path): CloningConfigData
    {
        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException(sprintf('Cloning config file not found: %s', $path));
        }

        $content = Storage::disk('local')->get($path);

        if (! is_string($content)) {
            throw new RuntimeException(sprintf('Could not read cloning config file: %s', $path));
        }

        try {
            $data = Yaml::parse($content);
        } catch (ParseException $parseException) {
            throw new RuntimeException(sprintf('Invalid YAML in %s: %s', $path, $parseException->getMessage()), $parseException->getCode(), $parseException);
        }

        if (! is_array($data)) {
            throw new RuntimeException(sprintf('Invalid cloning config in %s: root must be a mapping', $path));
        }

        /** @var array<string, mixed> $data */
        return $this->mapToDto($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapToDto(array $data): CloningConfigData
    {
        $rawVersion = $data['version'] ?? '1';
        $version = is_scalar($rawVersion) ? (string) $rawVersion : '1';

        $rawConnection = $data['connection'] ?? '';
        $connectionName = is_string($rawConnection) ? $rawConnection : '';

        /** @var array<string, mixed> $optionsRaw */
        $optionsRaw = is_array($data['options'] ?? null) ? $data['options'] : [];

        $options = new CloningOptionsData(
            chunkSize: is_int($optionsRaw['chunk_size'] ?? null) ? $optionsRaw['chunk_size'] : 1000,
            enforceColumnTypes: (bool) ($optionsRaw['enforce_column_types'] ?? false),
            dropUnknownTables: (bool) ($optionsRaw['drop_unknown_tables'] ?? false),
            disableForeignKeyChecks: (bool) ($optionsRaw['disable_foreign_key_checks'] ?? true),
            fakerLocale: is_string($optionsRaw['faker_locale'] ?? null) ? $optionsRaw['faker_locale'] : 'en_US',
        );

        /** @var array<string, mixed> $tablesRaw */
        $tablesRaw = is_array($data['tables'] ?? null) ? $data['tables'] : [];

        $tables = [];

        foreach ($tablesRaw as $tableName => $tableConfig) {
            if (! is_array($tableConfig)) {
                continue;
            }

            /** @var array<string, mixed> $tableConfig */
            $tables[] = $this->mapTableConfig((string) $tableName, $tableConfig);
        }

        // Parse key_remapping section (optional)
        $keyRemapping = null;
        $keyRemappingRaw = $data['key_remapping'] ?? null;
        if (is_array($keyRemappingRaw)) {
            /** @var array<string, mixed> $keyRemappingRaw */
            $keyRemapping = $this->mapKeyRemappingConfig($keyRemappingRaw);
        }

        return new CloningConfigData(
            version: $version,
            connectionName: $connectionName,
            options: $options,
            tables: $tables,
            keyRemapping: $keyRemapping,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mapTableConfig(string $tableName, array $config): TableCloningConfigData
    {
        /** @var array<string, mixed> $rowsRaw */
        $rowsRaw = is_array($config['rows'] ?? null) ? $config['rows'] : [];

        $rowStrategy = is_string($rowsRaw['strategy'] ?? null) ? $rowsRaw['strategy'] : 'full';
        $rowLimit = is_int($rowsRaw['limit'] ?? null) ? $rowsRaw['limit'] : null;
        $sortBy = is_string($rowsRaw['sort_by'] ?? null) ? $rowsRaw['sort_by'] : null;

        $rows = new TableRowConfigData(
            strategy: $rowStrategy,
            limit: $rowLimit,
            sortBy: $sortBy,
        );

        /** @var array<string, mixed> $columnsRaw */
        $columnsRaw = is_array($config['columns'] ?? null) ? $config['columns'] : [];

        $columns = [];

        foreach ($columnsRaw as $columnName => $columnConfig) {
            if (! is_array($columnConfig)) {
                continue;
            }

            /** @var array<string, mixed> $columnConfig */
            $columns[] = $this->mapColumnConfig((string) $columnName, $columnConfig);
        }

        return new TableCloningConfigData(
            tableName: $tableName,
            rows: $rows,
            columns: $columns,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapKeyRemappingConfig(array $data): ?KeyRemappingConfigData
    {
        $tablesRaw = $data['tables'] ?? null;
        if (! is_array($tablesRaw) || $tablesRaw === []) {
            return null;
        }

        $tables = [];
        foreach ($tablesRaw as $tableEntry) {
            if (! is_array($tableEntry)) {
                continue;
            }

            /** @var array<string, mixed> $tableEntry */
            $table = is_string($tableEntry['table'] ?? null) ? $tableEntry['table'] : '';
            $primaryKey = is_string($tableEntry['primary_key'] ?? null) ? $tableEntry['primary_key'] : '';
            $strategyRaw = is_string($tableEntry['strategy'] ?? null) ? $tableEntry['strategy'] : 'random_integer';
            $strategy = KeyRemappingStrategy::tryFrom($strategyRaw) ?? KeyRemappingStrategy::RandomInteger;
            $rangeMin = is_int($tableEntry['range_min'] ?? null) ? $tableEntry['range_min'] : 100000;
            $rangeMax = is_int($tableEntry['range_max'] ?? null) ? $tableEntry['range_max'] : 9999999;

            $fks = [];
            $fksRaw = $tableEntry['foreign_keys'] ?? [];
            if (is_array($fksRaw)) {
                foreach ($fksRaw as $fkEntry) {
                    if (! is_array($fkEntry)) {
                        continue;
                    }

                    /** @var array<string, mixed> $fkEntry */
                    $fks[] = new KeyRemappingForeignKeyData(
                        table: is_string($fkEntry['table'] ?? null) ? $fkEntry['table'] : '',
                        column: is_string($fkEntry['column'] ?? null) ? $fkEntry['column'] : '',
                        selfReferential: (bool) ($fkEntry['self_referential'] ?? false),
                    );
                }
            }

            if ($table === '') {
                continue;
            }

            if ($primaryKey === '') {
                continue;
            }

            $tables[] = new KeyRemappingTableData(
                table: $table,
                primaryKey: $primaryKey,
                strategy: $strategy,
                rangeMin: $rangeMin,
                rangeMax: $rangeMax,
                foreignKeys: $fks,
            );
        }

        if ($tables === []) {
            return null;
        }

        return new KeyRemappingConfigData(tables: $tables);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mapColumnConfig(string $columnName, array $config): ColumnCloningConfigData
    {
        $strategy = is_string($config['strategy'] ?? null) ? $config['strategy'] : 'keep';

        $fakerMethod = null;
        $fakerArguments = [];
        $hashAlgorithm = null;
        $hashSalt = null;
        $maskChar = null;
        $visibleChars = null;
        $preserveFormat = null;
        $staticValue = null;

        switch ($strategy) {
            case 'fake':
                $fakerMethod = is_string($config['faker_method'] ?? null) ? $config['faker_method'] : null;
                $fakerArgs = $config['faker_arguments'] ?? [];
                /** @var list<scalar> $fakerArguments */
                $fakerArguments = is_array($fakerArgs) ? array_values($fakerArgs) : [];
                break;

            case 'hash':
                $hashAlgorithm = is_string($config['algorithm'] ?? null) ? $config['algorithm'] : null;
                $rawSalt = $config['salt'] ?? null;
                $hashSalt = is_scalar($rawSalt) ? (string) $rawSalt : null;
                break;

            case 'mask':
                $maskChar = is_string($config['mask_char'] ?? null) ? $config['mask_char'] : null;
                $visibleChars = is_int($config['visible_chars'] ?? null) ? $config['visible_chars'] : null;
                $preserveFormat = isset($config['preserve_format']) ? (bool) $config['preserve_format'] : null;
                break;

            case 'static':
                $rawValue = $config['value'] ?? null;
                $staticValue = is_scalar($rawValue) ? (string) $rawValue : null;
                break;
        }

        return new ColumnCloningConfigData(
            columnName: $columnName,
            strategy: $strategy,
            fakerMethod: $fakerMethod,
            fakerArguments: $fakerArguments,
            hashAlgorithm: $hashAlgorithm,
            hashSalt: $hashSalt,
            maskChar: $maskChar,
            visibleChars: $visibleChars,
            preserveFormat: $preserveFormat,
            staticValue: $staticValue,
        );
    }
}
