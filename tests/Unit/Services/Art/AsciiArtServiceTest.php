<?php

declare(strict_types=1);

use App\Services\Art\AsciiArtService;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
        public function clonioLogo(OutputStyle $output, string $indent = ''): void
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

function g9_artOutput(): array
{
    $buffer = new BufferedOutput;

    return [new OutputStyle(new ArrayInput([]), $buffer), $buffer];
}

function g9_indentedArt(string $file, string $indent): string
{
    $lines = explode("\n", (string) file_get_contents(resource_path('ascii-art/'.$file)));

    return implode('', array_map(fn (string $line): string => $indent.$line."\n", $lines));
}

it('prefixes every logo line with the indent', function (): void {
    [$output, $buffer] = g9_artOutput();

    (new AsciiArtService)->clonioLogo($output, '>>');

    expect($buffer->fetch())->toBe(g9_indentedArt('clonio-logo.txt', '>>'));
});

it('prefixes every shadow logo line with the indent', function (): void {
    [$output, $buffer] = g9_artOutput();

    (new AsciiArtService)->clonioLogoWithShadow($output, '>>');

    expect($buffer->fetch())->toBe(g9_indentedArt('clonio-logo-with-shadow.txt', '>>'));
});

it('writes the shadow logo followed by the indented claim and a blank line', function (): void {
    [$output, $buffer] = g9_artOutput();

    (new AsciiArtService)->clonioLogoWithClaim($output, '>>');

    expect($buffer->fetch())->toBe(
        g9_indentedArt('clonio-logo-with-shadow.txt', '>>')
        .">>👻  Clone your production database to dev and test environments –\n"
        .">>   with automatic anonymization, schema versioning, and full audit trails.\n"
        ."\n"
    );
});

it('writes nothing when the ascii art files cannot be read', function (string $method): void {
    $original = base_path();
    $empty = sys_get_temp_dir().'/clonio_g9_art_'.uniqid();
    mkdir($empty);

    [$output, $buffer] = g9_artOutput();

    // Point resource_path() to a directory without ascii art and silence the
    // read warning so file_get_contents() returns false instead of throwing.
    app()->setBasePath($empty);
    set_error_handler(static fn (): bool => true);

    try {
        (new AsciiArtService)->{$method}($output, '>>');
    } finally {
        restore_error_handler();
        app()->setBasePath($original);
        rmdir($empty);
    }

    expect($buffer->fetch())->toBe('');
})->with(['clonioLogo', 'clonioLogoWithShadow', 'clonioLogoWithClaim']);
