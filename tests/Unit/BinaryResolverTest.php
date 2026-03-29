<?php

declare(strict_types=1);

use App\Services\Update\BinaryResolver;

it('throws when not running inside a PHAR or micro binary', function (): void {
    // In a plain CLI test run PHP_SAPI is 'cli' and Phar::running() returns ''
    expect(PHP_SAPI)->not->toBe('micro');

    expect(fn () => (new BinaryResolver)->resolve())
        ->toThrow(RuntimeException::class, 'Cannot determine the current binary path.');
});

it('resolves the correct filename for the current platform', function (): void {
    $arch = php_uname('m');
    $normalizedArch = $arch === 'arm64' ? 'aarch64' : $arch;

    $expectedOs = match (strtolower(PHP_OS_FAMILY)) {
        'linux' => 'linux',
        'darwin' => 'macos',
        default => null,
    };

    if ($expectedOs === null) {
        $this->markTestSkipped('Unsupported OS: '.PHP_OS_FAMILY);
    }

    $expected = "clonio-{$expectedOs}-{$normalizedArch}";

    // Access detectBinaryFilename via reflection since it's private
    $method = new ReflectionMethod(BinaryResolver::class, 'detectBinaryFilename');
    $filename = $method->invoke(new BinaryResolver);

    expect($filename)->toBe($expected);
});

it('normalises arm64 to aarch64 in the binary filename', function (): void {
    if (php_uname('m') !== 'arm64') {
        $this->markTestSkipped('Only relevant on arm64 (macOS Apple Silicon).');
    }

    $method = new ReflectionMethod(BinaryResolver::class, 'detectBinaryFilename');
    $filename = (string) $method->invoke(new BinaryResolver);

    expect($filename)->toContain('aarch64')
        ->and($filename)->not->toContain('arm64');
});
