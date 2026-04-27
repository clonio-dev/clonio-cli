<?php

declare(strict_types=1);

use App\Data\Schema\ColumnSchemaData;
use App\Exceptions\UnsupportedKeyColumnTypeException;
use App\Services\Cloning\KeyTypeBoundsResolver;

function makeIntCol(string $type, bool $unsigned = false): ColumnSchemaData
{
    return new ColumnSchemaData(
        name: 'id',
        type: $type,
        nullable: false,
        default: null,
        isPrimary: true,
        unsigned: $unsigned,
    );
}

it('returns 127 for signed tinyint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('tinyint')))->toBe(127);
});

it('returns 255 for unsigned tinyint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('tinyint', true)))->toBe(255);
});

it('returns 32767 for signed smallint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('smallint')))->toBe(32767);
});

it('returns 65535 for unsigned smallint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('smallint', true)))->toBe(65535);
});

it('returns 8388607 for signed mediumint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('mediumint')))->toBe(8388607);
});

it('returns 16777215 for unsigned mediumint', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('mediumint', true)))->toBe(16777215);
});

it('returns 2147483647 for signed int', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('int')))->toBe(2147483647);
});

it('returns 4294967295 for unsigned int', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('int', true)))->toBe(4294967295);
});

it('treats integer alias same as int', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('integer')))->toBe(2147483647);
});

it('returns PHP_INT_MAX for bigint regardless of signedness', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('bigint')))->toBe(PHP_INT_MAX)
        ->and((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('bigint', true)))->toBe(PHP_INT_MAX);
});

it('is case-insensitive on type name', function (): void {
    expect((new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('INT', true)))->toBe(4294967295);
});

it('throws UnsupportedKeyColumnTypeException for non-integer types', function (): void {
    (new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('varchar'));
})->throws(UnsupportedKeyColumnTypeException::class);

it('throws UnsupportedKeyColumnTypeException for char (UUID columns must use new_uuid)', function (): void {
    (new KeyTypeBoundsResolver)->ceilingFor(makeIntCol('char'));
})->throws(UnsupportedKeyColumnTypeException::class);
