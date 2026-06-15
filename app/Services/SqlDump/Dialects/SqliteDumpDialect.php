<?php

declare(strict_types=1);

namespace App\Services\SqlDump\Dialects;

use App\Data\Schema\ColumnSchemaData;

class SqliteDumpDialect extends AbstractDumpDialect
{
    protected function dialectDisplayName(): string
    {
        return 'SQLite';
    }

    public function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    protected function qualifiedTable(string $table): string
    {
        return $this->quoteIdentifier($table);
    }

    protected function insertKeyword(): string
    {
        return 'INSERT OR REPLACE INTO';
    }

    public function preamble(bool $disableFkChecks): string
    {
        return $disableFkChecks ? "PRAGMA foreign_keys = OFF;\n\n" : '';
    }

    public function postamble(bool $disableFkChecks, array $tables): string
    {
        return $disableFkChecks ? "\nPRAGMA foreign_keys = ON;\n" : '';
    }

    protected function binaryLiteral(string $hex): string
    {
        return "X'".$hex."'";
    }

    protected function mapColumnType(ColumnSchemaData $column): string
    {
        return match ($this->normalize($column->type)) {
            'boolean', 'tinyint', 'smallint', 'int', 'bigint' => 'INTEGER',
            'float', 'double', 'decimal' => 'REAL',
            'blob' => 'BLOB',
            default => 'TEXT',
        };
    }
}
