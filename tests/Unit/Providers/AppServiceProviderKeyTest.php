<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

beforeEach(function (): void {
    $this->previousCwd = getcwd();
    $this->emptyCwd = sys_get_temp_dir().'/clonio-key-'.bin2hex(random_bytes(4));
    mkdir($this->emptyCwd);
    chdir($this->emptyCwd);
});

afterEach(function (): void {
    chdir($this->previousCwd);
    rmdir($this->emptyCwd);
    putenv('CLONIO_KEY');
    unset($_ENV['CLONIO_KEY'], $_SERVER['CLONIO_KEY']);
    putenv('APP_KEY=base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=');
    $_ENV['APP_KEY'] = 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=';
});

it('uses CLONIO_KEY as the application key when it is set', function (): void {
    putenv('CLONIO_KEY=base64:clonio');

    (new AppServiceProvider($this->app))->register();

    expect(config('app.key'))->toBe('base64:clonio');
});

it('keeps the configured key when no key is set', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
    config(['app.key' => 'configured']);

    (new AppServiceProvider($this->app))->register();

    expect(config('app.key'))->toBe('configured');
});
