<?php

declare(strict_types=1);

namespace App\Services\SqlDump\Dialects;

use App\Data\Schema\ColumnSchemaData;

class PostgreSqlDumpDialect extends AbstractDumpDialect
{
    protected function dialectDisplayName(): string
    {
        return 'PostgreSQL';
    }

    public function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    protected function qualifiedTable(string $table): string
    {
        return $this->quoteIdentifier($table);
    }

    public function preamble(bool $disableFkChecks): string
    {
        return $disableFkChecks ? "SET session_replication_role = 'replica';\n\n" : '';
    }

    public function postamble(bool $disableFkChecks, array $tables): string
    {
        return $disableFkChecks ? "\nSET session_replication_role = 'origin';\n" : '';
    }

    protected function binaryLiteral(string $hex): string
    {
        return "E'\\\\x".$hex."'";
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    protected function mapColumnType(ColumnSchemaData $column): string
    {
        $unsigned = $column->unsigned;

        return match ($this->normalize($column->type)) {
            'boolean' => 'BOOLEAN',
            'tinyint', 'smallint' => 'SMALLINT',
            'int' => $unsigned ? 'BIGINT' : 'INTEGER',
            'bigint' => $unsigned ? 'NUMERIC(20,0)' : 'BIGINT',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'DECIMAL(20,6)',
            'varchar', 'enum' => 'VARCHAR('.self::DEFAULT_VARCHAR_LENGTH.')',
            'char' => 'CHAR('.self::DEFAULT_VARCHAR_LENGTH.')',
            'text' => 'TEXT',
            'date' => 'DATE',
            'datetime' => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMPTZ',
            'time' => 'TIME',
            'json' => 'JSONB',
            'blob' => 'BYTEA',
            'uuid' => 'UUID',
            default => 'TEXT',
        };
    }
}
