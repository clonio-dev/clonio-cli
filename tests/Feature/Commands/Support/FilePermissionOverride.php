<?php

declare(strict_types=1);

namespace Tests\Feature\Commands\Support;

/**
 * Path-level read/write denials for the namespaced is_readable()/is_writable()
 * stand-ins in CommandFunctionOverrides.php.
 */
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
