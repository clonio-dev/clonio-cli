<?php

declare(strict_types=1);

use App\Data\SslConfig;
use App\Enums\SslMode;
use App\Services\Database\Tls\CertificateFiles;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->originalHome = getenv('HOME');
    putenv('HOME=/home/tester');
});

afterEach(function (): void {
    putenv('HOME='.($this->originalHome === false ? '' : $this->originalHome));
});

it('resolves relative paths against the working-directory disk, not the install path', function (): void {
    expect((new CertificateFiles)->resolve('certs/ca.pem'))
        ->toBe(Storage::disk('local')->path('certs/ca.pem'));
});

it('resolves ~/ against HOME', function (): void {
    expect((new CertificateFiles)->resolve('~/certs/ca.pem'))->toBe('/home/tester/certs/ca.pem');
});

it('keeps absolute paths unchanged', function (): void {
    expect((new CertificateFiles)->resolve('/etc/ssl/ca.pem'))->toBe('/etc/ssl/ca.pem')
        ->and((new CertificateFiles)->resolve('C:\\certs\\ca.pem'))->toBe('C:\\certs\\ca.pem');
});

it('lists every configured file that does not exist, as resolved path', function (): void {
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $missing = (new CertificateFiles)->unreadable(new SslConfig(SslMode::Verify, 'certs/ca.pem', 'certs/c.pem', 'certs/k.pem'));

    expect($missing)->toBe([
        Storage::disk('local')->path('certs/c.pem'),
        Storage::disk('local')->path('certs/k.pem'),
    ]);
});

it('returns nothing when no files are configured', function (): void {
    expect((new CertificateFiles)->unreadable(new SslConfig(SslMode::Require)))->toBe([]);
});

it('flags group- or world-readable keys', function (): void {
    Storage::disk('local')->put('certs/k.pem', 'x');
    $path = Storage::disk('local')->path('certs/k.pem');

    chmod($path, 0644);
    expect((new CertificateFiles)->isKeyExposed('certs/k.pem'))->toBeTrue();

    chmod($path, 0600);
    expect((new CertificateFiles)->isKeyExposed('certs/k.pem'))->toBeFalse();
})->skipOnWindows();

it('does not flag a key that does not exist', function (): void {
    expect((new CertificateFiles)->isKeyExposed('certs/missing.pem'))->toBeFalse();
});
