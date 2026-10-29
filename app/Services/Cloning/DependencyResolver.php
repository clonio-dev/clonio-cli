<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;

class DependencyResolver
{
    /**
     * Given the database schema and a list of table names to process,
     * return them in parent-first topological order (so inserts don't violate FK constraints).
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function sort(DatabaseSchemaData $schema, array $tables): array
    {
        $tableSet = array_flip($tables);

        // Build adjacency: table -> list of tables it depends on (has FK to)
        // Only include dependencies that are within our table set
        /** @var array<string, list<string>> $deps */
        $deps = [];
        /** @var array<string, int> $inDegree */
        $inDegree = [];

        foreach ($tables as $table) {
            $deps[$table] = [];
            $inDegree[$table] = 0;
        }

        foreach ($tables as $table) {
            $tableSchema = $schema->getTable($table);

            if (! $tableSchema instanceof TableSchemaData) {
                continue;
            }

            foreach ($tableSchema->foreignKeys as $fk) {
                $refTable = $fk->referencedTable;

                // Only consider FK to tables within our set, and avoid self-references
                if (isset($tableSet[$refTable]) && $refTable !== $table) {
                    // $table depends on $refTable (refTable must come first)
                    $deps[$table][] = $refTable;
                    $inDegree[$table]++;
                }
            }
        }

        // Kahn's algorithm
        $queue = [];

        foreach ($tables as $table) {
            if ($inDegree[$table] === 0) {
                $queue[] = $table;
            }
        }

        /** @var list<string> $sorted */
        $sorted = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $current;

            // Find tables that depend on current
            foreach ($tables as $table) {
                if (in_array($current, $deps[$table], true)) {
                    $inDegree[$table]--;

                    if ($inDegree[$table] === 0) {
                        $queue[] = $table;
                    }
                }
            }
        }

        // Handle tables not reached (cycles or not in schema) — append them at the end
        $remaining = array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
            $tables,
            static fn (string $t): bool => ! in_array($t, $sorted, true)
        ));

        return array_merge($sorted, $remaining);
    }

    /**
     * The tables a run leaves out by flag: with --only-tables every table not
     * named there, and in any case every table to skip. A skip always holds;
     * --only-tables only narrows the run further.
     *
     * @param  list<string>  $tableNames  The configured tables
     * @param  list<string>  $skipTables
     * @param  list<string>  $onlyTables
     * @return list<string>
     */
    public static function excludedTables(array $tableNames, array $skipTables, array $onlyTables): array
    {
        $notOnly = $onlyTables === []
            ? []
            : array_filter($tableNames, static fn (string $t): bool => ! in_array($t, $onlyTables, true));

        return array_values(array_unique([...$notOnly, ...$skipTables]));
    }

    /**
     * The configured tables a run leaves out, for the run itself and for
     * --dry-run alike: by flag (see excludedTables()) and, cascading from every
     * excluded table, the tables with a foreign key dependency on one of them.
     * A skip entry for a table outside the config still cascades, but is not
     * listed: the run never meant to copy that table, and a regex skip entry
     * would otherwise list every source table it matches.
     *
     * @param  list<string>  $tableNames  The configured tables
     * @param  list<string>  $skipTables
     * @param  list<string>  $onlyTables
     * @return array{list<string>, list<string>} [by flag, by cascade]
     */
    public function exclusions(DatabaseSchemaData $schema, array $tableNames, array $skipTables, array $onlyTables): array
    {
        $excluded = self::excludedTables($tableNames, $skipTables, $onlyTables);

        return [
            array_values(array_intersect($excluded, $tableNames)),
            $this->computeCascadeExclusions($schema, $excluded, $tableNames),
        ];
    }

    /**
     * Given excluded tables, find all tables that have FK dependency on them.
     * Returns the additional tables to exclude (cascade set, not including the original excluded tables).
     *
     * @param  list<string>  $excludedTables
     * @param  list<string>  $allTableNames  (tables being processed, not all DB tables)
     * @return list<string>
     */
    public function computeCascadeExclusions(DatabaseSchemaData $schema, array $excludedTables, array $allTableNames): array
    {
        $excluded = array_flip($excludedTables);
        /** @var list<string> $cascadeSet */
        $cascadeSet = [];

        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($allTableNames as $table) {
                if (isset($excluded[$table])) {
                    continue;
                }

                $tableSchema = $schema->getTable($table);

                if (! $tableSchema instanceof TableSchemaData) {
                    continue;
                }

                foreach ($tableSchema->foreignKeys as $fk) {
                    if (isset($excluded[$fk->referencedTable])) {
                        $excluded[$table] = true; // @pest-mutate-ignore: TrueToFalse
                        $cascadeSet[] = $table;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return $cascadeSet;
    }
}
