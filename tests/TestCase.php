<?php

namespace Tests;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The console events fire in tests too (the app env is not "testing").
        // Keep the insecure-secrets warning out of the command output under
        // test; tests/Unit/Listeners covers it.
        Event::forget(CommandStarting::class);
    }
}
