<?php

declare(strict_types=1);

use LaravelZero\Framework\Application;

// Listeners are registered explicitly in AppServiceProvider: event discovery
// would register them a second time, and may not scan a packaged binary.
return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: false)
    ->create();
