<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class NtfyDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $url = $this->buildUrl($channelConfig);
        $priority = is_string($channelConfig['priority'] ?? null) ? $channelConfig['priority'] : 'default';
        $tags = is_array($channelConfig['tags'] ?? null) ? $channelConfig['tags'] : [];
        $token = isset($channelConfig['token']) ? $this->decryptIfNeeded(is_string($channelConfig['token']) ? $channelConfig['token'] : '') : null;

        $source = $templateVars['source'] ?? 'unknown';
        $target = $templateVars['target'] ?? 'unknown';
        $timestamp = $templateVars['timestamp'] ?? date('Y-m-d');
        $fileCount = count($artefacts);

        $title = sprintf('Clonio: %s → %s', $source, $target);
        $message = sprintf('Audit completed at %s. %d artefact(s) generated.', $timestamp, $fileCount);

        $headers = [
            'Title: '.$title,
            'Priority: '.$priority,
        ];

        if ($tags !== []) {
            $headers[] = 'Tags: '.implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tags));
        }

        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        $ch = curl_init($url);

        throw_if($ch === false, RuntimeException::class, 'Failed to initialize cURL for ntfy POST');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $message,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('ntfy POST failed (HTTP %d): %s', $httpCode, is_string($response) ? $response : $error));
        }
    }

    /** @param array<string, mixed> $channelConfig */
    private function buildUrl(array $channelConfig): string
    {
        $base = rtrim(is_string($channelConfig['url'] ?? null) ? $channelConfig['url'] : 'https://ntfy.sh', '/');
        $topic = is_string($channelConfig['topic'] ?? null) ? $channelConfig['topic'] : '';

        return $base.'/'.$topic;
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
