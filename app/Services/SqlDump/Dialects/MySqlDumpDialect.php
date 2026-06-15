<?php

declare(strict_types=1);

namespace App\Services\SqlDump\Dialects;

use App\Data\Schema\ColumnSchemaData;

class MySqlDumpDialect extends AbstractDumpDialect
{
    protected function dialectDisplayName(): string
    {
        return 'MySQL';
    }

    public function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    protected function qualifiedTable(string $table): string
    {
        return $this->quoteIdentifier($table);
    }

    public function preamble(bool $disableFkChecks): string
    {
        return $disableFkChecks ? "SET FOREIGN_KEY_CHECKS = 0;\n\n" : '';
    }

    public function postamble(bool $disableFkChecks, array $tables): string
    {
        return $disableFkChecks ? "\nSET FOREIGN_KEY_CHECKS = 1;\n" : '';
    }

    protected function binaryLiteral(string $hex): string
    {
        return '0x'.$hex;
    }

    protected function mapColumnType(ColumnSchemaData $column): string
    {
        $unsigned = $column->unsigned;

        return match ($this->normalize($column->type)) {
            'boolean' => 'TINYINT(1)',
            'tinyint' => $unsigned ? 'TINYINT UNSIGNED' : 'TINYINT',
            'smallint' => $unsigned ? 'SMALLINT UNSIGNED' : 'SMALLINT',
            'int' => $unsigned ? 'INT UNSIGNED' : 'INT',
            'bigint' => $unsigned ? 'BIGINT UNSIGNED' : 'BIGINT',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'decimal' => 'DECIMAL('.$this->decimalSpec($column).')',
            'varchar', 'enum' => $this->mysqlVarchar($this->varcharLength($column)),
            'char' => $this->varcharLength($column) <= 255
                ? 'CHAR('.$this->varcharLength($column).')'
                : $this->mysqlVarchar($this->varcharLength($column)),
            'text' => 'LONGTEXT',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'json' => 'JSON',
            'blob' => 'LONGBLOB',
            'uuid' => 'CHAR(36)',
            default => 'TEXT',
        };
    }

    /** MySQL VARCHAR caps at ~16383 utf8mb4 chars; wider columns fall back to LONGTEXT. */
    private function mysqlVarchar(int $length): string
    {
        return $length > 16383 ? 'LONGTEXT' : 'VARCHAR('.$length.')';
    }
}
