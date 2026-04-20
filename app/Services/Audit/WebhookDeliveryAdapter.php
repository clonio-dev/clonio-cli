<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use App\Enums\AuditChannelType;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class WebhookDeliveryAdapter implements DeliveryAdapterInterface
{
    public function __construct(
        private readonly AuditChannelType $channelType = AuditChannelType::Slack,
    ) {}

    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $webhookUrl = $this->decryptIfNeeded((string) ($channelConfig['webhook_url'] ?? ''));

        if ($webhookUrl === '') {
            throw new RuntimeException('Webhook URL is empty');
        }

        $source = $templateVars['source'] ?? 'unknown';
        $target = $templateVars['target'] ?? 'unknown';
        $timestamp = $templateVars['timestamp'] ?? date('Y-m-d');

        $payload = $this->channelType === AuditChannelType::MsTeams
            ? $this->buildTeamsPayload($source, $target, $timestamp)
            : $this->buildSlackPayload($source, $target, $timestamp);

        $this->post($webhookUrl, $payload);
    }

    /** @return array<string, mixed> */
    private function buildTeamsPayload(string $source, string $target, string $timestamp): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => sprintf('Clonio: %s → %s', $source, $target), 'weight' => 'bolder', 'size' => 'medium'],
                        ['type' => 'TextBlock', 'text' => sprintf('Audit completed at %s', $timestamp), 'wrap' => true],
                    ],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function buildSlackPayload(string $source, string $target, string $timestamp): array
    {
        return [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => sprintf(':white_check_mark: *Clonio Audit* — %s → %s\nCompleted at %s', $source, $target, $timestamp),
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function post(string $url, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Failed to encode webhook payload as JSON');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for webhook POST');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('Webhook POST failed (HTTP %d): %s', $httpCode, is_string($response) ? $response : $error));
        }
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
