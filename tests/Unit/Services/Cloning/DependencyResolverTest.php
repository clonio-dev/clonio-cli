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

it('skips cascade tables that are absent from the schema', function (): void {
    $resolver = new DependencyResolver;
    $schema = makeSchemaForSort();

    // 'ghost' is in the processed list but not in the schema → skipped without error
    $cascade = $resolver->computeCascadeExclusions($schema, ['users'], ['orders', 'ghost']);

    expect($cascade)->toBe(['orders']);
});

it('includes not-found tables at the end of sorted list', function (): void {
    $resolver = new DependencyResolver;
    $schema = new DatabaseSchemaData(databaseName: 'testdb', tables: []);

    $sorted = $resolver->sort($schema, ['ghost_table', 'another_ghost']);
    expect($sorted)->toBe(['ghost_table', 'another_ghost']);
});

it('keeps resolving dependencies after a table missing from the schema', function (): void {
    $resolver = new DependencyResolver;

    $sorted = $resolver->sort(makeSchemaForSort(), ['ghost', 'orders', 'users']);

    expect($sorted)->toBe(['ghost', 'users', 'orders']);
});

it('ignores foreign keys to tables outside the processed set', function (): void {
    $resolver = new DependencyResolver;

    // orders references users, but users is not part of the set
    $sorted = $resolver->sort(makeSchemaForSort(), ['orders', 'ghost']);

    expect($sorted)->toBe(['orders', 'ghost']);
});

it('ignores self-referencing foreign keys when sorting', function (): void {
    $resolver = new DependencyResolver;
    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(name: 'categories', columns: [], foreignKeys: [
                new ForeignKeyData(columnName: 'parent_id', referencedTable: 'categories', referencedColumn: 'id'),
            ]),
            new TableSchemaData(name: 'tags', columns: [], foreignKeys: []),
        ],
    );

    expect($resolver->sort($schema, ['categories', 'tags']))->toBe(['categories', 'tags']);
});

it('appends tables caught in a dependency cycle at the end', function (): void {
    $resolver = new DependencyResolver;
    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(name: 'a', columns: [], foreignKeys: [
                new ForeignKeyData(columnName: 'b_id', referencedTable: 'b', referencedColumn: 'id'),
            ]),
            new TableSchemaData(name: 'b', columns: [], foreignKeys: [
                new ForeignKeyData(columnName: 'a_id', referencedTable: 'a', referencedColumn: 'id'),
            ]),
            new TableSchemaData(name: 'c', columns: [], foreignKeys: []),
        ],
    );

    expect($resolver->sort($schema, ['a', 'b', 'c']))->toBe(['c', 'a', 'b']);
});

it('keeps checking cascade tables after one missing from the schema', function (): void {
    $resolver = new DependencyResolver;

    $cascade = $resolver->computeCascadeExclusions(makeSchemaForSort(), ['users'], ['ghost', 'orders']);

    expect($cascade)->toBe(['orders']);
});

it('computes transitive cascades regardless of table order', function (): void {
    $resolver = new DependencyResolver;

    $cascade = $resolver->computeCascadeExclusions(makeSchemaForSort(), ['users'], ['order_items', 'orders', 'users']);

    expect($cascade)->toBe(['orders', 'order_items']);
});

it('adds a cascaded table only once when several of its foreign keys hit excluded tables', function (): void {
    $resolver = new DependencyResolver;
    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(name: 'users', columns: [], foreignKeys: []),
            new TableSchemaData(name: 'products', columns: [], foreignKeys: []),
            new TableSchemaData(name: 'reviews', columns: [], foreignKeys: [
                new ForeignKeyData(columnName: 'user_id', referencedTable: 'users', referencedColumn: 'id'),
                new ForeignKeyData(columnName: 'product_id', referencedTable: 'products', referencedColumn: 'id'),
            ]),
        ],
    );

    $cascade = $resolver->computeCascadeExclusions($schema, ['users', 'products'], ['users', 'products', 'reviews']);

    expect($cascade)->toBe(['reviews']);
});

it('excludes the tables to skip, and with an only list every table outside it', function (array $skip, array $only, array $expected): void {
    expect(DependencyResolver::excludedTables(['a', 'b', 'c'], $skip, $only))->toBe($expected);
})->with([
    'skip only' => [['b', 'ghost'], [], ['b', 'ghost']],
    'only' => [[], ['a'], ['b', 'c']],
    'only and skip' => [['b', 'c'], ['a', 'b'], ['c', 'b']],
    'duplicate skip' => [['b', 'b', 'c'], [], ['b', 'c']],
    'nothing' => [[], [], []],
]);

it('returns the tables excluded by flag and those cascaded from them', function (): void {
    $exclusions = (new DependencyResolver)->exclusions(makeSchemaForSort(), ['users', 'orders', 'order_items'], ['users'], []);

    expect($exclusions)->toBe([['users'], ['orders', 'order_items']]);
});

it('lists only configured tables as excluded by flag, but cascades from every skipped table', function (): void {
    $exclusions = (new DependencyResolver)->exclusions(makeSchemaForSort(), ['orders', 'order_items', 'ghost'], ['tmp', 'users', 'ghost'], []);

    expect($exclusions)->toBe([['ghost'], ['orders', 'order_items']]);
});
