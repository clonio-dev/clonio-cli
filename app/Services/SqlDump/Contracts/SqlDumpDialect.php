<?php

declare(strict_types=1);

namespace App\Services\SqlDump\Contracts;

use App\Data\Schema\TableSchemaData;
use DateTimeImmutable;

/**
 * Renders dialect-correct SQL for a database dump.
 *
 * Implementations are stateless apart from immutable construction data (e.g. the
 * SQL Server schema prefix). All methods return SQL fragments terminated with a
 * trailing newline where appropriate so the caller can concatenate them directly.
 */
interface SqlDumpDialect
{
    /** Leading comment header naming the source database and generation time. */
    public function fileHeader(string $sourceDb, DateTimeImmutable $at): string;

    /** Statements emitted once, before any DDL, to disable FK enforcement (when requested). */
    public function preamble(bool $disableFkChecks): string;

    /**
     * Statements emitted once, after all DML, to restore FK enforcement (when requested).
     *
     * @param  list<TableSchemaData>  $tables  Tables in dump order (for sequence resets etc.)
     */
    public function postamble(bool $disableFkChecks, array $tables): string;

    /** `DROP TABLE IF EXISTS` (or dialect equivalent). */
    public function dropTable(string $table): string;

    /** `CREATE TABLE` statement. When $ifNotExists, guards against an existing table. */
    public function createTable(TableSchemaData $table, bool $ifNotExists): string;

    /**
     * One multi-row INSERT statement for the given chunk of rows.
     *
     * @param  list<string>  $columnNames  Column order used in the VALUES tuples
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertBatch(TableSchemaData $table, array $columnNames, array $rows): string;
}
