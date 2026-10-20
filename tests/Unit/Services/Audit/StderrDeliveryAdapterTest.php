<?php

declare(strict_types=1);

use App\Services\Audit\StderrDeliveryAdapter;

/**
 * Write filter that swallows everything written to STDERR and records it,
 * so the adapter output can be asserted without polluting the test output.
 */
final class G7StderrCaptureFilter extends php_user_filter
{
    public static string $captured = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}

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
