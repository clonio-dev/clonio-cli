<?php

declare(strict_types=1);

namespace Tests\Feature\Commands\Support;

/**
 * Queued clock readings for the namespaced hrtime() stand-in in
 * CommandFunctionOverrides.php.
 */
final class HrtimeOverride
{
    /** @var list<int> */
    private static array $readings = [];

    /** Queue nanosecond readings returned, in order, by hrtime(true). */
    public static function queue(int ...$readings): void
    {
        self::$readings = array_values($readings);
    }

    public static function next(): ?int
    {
        return array_shift(self::$readings);
    }

    public static function reset(): void
    {
        self::$readings = [];
    }
}
