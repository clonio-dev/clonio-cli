<?php

declare(strict_types=1);

namespace App\Services\SqlDump;

use App\Enums\DatabaseConnectionType;
use App\Services\SqlDump\Contracts\SqlDumpDialect;
use App\Services\SqlDump\Dialects\MariaDbDumpDialect;
use App\Services\SqlDump\Dialects\MySqlDumpDialect;
use App\Services\SqlDump\Dialects\PostgreSqlDumpDialect;
use App\Services\SqlDump\Dialects\SqliteDumpDialect;
use App\Services\SqlDump\Dialects\SqlServerDumpDialect;
use InvalidArgumentException;

class DumpDialectFactory
{
    /**
     * @param  string  $sqlServerSchema  Schema prefix for SQL Server output (derived from the source).
     */
    public function make(DatabaseConnectionType $dialect, string $sqlServerSchema = 'dbo'): SqlDumpDialect
    {
        return match ($dialect) {
            DatabaseConnectionType::Mysql => new MySqlDumpDialect,
            DatabaseConnectionType::MariaDB => new MariaDbDumpDialect,
            DatabaseConnectionType::PostgreSQL => new PostgreSqlDumpDialect,
            DatabaseConnectionType::SqlServer => new SqlServerDumpDialect($sqlServerSchema),
            DatabaseConnectionType::Sqlite => new SqliteDumpDialect,
            DatabaseConnectionType::Dump => throw new InvalidArgumentException('`dump` is not a valid SQL dialect.'),
        };
    }
}
