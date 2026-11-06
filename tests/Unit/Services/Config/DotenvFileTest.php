<?php

declare(strict_types=1);

use App\Services\Config\DotenvFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

describe('get', function (): void {
    it('returns null when there is no .env file', function (): void {
        expect((new DotenvFile)->get('CLONIO_KEY'))->toBeNull();
    });

    it('returns the value of the variable', function (): void {
        Storage::put('.env', "DB_HOST=localhost\nCLONIO_KEY=base64:abc\n");

        expect((new DotenvFile)->get('CLONIO_KEY'))->toBe('base64:abc')
            ->and((new DotenvFile)->get('DB_HOST'))->toBe('localhost');
    });

    it('parses quoted values like the loader does', function (): void {
        Storage::put('.env', "CLONIO_KEY=\"base64:abc\"\n");

        expect((new DotenvFile)->get('CLONIO_KEY'))->toBe('base64:abc');
    });

    it('returns null for a missing, empty or similarly named variable', function (): void {
        Storage::put('.env', "CLONIO_KEY=\nCLONIO_KEY_OLD=base64:old\n");

        expect((new DotenvFile)->get('CLONIO_KEY'))->toBeNull()
            ->and((new DotenvFile)->get('APP_KEY'))->toBeNull();
    });

    it('returns null when the file cannot be parsed', function (): void {
        Storage::put('.env', "CLONIO_KEY=base64:abc\nOTHER=a b\n");

        expect((new DotenvFile)->get('CLONIO_KEY'))->toBeNull();
    });

    it('returns null when the file cannot be read', function (): void {
        Storage::shouldReceive('exists')->with('.env')->andReturn(true);
        Storage::shouldReceive('get')->with('.env')->andReturn(null);

        expect((new DotenvFile)->get('CLONIO_KEY'))->toBeNull();
    });
});

describe('read', function (): void {
    it('returns null when there is no .env file', function (): void {
        expect((new DotenvFile)->read())->toBeNull();
    });

    it('returns the content of the file', function (): void {
        Storage::put('.env', "A=1\n");

        expect((new DotenvFile)->read())->toBe("A=1\n");
    });

    it('throws when the file cannot be read', function (): void {
        Storage::shouldReceive('exists')->with('.env')->andReturn(true);
        Storage::shouldReceive('get')->with('.env')->andReturn(null);

        expect(fn () => (new DotenvFile)->read())
            ->toThrow(RuntimeException::class, sprintf('Cannot read %s/.env: permission denied', getcwd()));
    });
});

describe('set', function (): void {
    it('creates the file readable only by its owner', function (): void {
        (new DotenvFile)->set('CLONIO_KEY', 'base64:new');

        expect(Storage::get('.env'))->toBe("CLONIO_KEY=base64:new\n")
            ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600);
    });

    it('replaces the line of the variable in place', function (): void {
        Storage::put('.env', "A=1\nCLONIO_KEY = base64:old\nCLONIO_KEY_OLD=x\nB=2\n");
        chmod(Storage::path('.env'), 0644);

        (new DotenvFile)->set('CLONIO_KEY', 'base64:new');

        expect(Storage::get('.env'))->toBe("A=1\nCLONIO_KEY=base64:new\nCLONIO_KEY_OLD=x\nB=2\n")
            ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600);
    });

    it('replaces an empty line of the variable', function (): void {
        Storage::put('.env', "CLONIO_KEY=\nB=2\n");

        (new DotenvFile)->set('CLONIO_KEY', 'base64:new');

        expect(Storage::get('.env'))->toBe("CLONIO_KEY=base64:new\nB=2\n");
    });

    it('appends the variable after trimming trailing blank lines', function (): void {
        Storage::put('.env', "A=1\nCLONIO_KEY_OLD=x\n\n\n");

        (new DotenvFile)->set('CLONIO_KEY', 'base64:new');

        expect(Storage::get('.env'))->toBe("A=1\nCLONIO_KEY_OLD=x\nCLONIO_KEY=base64:new\n");
    });

    it('writes the value literally', function (): void {
        Storage::put('.env', "CLONIO_KEY=old\n");

        (new DotenvFile)->set('CLONIO_KEY', 'a$1\\0b');

        expect(Storage::get('.env'))->toBe("CLONIO_KEY=a$1\\0b\n");
    });

    it('throws when the file cannot be written', function (): void {
        Storage::shouldReceive('exists')->with('.env')->andReturn(false);
        Storage::shouldReceive('put')->once()->with('.env', "CLONIO_KEY=x\n")->andReturn(false);

        expect(fn () => (new DotenvFile)->set('CLONIO_KEY', 'x'))
            ->toThrow(RuntimeException::class, sprintf('Cannot write %s/.env: permission denied', getcwd()));
    });
});

describe('restore', function (): void {
    it('writes the previous content back, readable only by its owner', function (): void {
        Storage::put('.env', "CLONIO_KEY=new\n");
        chmod(Storage::path('.env'), 0644);

        (new DotenvFile)->restore("APP_KEY=old\n");

        expect(Storage::get('.env'))->toBe("APP_KEY=old\n")
            ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600);
    });

    it('deletes the file when there was none before', function (): void {
        Storage::put('.env', "CLONIO_KEY=new\n");

        (new DotenvFile)->restore(null);

        Storage::assertMissing('.env');
    });
});
