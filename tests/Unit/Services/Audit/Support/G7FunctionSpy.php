<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit\Support;

/**
 * Call recorder for the namespaced function spies in G7FunctionSpies.php.
 */
final class G7FunctionSpy
{
    /** @var array<string, list<array<int|string, mixed>>> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}
