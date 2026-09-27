<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use Phar;

/**
 * Detects whether the app is running from a read-only packaged artefact —
 * a PHAR or an SPC-compiled standalone binary (PHP micro SAPI) — as opposed
 * to a normal `php clonio` invocation from source.
 *
 * Packaged runtimes cannot write inside their own archive/binary, so
 * `storage_path()` (which defaults to `base_path('storage')`) resolves to a
 * `phar://` stream that only supports reads. Anything that writes through it
 * — including Laravel's own emergency log fallback in
 * `Illuminate\Log\LogManager::createEmergencyLogger()` — must be redirected
 * to a real, writable location first (see `AppServiceProvider`).
 */
class PackagedRuntimeDetector
{
    /**
     * Inputs are injectable so every branch is testable; the defaults resolve
     * from the running PHP process (constant only — the non-constant lookup
     * Phar::running() resolves lazily at call time).
     */
    public function __construct(
        private readonly string $sapi = PHP_SAPI,
        private readonly ?string $pharPath = null,
    ) {}

    public function isPackaged(): bool
    {
        if ($this->sapi === 'micro') {
            return true;
        }

        return ($this->pharPath ?? Phar::running(false)) !== '';
    }
}
