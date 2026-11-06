<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use Illuminate\Support\Facades\Storage;

/**
 * Checks the files in the working directory that hold secrets or the key
 * that decrypts them.
 */
final readonly class SecretFiles
{
    /** clonio.json holds the (encrypted) secrets, .env the key. */
    private const array FILENAMES = ['clonio.json', '.env'];

    public function __construct(
        private string $osFamily = PHP_OS_FAMILY,
    ) {}

    /**
     * The files that group or others can read or write, as path => mode
     * (e.g. 0644). Always empty on platforms without POSIX permissions.
     *
     * @return array<string, int>
     */
    public function exposed(): array
    {
        if ($this->osFamily === 'Windows') {
            return [];
        }

        $exposed = [];

        foreach (self::FILENAMES as $filename) {
            $path = Storage::path($filename);

            if (! is_file($path)) {
                continue;
            }

            $mode = fileperms($path) & 0o777;

            if (($mode & 0o077) !== 0) {
                $exposed[$path] = $mode;
            }
        }

        return $exposed;
    }
}
