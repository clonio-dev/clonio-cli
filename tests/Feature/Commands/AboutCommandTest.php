<?php

use Illuminate\Support\Facades\Artisan;

it('displays about info for clonio', function (): void {
    $this->artisan('about')->assertExitCode(0);
});

it('prints the logo with claim followed by the exact about text', function (): void {
    $lines = explode("\n", (string) file_get_contents(resource_path('ascii-art/clonio-logo-with-shadow.txt')));

    $expected = implode('', array_map(fn (string $line): string => '  '.$line."\n", $lines))
        ."  👻  Clone your production database to dev and test environments –\n"
        ."     with automatic anonymization, schema versioning, and full audit trails.\n"
        ."\n"
        ."  It is open source software. It is free for individuals and NGOs.\n"
        ."\n"
        ."  See https://clonio.dev for more information.\n"
        ."\n";

    $exit = Artisan::call('about');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toBe($expected);
});
