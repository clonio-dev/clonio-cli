<?php

declare(strict_types=1);

namespace App\Services\Update;

use Phar;
use RuntimeException;

class BinaryResolver
{
    /**
     * Environment inputs are injectable so every platform branch is testable;
     * the defaults resolve from the running PHP process (constants only — the
     * non-constant lookups Phar::running()/php_uname() resolve lazily at call).
     */
    public function __construct(
        private readonly string $sapi = PHP_SAPI,
        private readonly string $osFamily = PHP_OS_FAMILY,
        private readonly ?string $machine = null,
        private readonly ?string $pharPath = null,
        private readonly string $binaryPath = PHP_BINARY,
    ) {}

    /**
     * @return array{string, string} [absolute path to current binary, release filename to download]
     */
    public function resolve(): array
    {
        if ($this->sapi === 'micro') {
            return [$this->binaryPath, $this->detectBinaryFilename()];
        }

        $pharPath = $this->pharPath ?? Phar::running(false);

        throw_if($pharPath === '', RuntimeException::class, 'Cannot determine the current binary path.');

        return [$pharPath, 'clonio.phar'];
    }

    private function detectBinaryFilename(): string
    {
        $arch = $this->machine ?? php_uname('m');

        if ($arch === 'arm64') {
            $arch = 'aarch64';
        }

        $os = match (strtolower($this->osFamily)) {
            'linux' => 'linux',
            'darwin' => 'macos',
            default => throw new RuntimeException('Unsupported OS: '.$this->osFamily),
        };

        return sprintf('clonio-%s-%s', $os, $arch);
    }
}
