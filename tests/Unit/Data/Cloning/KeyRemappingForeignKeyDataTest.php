<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingForeignKeyData;

it('applies a plain foreign key to every row', function (): void {
    $fk = new KeyRemappingForeignKeyData('orders', 'user_id');

    expect($fk->isPolymorphic())->toBeFalse()
        ->and($fk->appliesTo([]))->toBeTrue()
        ->and($fk->appliesTo(['user_id' => 1]))->toBeTrue();
});

it('applies a polymorphic foreign key only to rows of its type', function (array $row, bool $applies): void {
    $fk = new KeyRemappingForeignKeyData('addresses', 'addressable_id', typeColumn: 'addressable_type', typeValue: '7');

    expect($fk->isPolymorphic())->toBeTrue()
        ->and($fk->appliesTo($row))->toBe($applies);
})->with([
    'same string' => [['addressable_type' => '7'], true],
    'same value as integer' => [['addressable_type' => 7], true],
    'other type' => [['addressable_type' => 'user'], false],
    'null type' => [['addressable_type' => null], false],
    'missing type column' => [[], false],
    'non-scalar type' => [['addressable_type' => ['7']], false],
]);
