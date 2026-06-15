<?php

declare(strict_types=1);

use App\Enums\DatabaseConnectionType;

it('exposes a human label for every case', function (DatabaseConnectionType $type, string $label): void {
    expect($type->label())->toBe($label);
})->with([
    [DatabaseConnectionType::Mysql, 'MySQL'],
    [DatabaseConnectionType::MariaDB, 'MariaDB'],
    [DatabaseConnectionType::PostgreSQL, 'PostgreSQL'],
    [DatabaseConnectionType::SqlServer, 'SQL Server'],
    [DatabaseConnectionType::Sqlite, 'SQLite'],
    [DatabaseConnectionType::Dump, 'Dump (SQL file)'],
]);

it('returns the default port per driver', function (DatabaseConnectionType $type, ?int $port): void {
    expect($type->defaultPort())->toBe($port);
})->with([
    [DatabaseConnectionType::Mysql, 3306],
    [DatabaseConnectionType::MariaDB, 3306],
    [DatabaseConnectionType::PostgreSQL, 5432],
    [DatabaseConnectionType::SqlServer, 1433],
    [DatabaseConnectionType::Sqlite, null],
    [DatabaseConnectionType::Dump, null],
]);

it('requires a schema only for PostgreSQL', function (): void {
    expect(DatabaseConnectionType::PostgreSQL->requiresSchema())->toBeTrue()
        ->and(DatabaseConnectionType::Mysql->requiresSchema())->toBeFalse()
        ->and(DatabaseConnectionType::Dump->requiresSchema())->toBeFalse();
});

it('requires network config for every driver except SQLite and Dump', function (DatabaseConnectionType $type, bool $expected): void {
    expect($type->requiresNetworkConfig())->toBe($expected);
})->with([
    [DatabaseConnectionType::Mysql, true],
    [DatabaseConnectionType::MariaDB, true],
    [DatabaseConnectionType::PostgreSQL, true],
    [DatabaseConnectionType::SqlServer, true],
    [DatabaseConnectionType::Sqlite, false],
    [DatabaseConnectionType::Dump, false],
]);

it('identifies the dump pseudo-type', function (): void {
    expect(DatabaseConnectionType::Dump->isDump())->toBeTrue()
        ->and(DatabaseConnectionType::Mysql->isDump())->toBeFalse()
        ->and(DatabaseConnectionType::Dump->isDialect())->toBeFalse()
        ->and(DatabaseConnectionType::Sqlite->isDialect())->toBeTrue();
});

it('lists all string values including dump', function (): void {
    expect(DatabaseConnectionType::values())->toBe(['mysql', 'mariadb', 'pgsql', 'sqlsrv', 'sqlite', 'dump']);
});

it('lists dialect values excluding dump', function (): void {
    expect(DatabaseConnectionType::dialectValues())->toBe(['mysql', 'mariadb', 'pgsql', 'sqlsrv', 'sqlite'])
        ->and(DatabaseConnectionType::dialectValues())->not->toContain('dump');
});

it('lists all human labels', function (): void {
    expect(DatabaseConnectionType::labels())->toBe(['MySQL', 'MariaDB', 'PostgreSQL', 'SQL Server', 'SQLite', 'Dump (SQL file)']);
});

it('resolves a case from its label', function (): void {
    expect(DatabaseConnectionType::fromLabel('PostgreSQL'))->toBe(DatabaseConnectionType::PostgreSQL)
        ->and(DatabaseConnectionType::fromLabel('Dump (SQL file)'))->toBe(DatabaseConnectionType::Dump);
});

it('throws a ValueError for an unknown label', function (): void {
    expect(fn () => DatabaseConnectionType::fromLabel('Oracle'))
        ->toThrow(ValueError::class, "'Oracle' is not a valid label");
});
