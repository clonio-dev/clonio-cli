<?php

declare(strict_types=1);

namespace App\Services\Database\Tls;

use App\Data\SslConfig;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves certificate paths from clonio.json. PHAR/SPC binaries are read-only archives,
 * so relative paths resolve against the working directory (local disk), never base_path().
 */
class CertificateFiles
{
    public function resolve(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');

            return is_string($home) && $home !== '' ? rtrim($home, '/').substr($path, 1) : $path;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return Storage::disk('local')->path($path);
    }

    /**
     * Resolved paths of configured certificate files that are missing or unreadable.
     *
     * @return list<string>
     */
    public function unreadable(SslConfig $ssl): array
    {
        $unreadable = [];

        foreach ([$ssl->ca, $ssl->cert, $ssl->key] as $path) {
            if ($path === null) {
                continue;
            }

            $resolved = $this->resolve($path);

            if (! is_file($resolved) || ! is_readable($resolved)) {
                $unreadable[] = $resolved;
            }
        }

        return $unreadable;
    }

    /** True when a private key is readable by group or others (POSIX only). */
    public function isKeyExposed(string $path): bool
    {
        $resolved = $this->resolve($path);

        if (PHP_OS_FAMILY === 'Windows' || ! is_file($resolved)) {
            return false;
        }

        $permissions = fileperms($resolved);

        return $permissions !== false && ($permissions & 0o077) !== 0;
    }
}
