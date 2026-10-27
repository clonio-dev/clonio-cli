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
 * State lives in the autoloaded FilePermissionOverride and HrtimeOverride classes.
 * This file holds no class of its own. It is loaded with require_once from the
 * command tests before any test runs, so the namespaced functions exist before the
 * first call is resolved and cached.
 */

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
