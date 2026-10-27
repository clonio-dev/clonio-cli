<?php

declare(strict_types=1);

use App\Services\Audit\StderrDeliveryAdapter;
use Tests\Unit\Services\Audit\Support\G7StderrCaptureFilter;

it('writes every artefact content to standard error in order', function (): void {
    if (! in_array('g7.stderr_capture', stream_get_filters(), true)) {
        stream_filter_register('g7.stderr_capture', G7StderrCaptureFilter::class);
    }

    G7StderrCaptureFilter::$captured = '';
    $filter = stream_filter_append(STDERR, 'g7.stderr_capture', STREAM_FILTER_WRITE);

    try {
        (new StderrDeliveryAdapter)->deliver(
            artefacts: ['a.sig' => "first\n", 'b.sig' => "second\n"],
            channelConfig: [],
            templateVars: [],
        );
    } finally {
        stream_filter_remove($filter);
    }

    expect(G7StderrCaptureFilter::$captured)->toBe("first\nsecond\n");
});
