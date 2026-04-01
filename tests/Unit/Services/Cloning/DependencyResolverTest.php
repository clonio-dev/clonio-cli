<?php

declare(strict_types=1);

use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Services\Cloning\DependencyResolver;

function makeSchemaForSort(): DatabaseSchemaData
{
    // orders -> users (orders depends on users via user_id FK)
    return new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'orders',
                columns: [],
                foreignKeys: [
                    new ForeignKeyData(columnName: 'user_id', referencedTable: 'users', referencedColumn: 'id'),
                ],
            ),
            new TableSchemaData(
                name: 'order_items',
                columns: [],
                foreignKeys: [
                    new ForeignKeyData(columnName: 'order_id', referencedTable: 'orders', referencedColumn: 'id'),
                ],
            ),
        ],
    );
}

it('sorts tables in topological order (parents before children)', function (): void {
    $resolver = new DependencyResolver;
    $schema = makeSchemaForSort();

    $sorted = $resolver->sort($schema, ['order_items', 'orders', 'users']);

    // users must come before orders, orders before order_items
    $usersIdx = array_search('users', $sorted, true);
    $ordersIdx = array_search('orders', $sorted, true);
    $itemsIdx = array_search('order_items', $sorted, true);

    expect($usersIdx)->toBeLessThan($ordersIdx);
    expect($ordersIdx)->toBeLessThan($itemsIdx);
});

it('returns tables in original order when there are no FKs', function (): void {
    $resolver = new DependencyResolver;
    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(name: 'a', columns: [], foreignKeys: []),
            new TableSchemaData(name: 'b', columns: [], foreignKeys: []),
        ],
    );

    $sorted = $resolver->sort($schema, ['a', 'b']);
    expect($sorted)->toBe(['a', 'b']);
});

it('computes cascade exclusions transitively', function (): void {
    $resolver = new DependencyResolver;
    $schema = makeSchemaForSort();

    // Exclude 'users' — 'orders' depends on it, 'order_items' depends on 'orders'
    $cascade = $resolver->computeCascadeExclusions($schema, ['users'], ['users', 'orders', 'order_items']);

    expect($cascade)->toContain('orders');
    expect($cascade)->toContain('order_items');
});

it('returns empty cascade when no tables depend on excluded', function (): void {
    $resolver = new DependencyResolver;
    $schema = makeSchemaForSort();

    // Exclude order_items — nothing depends on it
    $cascade = $resolver->computeCascadeExclusions($schema, ['order_items'], ['users', 'orders', 'order_items']);

    expect($cascade)->toBe([]);
});

it('includes not-found tables at the end of sorted list', function (): void {
    $resolver = new DependencyResolver;
    $schema = new DatabaseSchemaData(databaseName: 'testdb', tables: []);

    $sorted = $resolver->sort($schema, ['ghost_table', 'another_ghost']);
    expect($sorted)->toBe(['ghost_table', 'another_ghost']);
});
