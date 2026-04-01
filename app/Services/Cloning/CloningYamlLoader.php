<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
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

        return new CloningConfigData(
            version: $version,
            connectionName: $connectionName,
            options: $options,
            tables: $tables,
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
