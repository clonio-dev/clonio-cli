<?php

declare(strict_types=1);

use App\Services\Schema\ColumnTypeParser;

it('extracts a character length from a single-argument string type', function (): void {
    expect(ColumnTypeParser::parse('varchar(255)'))->toBe(['length' => 255, 'precision' => null, 'scale' => null])
        ->and(ColumnTypeParser::parse('VARCHAR(36)'))->toBe(['length' => 36, 'precision' => null, 'scale' => null])
        ->and(ColumnTypeParser::parse('char(10)'))->toBe(['length' => 10, 'precision' => null, 'scale' => null]);
});

it('extracts precision and scale from a two-argument numeric type', function (): void {
    expect(ColumnTypeParser::parse('decimal(10,2)'))->toBe(['length' => null, 'precision' => 10, 'scale' => 2])
        ->and(ColumnTypeParser::parse('numeric(20, 6)'))->toBe(['length' => null, 'precision' => 20, 'scale' => 6]);
});

it('treats a single argument on a numeric type as precision with zero scale', function (): void {
    expect(ColumnTypeParser::parse('decimal(8)'))->toBe(['length' => null, 'precision' => 8, 'scale' => 0]);
});

it('returns all null for types without a modifier', function (): void {
    expect(ColumnTypeParser::parse('int unsigned'))->toBe(['length' => null, 'precision' => null, 'scale' => null])
        ->and(ColumnTypeParser::parse('text'))->toBe(['length' => null, 'precision' => null, 'scale' => null])
        ->and(ColumnTypeParser::parse('timestamp'))->toBe(['length' => null, 'precision' => null, 'scale' => null]);
});
