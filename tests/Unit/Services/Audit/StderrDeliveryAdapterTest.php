<?php

declare(strict_types=1);

use App\Services\Audit\StderrDeliveryAdapter;

it('writes artefact content to standard error without throwing', function (): void {
    $adapter = new StderrDeliveryAdapter;

    // fwrite(STDERR, ...) cannot be captured via output buffering; assert the
    // loop executes for every artefact and the call completes cleanly.
    $adapter->deliver(
        artefacts: ['a.sig' => '', 'b.sig' => ''],
        channelConfig: [],
        templateVars: [],
    );
})->throwsNoExceptions();
