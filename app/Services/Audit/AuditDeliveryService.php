<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use App\Enums\AuditChannelType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class AuditDeliveryService
{
    /** @var array<string, DeliveryAdapterInterface> */
    private readonly array $adapters;

    public function __construct(
        LocalDeliveryAdapter $localAdapter,
        StdoutDeliveryAdapter $stdoutAdapter,
        StderrDeliveryAdapter $stderrAdapter,
        S3DeliveryAdapter $s3Adapter,
        EmailDeliveryAdapter $emailAdapter,
        WebhookDeliveryAdapter $teamsAdapter,
        WebhookDeliveryAdapter $slackAdapter,
        NtfyDeliveryAdapter $ntfyAdapter,
    ) {
        $this->adapters = [
            AuditChannelType::Local->value => $localAdapter,
            AuditChannelType::Stdout->value => $stdoutAdapter,
            AuditChannelType::Stderr->value => $stderrAdapter,
            AuditChannelType::S3->value => $s3Adapter,
            AuditChannelType::Email->value => $emailAdapter,
            AuditChannelType::MsTeams->value => $teamsAdapter,
            AuditChannelType::Slack->value => $slackAdapter,
            AuditChannelType::Ntfy->value => $ntfyAdapter,
        ];
    }

    /**
     * Deliver audit artefacts and process log via the configured default channel.
     *
     * @param  array<string, mixed>|null  $auditConfig  the 'audit' key from clonio.json
     * @param  array<string, string>  $auditArtefacts  filename => content pairs
     * @param  string  $processLogContent  JSONL process log
     * @param  list<string>  $channelOverride  if non-empty, deliver to these channels instead of default
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

        /** @var array<string, mixed> $allChannels */
        $allChannels = is_array($auditConfig['channels'] ?? null) ? $auditConfig['channels'] : [];

        // Determine target channel names
        if ($channelOverride !== []) {
            $targetNames = $channelOverride;
        } elseif (is_string($auditConfig['default'] ?? null)) {
            $targetNames = [$auditConfig['default']];
        } else {
            // Legacy fallback: audit_log.deliver_to
            $legacySection = $auditConfig['audit_log'] ?? null;
            $legacyDeliverTo = is_array($legacySection) ? ($legacySection['deliver_to'] ?? null) : null;
            $targetNames = is_array($legacyDeliverTo) ? array_filter($legacyDeliverTo, is_string(...)) : [];
        }

        foreach ($targetNames as $channelName) {
            $this->deliverToChannel((string) $channelName, $allChannels, $auditArtefacts, $processLogContent, $templateVars);
        }
    }

    /**
     * @param  array<string, mixed>  $allChannels
     * @param  array<string, string>  $auditArtefacts
     * @param  array<string, string>  $templateVars
     */
    private function deliverToChannel(
        string $channelName,
        array $allChannels,
        array $auditArtefacts,
        string $processLogContent,
        array $templateVars,
    ): void {
        $channelConfig = $allChannels[$channelName] ?? null;

        if (! is_array($channelConfig)) {
            Log::warning('audit_channel_not_found', ['channel' => $channelName]);

            return;
        }

        $typeValue = $channelConfig['type'] ?? null;

        if (! is_string($typeValue)) {
            return;
        }

        $channelType = AuditChannelType::tryFrom($typeValue);

        if ($channelType === null) {
            Log::warning('audit_channel_unsupported', ['channel' => $channelName, 'type' => $typeValue]);

            return;
        }

        // Stack: fan out to child channels
        if ($channelType === AuditChannelType::Stack) {
            $childNames = is_array($channelConfig['channels'] ?? null) ? $channelConfig['channels'] : [];

            foreach ($childNames as $childName) {
                if (is_string($childName)) {
                    $this->deliverToChannel($childName, $allChannels, $auditArtefacts, $processLogContent, $templateVars);
                }
            }

            return;
        }

        // Resolve delivery flags
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

        if (! array_key_exists($channelType->value, $this->adapters)) {
            Log::warning('audit_channel_unsupported', ['channel' => $channelName, 'type' => $typeValue]);

            return;
        }

        $adapter = $this->adapters[$channelType->value];

        /** @var array<string, mixed> $channelConfig */
        if ($deliversAudit) {
            $this->deliverWithRetry(fn () => $adapter->deliver($auditArtefacts, $channelConfig, $templateVars));
        }

        if ($deliversProcessLog) {
            $this->deliverWithRetry(fn () => $adapter->deliver([$processLogFilename => $processLogContent], $channelConfig, $templateVars));
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
                    Log::error('audit_delivery_failed', ['error' => $e->getMessage()]);

                    return;
                }

                Sleep::usleep($delay * 1000);
                $delay = min($delay * $backoff, $maxDelayMs);
            }
        }
    }
}
