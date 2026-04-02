<?php

declare(strict_types=1);

use App\Enums\KeyRemappingStrategy;

it('returns both strategy values', function (): void {
    expect(KeyRemappingStrategy::values())->toBe(['random_integer', 'new_uuid']);
});

it('creates from valid string values', function (): void {
    expect(KeyRemappingStrategy::from('random_integer'))->toBe(KeyRemappingStrategy::RandomInteger)
        ->and(KeyRemappingStrategy::from('new_uuid'))->toBe(KeyRemappingStrategy::NewUuid);
});

it('tryFrom returns null for unknown value', function (): void {
    expect(KeyRemappingStrategy::tryFrom('unknown'))->toBeNull();
});
