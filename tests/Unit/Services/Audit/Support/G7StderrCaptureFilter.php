<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit\Support;

use php_user_filter;

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
