<?php

declare(strict_types=1);

use App\Services\SqlDump\DumpArchiver;
use Illuminate\Support\Facades\Storage;

it('compresses a .sql file into a ZIP and removes the intermediate file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('shop_20260524_120000.sql', "SELECT 1;\n");

    $result = (new DumpArchiver)->archive('shop_20260524_120000.sql', null);

    expect($result->zipFileName)->toBe('shop_20260524_120000.zip')
        ->and($result->encrypted)->toBeFalse()
        ->and($result->sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($result->zipBytes)->toBeGreaterThan(0)
        ->and(Storage::disk('local')->exists('shop_20260524_120000.zip'))->toBeTrue()
        ->and(Storage::disk('local')->exists('shop_20260524_120000.sql'))->toBeFalse();
});

it('encrypts the archive with AES-256 when a password is given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('shop.sql', "SELECT 1;\n");

    $result = (new DumpArchiver)->archive('shop.sql', 'pw');

    expect($result->encrypted)->toBeTrue();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($result->zipFileName));
    expect($zip->getFromIndex(0))->toBeFalse(); // unreadable without the password
    $zip->close();
});

it('throws when the source .sql file does not exist', function (): void {
    Storage::fake('local');

    expect(fn () => (new DumpArchiver)->archive('missing.sql', null))
        ->toThrow(RuntimeException::class, 'Dump file not found');
});
