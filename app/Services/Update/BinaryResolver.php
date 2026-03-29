<?php

declare(strict_types=1);

namespace App\Services\Update;

use Phar;
use RuntimeException;

class BinaryResolver
{
    /**
     * @return array{string, string} [absolute path to current binary, release filename to download]
     */
    public function resolve(): array
    {
        if (PHP_SAPI === 'micro') {
            return [PHP_BINARY, $this->detectBinaryFilename()];
        }

        $pharPath = Phar::running(false);

        throw_if($pharPath === '', RuntimeException::class, 'Cannot determine the current binary path.');

        return [$pharPath, 'clonio.phar'];
    }

    private function detectBinaryFilename(): string
    {
        $arch = php_uname('m');

        if ($arch === 'arm64') {
            $arch = 'aarch64';
        }

        $os = match (strtolower(PHP_OS_FAMILY)) {
            'linux' => 'linux',
            'darwin' => 'macos',
            default => throw new RuntimeException('Unsupported OS: '.PHP_OS_FAMILY),
        };

        return sprintf('clonio-%s-%s', $os, $arch);
    }
}
