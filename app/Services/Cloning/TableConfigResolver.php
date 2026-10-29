<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Expands regex table keys in a cloning config against the real source schema.
 *
 * A key under `tables:` is either a literal table name or a slash-delimited
 * regex (`/…/`). Regex keys are expanded to the concrete source tables they
 * match, case-sensitively unless the pattern carries the `i` flag. A literal
 * name names the table with exactly that name; only when no source table has
 * it does it fall back to the one table that matches it case-insensitively
 * (none if several do). The same name resolution applies to every table
 * reference in the config: table keys, the `skip:` list and the key remapping
 * tables and foreign keys. When several entries match the same table, a
 * literal key beats a regex whatever the YAML order (an exact name beats a
 * case-insensitive one; among equals the last one wins). Among regex keys the
 * strictest `rows` win, and a regex `keep` cannot lift another regex's column
 * rule: only a literal key loosens. Column rules merge per column in the same
 * order. The result is a config whose `tables` all carry concrete table names,
 * in YAML order, so the rest of the run (orchestrator, getTable(), dependency
 * sort, audit) keeps working against literal names.
 */
final readonly class TableConfigResolver
{
    private const int RANK_REGEX = 0;

    private const int RANK_CASE_INSENSITIVE = 1;

    private const int RANK_EXACT = 2;

    /**
     * @param  (callable(string, string): void)|null  $onUnmatched  receives each reference that names no source table, and where it comes from
     */
    public function resolve(CloningConfigData $config, DatabaseSchemaData $schema, ?callable $onUnmatched = null): CloningConfigData
    {
        $realNames = $this->realNames($schema);
        $resolveName = $this->nameResolver($realNames);

        /** @var list<array{int, TableCloningConfigData}> $resolved [first matching entry, table] */
        $resolved = [];

        /** @var array<int, int> $matchedEntries */
        $matchedEntries = [];

        /** @var array<string, true> $seen */
        $seen = [];

        // 1. Expand every real table against the config entries. A name that
        //    several schemas share (SQL Server lists all of them) is one table,
        //    configured once, as getTable() finds its first entry only.
        foreach ($schema->tables as $table) {
            $realName = $table->name;

            if (isset($seen[$realName])) {
                continue;
            }

            $seen[$realName] = true; // @pest-mutate-ignore: TrueToFalse
            /** @var list<array{int, int}> $ranked [rank, entry index] of the matching entries */
            $ranked = [];

            foreach ($config->tables as $index => $entry) {
                $rank = $this->rank($entry->tableName, $realName, $resolveName);

                if ($rank !== null) {
                    $ranked[] = [$rank, $index];
                    $matchedEntries[$index] = $index;
                }
            }

            if ($ranked === []) {
                continue;
            }

            // The table sits where it is first mentioned; precedence decides the rest.
            $firstMention = $ranked[0][1];
            sort($ranked);

            $resolved[] = [$firstMention, new TableCloningConfigData(
                tableName: $realName,
                rows: $this->winningRows($config->tables, $ranked),
                columns: $this->mergedColumns($config->tables, $ranked, $table),
            )];
        }

        // 2. Preserve literal entries that name no source table, so a mistyped
        //    or dropped table still reports NotFound as before. A regex that
        //    matches nothing was never a concrete table: drop it, but say so,
        //    as a typo would otherwise leave its tables out without a trace.
        foreach ($config->tables as $index => $entry) {
            if (isset($matchedEntries[$index])) {
                continue;
            }

            if (self::isRegex($entry->tableName)) {
                $this->reportUnmatched($entry->tableName, 'table key', $onUnmatched);

                continue;
            }

            $resolved[] = [$index, $entry];
        }

        // Keep YAML order: a table sits where it is first mentioned. The sort is
        // stable, so tables first matched by the same entry keep schema order.
        usort($resolved, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $tables = array_map(static fn (array $r): TableCloningConfigData => $r[1], $resolved);

        return new CloningConfigData(
            version: $config->version,
            connectionName: $config->connectionName,
            options: $config->options,
            tables: $tables,
            keyRemapping: $this->resolveKeyRemapping($config->keyRemapping, $resolveName),
            skipTables: $this->resolveSkipTables($config->skipTables, $tables, $realNames, $resolveName, $onUnmatched),
        );
    }

    /**
     * Resolve table references to the source tables they name: a regex expands
     * to every table it matches, a literal name resolves like a table key. A
     * reference that names no source table is reported, as a typo would
     * otherwise leave the tables it meant unskipped without a trace; a literal
     * one is still kept as written.
     *
     * @param  list<string>  $references
     * @param  string  $origin  Where the references come from, e.g. 'skip entry'
     * @param  (callable(string, string): void)|null  $onUnmatched  receives each unmatched reference and $origin
     * @return list<string>
     */
    public function resolveReferences(array $references, DatabaseSchemaData $schema, string $origin, ?callable $onUnmatched = null): array
    {
        $realNames = $this->realNames($schema);

        return $this->expandReferences($references, $realNames, $this->nameResolver($realNames), $origin, $onUnmatched);
    }

    /**
     * @param  list<string>  $references
     * @param  list<string>  $realNames
     * @param  callable(string): string  $resolveName
     * @param  (callable(string, string): void)|null  $onUnmatched
     * @return list<string>
     */
    private function expandReferences(array $references, array $realNames, callable $resolveName, string $origin, ?callable $onUnmatched): array
    {
        $names = [];

        foreach ($references as $reference) {
            if (self::isRegex($reference)) {
                $matched = array_filter($realNames, fn (string $name): bool => $this->matches($reference, $name));
            } else {
                $name = $resolveName($reference);
                $matched = in_array($name, $realNames, true) ? [$name] : [];
            }

            if ($matched === []) {
                $this->reportUnmatched($reference, $origin, $onUnmatched);
                $matched = self::isRegex($reference) ? [] : [$reference];
            }

            foreach ($matched as $name) {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * How strongly a table key claims a table, or null when it does not match:
     * a literal key naming the table exactly beats one that matches it only
     * case-insensitively, and both beat any regex.
     *
     * @param  callable(string): string  $resolveName
     */
    private function rank(string $key, string $realName, callable $resolveName): ?int
    {
        if (self::isRegex($key)) {
            return $this->matches($key, $realName) ? self::RANK_REGEX : null;
        }

        if ($resolveName($key) !== $realName) {
            return null;
        }

        return $key === $realName ? self::RANK_EXACT : self::RANK_CASE_INSENSITIVE;
    }

    /**
     * The rows of the entry with the highest precedence; among literal entries
     * of equal rank the last one in YAML order wins. When only regex entries
     * match, the strictest rows win (`skip`, then `schema`, then `first`/`last`
     * with the smaller limit, then `full`; the last in YAML order on a tie): one
     * pattern can tighten what another sets but never loosen it, so a broad
     * pattern further down cannot copy data that a narrower one withheld. Only
     * a literal entry loosens.
     *
     * @param  list<TableCloningConfigData>  $entries
     * @param  non-empty-list<array{int, int}>  $ranked  [rank, entry index] of the matching entries, in precedence order
     */
    private function winningRows(array $entries, array $ranked): TableRowConfigData
    {
        [$rank, $index] = $ranked[count($ranked) - 1];

        if ($rank !== self::RANK_REGEX) {
            return $entries[$index]->rows;
        }

        $winner = $entries[$ranked[0][1]]->rows;

        foreach ($ranked as [, $candidate]) {
            $rows = $entries[$candidate]->rows;

            if ($this->strictness($rows) >= $this->strictness($winner)) {
                $winner = $rows;
            }
        }

        return $winner;
    }

    /**
     * How much of a table's data a rows strategy withholds: `skip` and `schema`
     * all of it (`skip` the table too), `first` / `last` more the smaller their
     * limit (below any limit when they have none), `full` nothing.
     */
    private function strictness(TableRowConfigData $rows): int
    {
        return match ($rows->strategy) {
            'skip' => 1, // @pest-mutate-ignore: IncrementInteger
            'schema' => 0,
            'first', 'last' => -($rows->limit ?? PHP_INT_MAX),
            default => PHP_INT_MIN,
        };
    }

    /**
     * The column rules of all entries matching a table, merged per column in
     * precedence order: an entry overrides only the columns it names, so e.g.
     * a catch-all regex cannot drop an anonymization rule of another entry.
     * Like rows, a regex can tighten but not loosen: its `keep` does not lift
     * the rule another regex sets for a column, only a literal entry's does.
     * A regex configures a whole family of tables, so its rules only reach the
     * tables that have the column; otherwise the audit would report columns as
     * anonymized that do not exist. Columns keep the order in which they are
     * first merged.
     *
     * @param  list<TableCloningConfigData>  $entries
     * @param  list<array{int, int}>  $ranked  [rank, entry index] of the matching entries, in precedence order
     * @return list<ColumnCloningConfigData>
     */
    private function mergedColumns(array $entries, array $ranked, TableSchemaData $table): array
    {
        $existing = array_map(static fn (ColumnSchemaData $c): string => $c->name, $table->columns);
        $columns = [];

        foreach ($ranked as [$rank, $index]) {
            foreach ($entries[$index]->columns as $column) {
                $name = $column->columnName;

                // Regex entries come first in precedence order, so a rule already
                // merged when a regex entry is reached came from another regex.
                if ($rank === self::RANK_REGEX && (
                    ! in_array($name, $existing, true)
                    || ($column->strategy === 'keep' && ($columns[$name]->strategy ?? 'keep') !== 'keep')
                )) {
                    continue;
                }

                $columns[$name] = $column;
            }
        }

        return array_values($columns);
    }

    /**
     * Key remapping names its tables and foreign keys by literal name; resolve
     * them like table keys so they match the concrete tables.
     *
     * @param  callable(string): string  $resolveName
     */
    private function resolveKeyRemapping(?KeyRemappingConfigData $keyRemapping, callable $resolveName): ?KeyRemappingConfigData
    {
        if (! $keyRemapping instanceof KeyRemappingConfigData) {
            return null;
        }

        return new KeyRemappingConfigData(array_map(
            static fn (KeyRemappingTableData $t): KeyRemappingTableData => new KeyRemappingTableData(
                table: $resolveName($t->table),
                primaryKey: $t->primaryKey,
                strategy: $t->strategy,
                foreignKeys: array_map(
                    static fn (KeyRemappingForeignKeyData $fk): KeyRemappingForeignKeyData => new KeyRemappingForeignKeyData(
                        table: $resolveName($fk->table),
                        column: $fk->column,
                        selfReferential: $fk->selfReferential,
                    ),
                    $t->foreignKeys,
                ),
            ),
            $keyRemapping->tables,
        ));
    }

    /**
     * The tables to skip: the `skip:` list (regex entries expanded, literal
     * entries resolved) plus every source table whose winning entry has
     * `rows.strategy: skip`. A literal key with a typo names no source table:
     * it stays out of the list so the run reports it as not found instead of
     * as skipped, where it would hide that the table it meant is copied.
     *
     * @param  list<string>  $skipList
     * @param  list<TableCloningConfigData>  $tables
     * @param  list<string>  $realNames
     * @param  callable(string): string  $resolveName
     * @param  (callable(string, string): void)|null  $onUnmatched
     * @return list<string>
     */
    private function resolveSkipTables(array $skipList, array $tables, array $realNames, callable $resolveName, ?callable $onUnmatched): array
    {
        $skip = $this->expandReferences($skipList, $realNames, $resolveName, 'skip entry', $onUnmatched);
        $known = array_flip($realNames);

        foreach ($tables as $table) {
            if ($table->rows->strategy === 'skip' && isset($known[$table->tableName])) {
                $skip[] = $table->tableName;
            }
        }

        return array_values(array_unique($skip));
    }

    /** @param  (callable(string, string): void)|null  $onUnmatched */
    private function reportUnmatched(string $reference, string $origin, ?callable $onUnmatched): void
    {
        Log::warning('table_reference_unmatched', ['reference' => $reference, 'origin' => $origin]);

        if ($onUnmatched !== null) {
            $onUnmatched($reference, $origin);
        }
    }

    /**
     * The source table names, each once: SQL Server lists a name once per
     * schema, which would also make its case-insensitive fallback ambiguous.
     *
     * @return list<string>
     */
    private function realNames(DatabaseSchemaData $schema): array
    {
        return array_values(array_unique(array_map(static fn (TableSchemaData $t): string => $t->name, $schema->tables))); // @pest-mutate-ignore: UnwrapArrayValues
    }

    /**
     * Resolves a literal name to its source table, see literalTarget(). Each
     * name is looked up once; the scan over all source tables is the costly part.
     *
     * @param  list<string>  $realNames
     * @return Closure(string): string
     */
    private function nameResolver(array $realNames): Closure
    {
        $targets = [];

        return function (string $name) use (&$targets, $realNames): string {
            return $targets[$name] ??= $this->literalTarget($name, $realNames) ?? $name; // @pest-mutate-ignore: CoalesceEqualToEqual
        };
    }

    /**
     * The single source table a literal name matches case-insensitively, or
     * null when none or several do (e.g. `Users` and `users`). resolveName()
     * falls back to the name itself, so a table with exactly that name always
     * wins over a case-insensitive match.
     *
     * @param  list<string>  $realNames
     */
    private function literalTarget(string $name, array $realNames): ?string
    {
        $candidates = array_values(array_filter($realNames, static fn (string $real): bool => strcasecmp($name, $real) === 0));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** A table reference wrapped in `/…/` is a regex, any other is a literal name. */
    public static function isRegex(string $reference): bool
    {
        return str_starts_with($reference, '/');
    }

    /** A regex reference that does not compile; a literal name never is one. */
    public static function isInvalidPattern(string $reference): bool
    {
        return self::isRegex($reference) && @preg_match($reference, '') === false; // @pest-mutate-ignore: EmptyStringToNotEmpty
    }

    /**
     * Whether a regex matches a table name. A pattern that fails while matching
     * (backtrack limit, a table name that is not UTF-8 under the `u` flag) must
     * not count as "no match": that would leave the table unconfigured or
     * unskipped without a trace.
     *
     * @throws RuntimeException
     */
    private function matches(string $pattern, string $name): bool
    {
        $result = @preg_match($pattern, $name);

        if ($result === false) {
            throw new RuntimeException(sprintf("Table pattern '%s' failed on table '%s': %s", $pattern, $name, preg_last_error_msg()));
        }

        return $result === 1;
    }
}
