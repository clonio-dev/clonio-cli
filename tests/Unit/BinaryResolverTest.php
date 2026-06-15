<?php

declare(strict_types=1);

use App\Services\Update\BinaryResolver;

it('throws when not running inside a PHAR or micro binary', function (): void {
    // cli SAPI + empty Phar path → cannot determine the binary
    $resolver = new BinaryResolver(sapi: 'cli', pharPath: '');

    expect(fn () => $resolver->resolve())
        ->toThrow(RuntimeException::class, 'Cannot determine the current binary path.');
});

it('resolves the PHAR path and filename when running as a PHAR', function (): void {
    $resolver = new BinaryResolver(sapi: 'cli', pharPath: '/opt/clonio/clonio.phar');

    expect($resolver->resolve())->toBe(['/opt/clonio/clonio.phar', 'clonio.phar']);
});

it('resolves the standalone Linux x86_64 binary under the micro SAPI', function (): void {
    $resolver = new BinaryResolver(sapi: 'micro', osFamily: 'Linux', machine: 'x86_64', binaryPath: '/usr/local/bin/clonio');

    expect($resolver->resolve())->toBe(['/usr/local/bin/clonio', 'clonio-linux-x86_64']);
});

it('resolves the macOS ARM binary and normalises arm64 to aarch64', function (): void {
    $resolver = new BinaryResolver(sapi: 'micro', osFamily: 'Darwin', machine: 'arm64', binaryPath: '/bin/clonio');

    expect($resolver->resolve())->toBe(['/bin/clonio', 'clonio-macos-aarch64']);
});

it('resolves a Linux aarch64 binary without re-normalising', function (): void {
    $resolver = new BinaryResolver(sapi: 'micro', osFamily: 'Linux', machine: 'aarch64', binaryPath: '/bin/clonio');

    expect($resolver->resolve()[1])->toBe('clonio-linux-aarch64');
});

it('throws on an unsupported OS under the micro SAPI', function (): void {
    $resolver = new BinaryResolver(sapi: 'micro', osFamily: 'Windows', machine: 'x86_64', binaryPath: 'C:\\clonio.exe');

    expect(fn () => $resolver->resolve())
        ->toThrow(RuntimeException::class, 'Unsupported OS: Windows');
});

it('falls back to the live machine architecture when none is injected', function (): void {
    $resolver = new BinaryResolver(sapi: 'micro', osFamily: 'Linux', binaryPath: '/bin/clonio');

    $expectedArch = php_uname('m') === 'arm64' ? 'aarch64' : php_uname('m');

    expect($resolver->resolve()[1])->toBe('clonio-linux-'.$expectedArch);
});
