<?php

declare(strict_types=1);

use App\Services\Config\DotenvFile;
use App\Services\Secrets\EncryptionKey;
use Illuminate\Support\Facades\Storage;

function g10_setEnv(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);

        return;
    }

    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

beforeEach(function (): void {
    Storage::fake('local');
    g10_setEnv('CLONIO_KEY', null);
    g10_setEnv('APP_KEY', null);
});

afterEach(function (): void {
    g10_setEnv('CLONIO_KEY', null);
    g10_setEnv('APP_KEY', 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=');
});

describe('value', function (): void {
    it('prefers CLONIO_KEY over APP_KEY', function (): void {
        g10_setEnv('CLONIO_KEY', 'base64:clonio');
        g10_setEnv('APP_KEY', 'base64:app');

        expect(EncryptionKey::value())->toBe('base64:clonio');
    });

    it('falls back to APP_KEY when CLONIO_KEY is missing or empty', function (): void {
        g10_setEnv('APP_KEY', 'base64:app');

        expect(EncryptionKey::value())->toBe('base64:app');

        g10_setEnv('CLONIO_KEY', '');

        expect(EncryptionKey::value())->toBe('base64:app');
    });

    it('returns null when no key is set', function (): void {
        g10_setEnv('APP_KEY', '');

        expect(EncryptionKey::value())->toBeNull();
    });
});

describe('locate', function (): void {
    it('reports a key from the process environment', function (): void {
        g10_setEnv('CLONIO_KEY', 'base64:clonio');
        g10_setEnv('APP_KEY', 'base64:app');

        expect(EncryptionKey::locate(new DotenvFile))->toBe(['CLONIO_KEY', false]);
    });

    it('reports a key that the environment holds because .env set it', function (): void {
        Storage::put('.env', "CLONIO_KEY=base64:clonio\n");
        g10_setEnv('CLONIO_KEY', 'base64:clonio');

        expect(EncryptionKey::locate(new DotenvFile))->toBe(['CLONIO_KEY', true]);
    });

    it('reports the environment when it overrides a different value in .env', function (): void {
        Storage::put('.env', "APP_KEY=base64:file\n");
        g10_setEnv('APP_KEY', 'base64:env');

        expect(EncryptionKey::locate(new DotenvFile))->toBe(['APP_KEY', false]);
    });

    it('reports a key that is only in .env', function (): void {
        Storage::put('.env', "APP_KEY=base64:app\nCLONIO_KEY=base64:clonio\n");

        expect(EncryptionKey::locate(new DotenvFile))->toBe(['CLONIO_KEY', true]);
    });

    it('falls back to APP_KEY and ignores empty values', function (): void {
        Storage::put('.env', "CLONIO_KEY=\n");
        g10_setEnv('CLONIO_KEY', '');
        g10_setEnv('APP_KEY', 'base64:app');

        expect(EncryptionKey::locate(new DotenvFile))->toBe(['APP_KEY', false]);
    });

    it('returns null when no key is set', function (): void {
        Storage::put('.env', "DB_HOST=localhost\n");

        expect(EncryptionKey::locate(new DotenvFile))->toBeNull();
    });
});
