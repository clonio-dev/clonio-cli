<?php

declare(strict_types=1);

use App\Services\Cloning\TablePattern;

it('tells a pattern from a table name', function (string $name, bool $isPattern): void {
    expect(TablePattern::isPattern($name))->toBe($isPattern);
})->with([
    'pattern' => ['/^logs_/', true],
    'pattern with flags' => ['/^logs_/iu', true],
    'pattern with a newline' => ["/^a\nb/", true],
    'plain name' => ['logs', false],
    'leading slash only' => ['/logs', false],
    'empty pattern' => ['//', false],
    'flag that is no letter' => ['/^logs_/1', false],
    'slash inside a name' => ['x/^logs_/', false],
]);

it('tells whether a pattern compiles', function (): void {
    expect(TablePattern::compiles('/^logs_/'))->toBeTrue()
        ->and(TablePattern::compiles('/^logs_(/'))->toBeFalse();
});

it('matches a table name against a pattern', function (): void {
    expect(TablePattern::matches('/^logs_/', 'logs_2024'))->toBeTrue()
        ->and(TablePattern::matches('/^logs_/', 'Logs_2024'))->toBeFalse()
        ->and(TablePattern::matches('/^logs_/i', 'Logs_2024'))->toBeTrue();
});

it('rejects a pattern that does not compile', function (): void {
    TablePattern::matches('/^logs_(/', 'logs_2024');
})->throws(InvalidArgumentException::class, 'Table pattern "/^logs_(/" is not a valid regular expression.');
