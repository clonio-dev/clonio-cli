<?php

declare(strict_types=1);

namespace App\Services\SqlDump\Dialects;

use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\TableSchemaData;

class SqlServerDumpDialect extends AbstractDumpDialect
{
    public function __construct(private readonly string $schema = 'dbo') {}

    protected function dialectDisplayName(): string
    {
        return 'SQL Server';
    }

    public function quoteIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }

    protected function qualifiedTable(string $table): string
    {
        return $this->quoteIdentifier($this->schema).'.'.$this->quoteIdentifier($table);
    }

    public function preamble(bool $disableFkChecks): string
    {
        return '';
    }

    public function postamble(bool $disableFkChecks, array $tables): string
    {
        return '';
    }

    protected function binaryLiteral(string $hex): string
    {
        return '0x'.$hex;
    }

    protected function stringLiteral(string $value): string
    {
        return "N'".str_replace("'", "''", $value)."'";
    }

    public function dropTable(string $table): string
    {
        return sprintf(
            "IF OBJECT_ID(N'%s', N'U') IS NOT NULL DROP TABLE %s;\n",
            $this->objectIdRef($table),
            $this->qualifiedTable($table),
        );
    }

    public function createTable(TableSchemaData $table, bool $ifNotExists): string
    {
        $ddl = parent::createTable($table, false);

        if (! $ifNotExists) {
            return $ddl;
        }

        return sprintf(
            "IF OBJECT_ID(N'%s', N'U') IS NULL\n%s",
            $this->objectIdRef($table->name),
            $ddl,
        );
    }

    /** Unquoted `[schema].[table]` reference used inside OBJECT_ID(N'...'). */
    private function objectIdRef(string $table): string
    {
        return $this->quoteIdentifier($this->schema).'.'.$this->quoteIdentifier($table);
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function mapColumnType(ColumnSchemaData $column): string
    {
        $unsigned = $column->unsigned;

        return match ($this->normalize($column->type)) {
            'boolean' => 'BIT',
            'tinyint' => 'TINYINT',
            'smallint' => 'SMALLINT',
            'int' => $unsigned ? 'BIGINT' : 'INT',
            'bigint' => $unsigned ? 'DECIMAL(20,0)' : 'BIGINT',
            'float' => 'REAL',
            'double' => 'FLOAT',
            'decimal' => 'DECIMAL(20,6)',
            'varchar', 'enum' => 'NVARCHAR('.self::DEFAULT_VARCHAR_LENGTH.')',
            'char' => 'NCHAR('.self::DEFAULT_VARCHAR_LENGTH.')',
            'text', 'json' => 'NVARCHAR(MAX)',
            'date' => 'DATE',
            'datetime' => 'DATETIME2',
            'timestamp' => 'DATETIMEOFFSET',
            'time' => 'TIME',
            'blob' => 'VARBINARY(MAX)',
            'uuid' => 'UNIQUEIDENTIFIER',
            default => 'NVARCHAR(MAX)',
        };
    }
}
