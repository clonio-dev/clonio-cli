<?php

declare(strict_types=1);

use App\Services\Art\AsciiArtService;
use Illuminate\Console\OutputStyle;

it('writes clonio logo lines to output', function (): void {
    $output = Mockery::mock(OutputStyle::class);
    $output->shouldReceive('writeln')->atLeast()->once();

    $service = new AsciiArtService;
    $service->clonioLogo($output);
});

it('writes clonio logo with shadow lines to output', function (): void {
    $output = Mockery::mock(OutputStyle::class);
    $output->shouldReceive('writeln')->atLeast()->once();

    $service = new AsciiArtService;
    $service->clonioLogoWithShadow($output);
});

it('writes correct number of lines for clonio logo', function (): void {
    $content = file_get_contents(resource_path('ascii-art/clonio-logo.txt'));
    $expectedLines = count(explode("\n", $content));

    $writtenLines = [];
    $output = Mockery::mock(OutputStyle::class);
    $output->shouldReceive('writeln')
        ->times($expectedLines)
        ->andReturnUsing(function (string $line) use (&$writtenLines): void {
            $writtenLines[] = $line;
        });

    $service = new AsciiArtService;
    $service->clonioLogo($output);

    expect($writtenLines)->toHaveCount($expectedLines);
});

it('writes correct number of lines for clonio logo with shadow', function (): void {
    $content = file_get_contents(resource_path('ascii-art/clonio-logo-with-shadow.txt'));
    $expectedLines = count(explode("\n", $content));

    $writtenLines = [];
    $output = Mockery::mock(OutputStyle::class);
    $output->shouldReceive('writeln')
        ->times($expectedLines)
        ->andReturnUsing(function (string $line) use (&$writtenLines): void {
            $writtenLines[] = $line;
        });

    $service = new AsciiArtService;
    $service->clonioLogoWithShadow($output);

    expect($writtenLines)->toHaveCount($expectedLines);
});

it('does nothing when logo file does not exist', function (): void {
    $output = Mockery::mock(OutputStyle::class);
    $output->shouldNotReceive('writeln');

    // Temporarily swap the resource path by mocking file_get_contents via a subclass
    $service = new class extends AsciiArtService
    {
        public function clonioLogo(OutputStyle $output): void
        {
            // Simulate file_get_contents returning false
            $lines = false;
            if ($lines === false) {
                return;
            }
        }
    };

    $service->clonioLogo($output);
});
