<?php

declare(strict_types=1);

use App\Services\Cloning\SkippedRow;

it('exposes all skip detail properties', function (): void {
    $row = new SkippedRow(
        tableName: 'users',
        chunkOffset: 1000,
        rowIndex: 42,
        pkSnapshot: ['id' => 8421],
        sqlError: "SQLSTATE[23000]: Duplicate entry '8421' for key 'PRIMARY'",
    );

    expect($row->tableName)->toBe('users');
    expect($row->chunkOffset)->toBe(1000);
    expect($row->rowIndex)->toBe(42);
    expect($row->pkSnapshot)->toBe(['id' => 8421]);
    expect($row->sqlError)->toBe("SQLSTATE[23000]: Duplicate entry '8421' for key 'PRIMARY'");
});

it('accepts null pk snapshot for tables without identifiable primary key', function (): void {
    $row = new SkippedRow(
        tableName: 'audit_blob',
        chunkOffset: 0,
        rowIndex: 0,
        pkSnapshot: null,
        sqlError: 'some error',
    );

    expect($row->pkSnapshot)->toBeNull();
});
