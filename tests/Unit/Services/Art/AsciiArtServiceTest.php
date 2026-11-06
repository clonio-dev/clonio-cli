<?php

declare(strict_types=1);

use App\Services\Art\AsciiArtService;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
})->with(['clonioLogoWithClaim']);
