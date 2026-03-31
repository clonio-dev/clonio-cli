<?php

declare(strict_types=1);

namespace App\Enums;

use ValueError;

enum DatabaseConnectionType: string
{
    case Mysql = 'mysql';
    case MariaDB = 'mariadb';
    case PostgreSQL = 'pgsql';
    case SqlServer = 'sqlsrv';
    case Sqlite = 'sqlite';

    public function label(): string
    {
        return match ($this) {
            self::Mysql => 'MySQL',
            self::MariaDB => 'MariaDB',
            self::PostgreSQL => 'PostgreSQL',
            self::SqlServer => 'SQL Server',
            self::Sqlite => 'SQLite',
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Mysql, self::MariaDB => 3306,
            self::PostgreSQL => 5432,
            self::SqlServer => 1433,
            self::Sqlite => null,
        };
    }

    public function requiresSchema(): bool
    {
        return $this === self::PostgreSQL;
    }

    public function requiresNetworkConfig(): bool
    {
        return $this !== self::Sqlite;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(static fn (self $case): string => $case->label(), self::cases());
    }

    public static function fromLabel(string $label): self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        throw new ValueError(sprintf("'%s' is not a valid label for ", $label).self::class);
    }
}
