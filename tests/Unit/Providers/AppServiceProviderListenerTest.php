<?php

declare(strict_types=1);

use App\Listeners\WarnAboutInsecureSecrets;
use App\Providers\AppServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;

it('registers the insecure-secrets warning exactly once', function (): void {
    (new AppServiceProvider(app()))->boot();

    $listeners = (new ReflectionProperty(app('events'), 'listeners'))->getValue(app('events'));

    expect($listeners[CommandStarting::class] ?? [])->toBe([WarnAboutInsecureSecrets::class]);
});

it('turns off event discovery, which would register the listener a second time', function (): void {
    expect((new ReflectionProperty(EventServiceProvider::class, 'shouldDiscoverEvents'))->getValue())->toBeFalse();
});
