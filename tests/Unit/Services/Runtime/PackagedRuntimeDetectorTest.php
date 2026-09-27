<?php

declare(strict_types=1);

use App\Services\Runtime\PackagedRuntimeDetector;

it('reports packaged when running under the micro SAPI', function (): void {
    $detector = new PackagedRuntimeDetector(sapi: 'micro', pharPath: '');

    expect($detector->isPackaged())->toBeTrue();
});

it('reports packaged when a PHAR is running', function (): void {
    $detector = new PackagedRuntimeDetector(sapi: 'cli', pharPath: '/opt/clonio/clonio.phar');

    expect($detector->isPackaged())->toBeTrue();
});

it('reports not packaged for a plain CLI invocation with no PHAR running', function (): void {
    $detector = new PackagedRuntimeDetector(sapi: 'cli', pharPath: '');

    expect($detector->isPackaged())->toBeFalse();
});

it('falls back to the live Phar::running() state when none is injected', function (): void {
    $detector = new PackagedRuntimeDetector(sapi: 'cli');

    // Under Pest/PHPUnit there is no PHAR running, so this resolves to false.
    expect($detector->isPackaged())->toBeFalse();
});
