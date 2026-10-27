<?php

declare(strict_types=1);

/*
 * Test stand-ins for global functions called unqualified from the command
 * namespaces. PHP resolves such calls to a namespaced function first, so these
 * wrappers let tests control the outcome and otherwise delegate to the real
 * implementation unchanged.
 *
 * - is_readable()/is_writable(): mark a path as unreadable or unwritable without
 *   touching its permission bits. chmod-based simulation fails when the suite runs
 *   as root (e.g. rootless Docker/Podman), because root ignores permission bits.
 * - hrtime(): queue exact clock readings so elapsed-time output is deterministic.
 *
 * Loaded with require_once from the command tests before any test runs, so the
 * namespaced functions exist before the first call is resolved and cached.
 */

namespace Tests\Feature\Commands\Support {
    final class FilePermissionOverride
    {
        /** @var array<string, true> */
        private static array $unreadable = [];

        /** @var array<string, true> */
        private static array $unwritable = [];

        public static function denyRead(string $path): void
        {
            self::$unreadable[$path] = true;
        }

        public static function denyWrite(string $path): void
        {
            self::$unwritable[$path] = true;
        }

        public static function isReadable(string $path): bool
        {
            return ! isset(self::$unreadable[$path]) && \is_readable($path);
        }

        public static function isWritable(string $path): bool
        {
            return ! isset(self::$unwritable[$path]) && \is_writable($path);
        }

        public static function reset(): void
        {
            self::$unreadable = [];
            self::$unwritable = [];
        }
    }

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
}

namespace App\Commands\Connection {
    use Tests\Feature\Commands\Support\FilePermissionOverride;
    use Tests\Feature\Commands\Support\HrtimeOverride;

    if (! function_exists(__NAMESPACE__.'\is_readable')) {
        function is_readable(string $filename): bool
        {
            return FilePermissionOverride::isReadable($filename);
        }
    }

    if (! function_exists(__NAMESPACE__.'\is_writable')) {
        function is_writable(string $filename): bool
        {
            return FilePermissionOverride::isWritable($filename);
        }
    }

    if (! function_exists(__NAMESPACE__.'\hrtime')) {
        /** @return array{0: int, 1: int}|int|float|false */
        function hrtime(bool $as_number = false): array|int|float|false
        {
            return HrtimeOverride::next() ?? \hrtime($as_number);
        }
    }
}

namespace App\Commands\Cloning {
    use Tests\Feature\Commands\Support\FilePermissionOverride;

    if (! function_exists(__NAMESPACE__.'\is_writable')) {
        function is_writable(string $filename): bool
        {
            return FilePermissionOverride::isWritable($filename);
        }
    }
}
