<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditChannelType;
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
     * Deliver audit artefacts and process log per channel configuration.
     *
     * Each channel can be configured with optional booleans:
     *   delivers_audit       — whether to send the audit HTML/sig files (default: true for all channels)
     *   delivers_process_log — whether to send the JSONL process log
     *                          (default: true for local/s3, false for stdout/stderr and notification channels)
     *
     * @param  array<string, mixed>|null  $auditConfig  the 'audit' key from clonio.json (or null if absent)
     * @param  array<string, string>  $auditArtefacts  filename => content pairs (html, sig)
     * @param  string  $processLogContent  the JSONL process log content
     * @param  list<string>  $channelOverride  if non-empty, use only these channels
     * @param  array<string, string>  $templateVars
     */
    public function deliver(
        ?array $auditConfig,
        array $auditArtefacts,
        string $processLogContent,
        array $channelOverride,
        array $templateVars,
    ): void {
        if ($auditConfig === null) {
            return;
        }

        /** @var array<string, mixed> $channels */
        $channels = is_array($auditConfig['channels'] ?? null) ? $auditConfig['channels'] : [];

        if ($channelOverride !== []) {
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
            $typeValue = $channelConfig['type'] ?? null;

            if (! is_string($typeValue)) {
                continue;
            }

            $channelType = AuditChannelType::tryFrom($typeValue);

            if ($channelType === null) {
                $this->runLog->log('warning', 'audit_channel_unsupported', [
                    'channel' => $channelName,
                    'type' => $typeValue,
                ]);

                continue;
            }

            // Resolve what this channel should deliver.
            // Per-channel config keys override the channel-type defaults.
            $deliversAudit = isset($channelConfig['delivers_audit'])
                ? (bool) $channelConfig['delivers_audit']
                : true;

            $deliversProcessLog = isset($channelConfig['delivers_process_log'])
                ? (bool) $channelConfig['delivers_process_log']
                : $channelType->defaultDeliversProcessLog();

            $processLogFilename = ($templateVars['source'] ?? 'source')
                .'_'.($templateVars['target'] ?? 'target')
                .'_'.($templateVars['timestamp'] ?? date('Y-m-d'))
                .'_process.jsonl';

            match ($channelType) {
                AuditChannelType::Local => $this->deliverLocal(
                    $channelConfig, $auditArtefacts, $processLogContent,
                    $processLogFilename, $templateVars, $deliversAudit, $deliversProcessLog,
                ),
                AuditChannelType::Stdout => $this->deliverOutput(
                    $this->stdoutAdapter, $auditArtefacts, $processLogContent,
                    $processLogFilename, $deliversAudit, $deliversProcessLog,
                ),
                AuditChannelType::Stderr => $this->deliverOutput(
                    $this->stderrAdapter, $auditArtefacts, $processLogContent,
                    $processLogFilename, $deliversAudit, $deliversProcessLog,
                ),
                default => $this->runLog->log('warning', 'audit_channel_unsupported', [
                    'channel' => $channelName,
                    'type' => $typeValue,
                ]),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     * @param  array<string, string>  $auditArtefacts
     * @param  array<string, string>  $templateVars
     */
    private function deliverLocal(
        array $channelConfig,
        array $auditArtefacts,
        string $processLogContent,
        string $processLogFilename,
        array $templateVars,
        bool $deliversAudit,
        bool $deliversProcessLog,
    ): void {
        $path = is_string($channelConfig['path'] ?? null) ? $channelConfig['path'] : 'clonio-logs';

        if ($deliversAudit) {
            $this->deliverWithRetry(fn () => $this->localAdapter->deliver($auditArtefacts, $path, $templateVars));
        }

        if ($deliversProcessLog) {
            $this->deliverWithRetry(fn () => $this->localAdapter->deliver([$processLogFilename => $processLogContent], $path, $templateVars));
        }
    }

    /**
     * @param  array<string, string>  $auditArtefacts
     */
    private function deliverOutput(
        StdoutDeliveryAdapter|StderrDeliveryAdapter $adapter,
        array $auditArtefacts,
        string $processLogContent,
        string $processLogFilename,
        bool $deliversAudit,
        bool $deliversProcessLog,
    ): void {
        if ($deliversAudit) {
            $this->deliverWithRetry(fn () => $adapter->deliver($auditArtefacts));
        }

        if ($deliversProcessLog) {
            $this->deliverWithRetry(fn () => $adapter->deliver([$processLogFilename => $processLogContent]));
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
