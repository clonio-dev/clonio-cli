<?php

declare(strict_types=1);

use App\Data\Cloning\TableRunPhase;

it('marks only the pre-loop phases as one-shot', function (): void {
    expect(TableRunPhase::CountingRows->isOneShot())->toBeTrue();
    expect(TableRunPhase::DisableFkChecks->isOneShot())->toBeTrue();
    expect(TableRunPhase::Clear->isOneShot())->toBeTrue();

    expect(TableRunPhase::Select->isOneShot())->toBeFalse();
    expect(TableRunPhase::Transform->isOneShot())->toBeFalse();
    expect(TableRunPhase::Insert->isOneShot())->toBeFalse();
    expect(TableRunPhase::Loop->isOneShot())->toBeFalse();
});

it('gives every phase a human-readable label distinct from its raw value', function (): void {
    foreach (TableRunPhase::cases() as $phase) {
        expect($phase->label())->not->toBe('')
            ->and($phase->label())->not->toBe($phase->value);
    }

    expect(TableRunPhase::CountingRows->label())->toBe('counting rows');
    expect(TableRunPhase::DisableFkChecks->label())->toBe('disabling FK checks');
});
