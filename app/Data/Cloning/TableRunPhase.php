<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * The distinct phases tracked during a single table transfer.
 *
 * `CountingRows` / `DisableFkChecks` / `Clear` are one-shot phases that run once
 * before the chunk loop. `Select` / `Transform` / `Insert` are the per-chunk
 * sub-phases, and `Loop` is the whole-loop wall-clock. `cases()` yields them in
 * render order (one-shot first, then the per-chunk sub-phases, then `Loop`).
 */
enum TableRunPhase: string
{
    case CountingRows = 'countingRows';
    case DisableFkChecks = 'disablingFkChecks';
    case Clear = 'clearingData';

    case Select = 'selectData';
    case Transform = 'transformingData';
    case Insert = 'insertingData';
    case Loop = 'loop';

    public function isOneShot(): bool
    {
        return match ($this) {
            self::CountingRows, self::DisableFkChecks, self::Clear => true,
            default => false,
        };
    }

    /** Human-readable, present-continuous label for live progress display. */
    public function label(): string
    {
        return match ($this) {
            self::CountingRows => 'counting rows',
            self::DisableFkChecks => 'disabling FK checks',
            self::Clear => 'clearing data',
            self::Select => 'selecting',
            self::Transform => 'transforming',
            self::Insert => 'inserting',
            self::Loop => 'writing',
        };
    }
}
