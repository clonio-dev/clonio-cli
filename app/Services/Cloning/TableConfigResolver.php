<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;

/**
 * Expands regex table keys in a cloning config against the real source schema.
 *
 * A key under `tables:` is either a literal table name or a slash-delimited
 * regex (`/…/`). Regex keys are expanded to the concrete source tables they
 * match; when several entries match the same table, the last one in YAML order
 * wins. The result is a config whose `tables` all carry concrete table names,
 * so the rest of the run (orchestrator, getTable(), dependency sort, audit)
 * keeps working against literal names.
 */
final readonly class TableConfigResolver
{
    public function resolve(CloningConfigData $config, DatabaseSchemaData $schema): CloningConfigData
    {
        $realNames = array_map(
            static fn (TableSchemaData $t): string => $t->name,
            $schema->tables,
        );

        /** @var array<string, TableCloningConfigData> $resolved */
        $resolved = [];

        // 1. Expand every real table against the config entries (last match wins).
        foreach ($realNames as $realName) {
            $winner = null;

            foreach ($config->tables as $entry) {
                if ($this->matches($entry->tableName, $realName)) {
                    $winner = $entry;
                }
            }

            if ($winner instanceof TableCloningConfigData) {
                $resolved[$realName] = new TableCloningConfigData(
                    tableName: $realName,
                    rows: $winner->rows,
                    columns: $winner->columns,
                );
            }
        }

        // 2. Preserve literal entries that match no source table, so a mistyped
        //    or dropped table still reports NotFound as before. (Regex keys that
        //    match nothing were never a concrete table — drop them.)
        foreach ($config->tables as $entry) {
            if ($this->isRegex($entry->tableName)) {
                continue;
            }

            $matchedReal = array_any(
                $realNames,
                fn (string $realName): bool => $this->matches($entry->tableName, $realName),
            );

            if ($matchedReal) {
                continue;
            }

            if (isset($resolved[$entry->tableName])) {
                continue;
            }

            $resolved[$entry->tableName] = $entry;
        }

        return new CloningConfigData(
            version: $config->version,
            connectionName: $config->connectionName,
            options: $config->options,
            tables: array_values($resolved),
            keyRemapping: $config->keyRemapping,
            skipTables: $this->resolveSkipTables($config, $realNames, array_values($resolved)),
        );
    }

    /**
     * @param  list<string>  $realNames
     * @param  list<TableCloningConfigData>  $resolvedTables
     * @return list<string>
     */
    private function resolveSkipTables(CloningConfigData $config, array $realNames, array $resolvedTables): array
    {
        /** @var list<string> $skip */
        $skip = [];

        // Carry over the configured skip list, expanding any regex entries.
        foreach ($config->skipTables as $entry) {
            if ($this->isRegex($entry)) {
                foreach ($realNames as $realName) {
                    if ($this->matches($entry, $realName) && ! in_array($realName, $skip, true)) {
                        $skip[] = $realName;
                    }
                }

                continue;
            }

            if (! in_array($entry, $skip, true)) {
                $skip[] = $entry;
            }
        }

        // Any resolved table whose winning rule is `rows.strategy: skip`.
        foreach ($resolvedTables as $table) {
            if ($table->rows->strategy === 'skip' && ! in_array($table->tableName, $skip, true)) {
                $skip[] = $table->tableName;
            }
        }

        return $skip;
    }

    /**
     * Match a config key against a concrete table name: slash-delimited regex,
     * otherwise a case-insensitive literal comparison.
     */
    private function matches(string $key, string $tableName): bool
    {
        if ($this->isRegex($key)) {
            return preg_match($key, $tableName) === 1;
        }

        return strcasecmp($key, $tableName) === 0;
    }

    private function isRegex(string $key): bool
    {
        return str_starts_with($key, '/');
    }
}
