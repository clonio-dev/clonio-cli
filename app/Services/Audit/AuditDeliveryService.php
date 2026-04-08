<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Cloning\RunLogWriter;
use Illuminate\Support\Sleep;
use Throwable;

class AuditDeliveryService
{
    public function __construct(
        private readonly LocalDeliveryAdapter $localAdapter,
        private readonly StdoutDeliveryAdapter $stdoutAdapter,
        private readonly StderrDeliveryAdapter $stderrAdapter,
        private readonly RunLogWriter $runLog,
    ) {}

    /**
     * Deliver audit and run log artefacts per channel configuration.
     *
     * @param  array<string, mixed>|null  $auditConfig  the 'audit' key from clonio.json (or null if absent)
     * @param  array<string, string>  $auditArtefacts  filename => content pairs (html, sig)
     * @param  string  $runLogContent  the JSONL run log content
     * @param  list<string>  $channelOverride  if non-empty, use only these channels
     * @param  array<string, string>  $templateVars
     */
    public function deliver(
        ?array $auditConfig,
        array $auditArtefacts,
        string $runLogContent,
        array $channelOverride,
        array $templateVars,
    ): void {
        if ($auditConfig === null) {
            return;
        }

        /** @var array<string, mixed> $channels */
        $channels = is_array($auditConfig['channels'] ?? null) ? $auditConfig['channels'] : [];

        if ($channelOverride !== []) {
            // Filter channels to only the overridden ones
            $filteredChannels = [];

            foreach ($channelOverride as $name) {
                if (isset($channels[$name])) {
                    $filteredChannels[$name] = $channels[$name];
                }
            }

            $channels = $filteredChannels;
        }

        foreach ($channels as $channelName => $channelConfig) {
            if (! is_array($channelConfig)) {
                continue;
            }

            /** @var array<string, mixed> $channelConfig */
            $type = $channelConfig['type'] ?? null;

            if ($type === 'local') {
                $path = is_string($channelConfig['path'] ?? null) ? $channelConfig['path'] : 'clonio-logs';

                // Deliver audit artefacts
                $this->deliverWithRetry(fn () => $this->localAdapter->deliver($auditArtefacts, $path, $templateVars));

                // Deliver run log
                $runLogFilename = ($templateVars['source'] ?? 'source').'_'.($templateVars['target'] ?? 'target').'_'.($templateVars['timestamp'] ?? date('Y-m-d')).'_run.jsonl';

                $this->deliverWithRetry(fn () => $this->localAdapter->deliver([$runLogFilename => $runLogContent], $path, $templateVars));
            } elseif ($type === 'stdout') {
                $runLogFilename = ($templateVars['source'] ?? 'source').'_'.($templateVars['target'] ?? 'target').'_'.($templateVars['timestamp'] ?? date('Y-m-d')).'_run.jsonl';

                $this->deliverWithRetry(fn () => $this->stdoutAdapter->deliver($auditArtefacts));
                $this->deliverWithRetry(fn () => $this->stdoutAdapter->deliver([$runLogFilename => $runLogContent]));
            } elseif ($type === 'stderr') {
                $runLogFilename = ($templateVars['source'] ?? 'source').'_'.($templateVars['target'] ?? 'target').'_'.($templateVars['timestamp'] ?? date('Y-m-d')).'_run.jsonl';

                $this->deliverWithRetry(fn () => $this->stderrAdapter->deliver($auditArtefacts));
                $this->deliverWithRetry(fn () => $this->stderrAdapter->deliver([$runLogFilename => $runLogContent]));
            } else {
                $this->runLog->log('warning', 'audit_channel_unsupported', [
                    'channel' => $channelName,
                    'type' => $type,
                ]);

                continue;
            }
        }
    }

    private function deliverWithRetry(callable $fn, int $maxAttempts = 3, int $initialDelayMs = 500, int $backoff = 2, int $maxDelayMs = 10000): void
    {
        $attempt = 0;
        $delay = $initialDelayMs;

        while ($attempt < $maxAttempts) {
            try {
                $fn();

                return;
            } catch (Throwable $e) {
                $attempt++;

                if ($attempt >= $maxAttempts) {
                    $this->runLog->log('error', 'audit_delivery_failed', ['error' => $e->getMessage()]);

                    return;
                }

                Sleep::usleep($delay * 1000);
                $delay = min($delay * $backoff, $maxDelayMs);
            }
        }
    }
}
