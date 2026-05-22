<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class S3DeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $endpoint = is_string($channelConfig['endpoint'] ?? null) ? $channelConfig['endpoint'] : '';
        $bucket = is_string($channelConfig['bucket'] ?? null) ? $channelConfig['bucket'] : '';
        $region = is_string($channelConfig['region'] ?? null) ? $channelConfig['region'] : 'us-east-1';
        $accessKey = is_string($channelConfig['access_key'] ?? null) ? $channelConfig['access_key'] : '';
        $secretKey = $this->decryptIfNeeded(is_string($channelConfig['secret_key'] ?? null) ? $channelConfig['secret_key'] : '');
        $pathPrefix = is_string($channelConfig['path_prefix'] ?? null) ? $channelConfig['path_prefix'] : '';

        $disk = Storage::build([
            'driver' => 's3',
            'key' => $accessKey,
            'secret' => $secretKey,
            'region' => $region,
            'bucket' => $bucket,
            'endpoint' => $endpoint !== '' ? $endpoint : null,
            'use_path_style_endpoint' => $endpoint !== '',
            'throw' => true,
        ]);

        foreach ($artefacts as $filename => $content) {
            $key = $this->resolveKey($pathPrefix, $filename, $templateVars);
            $disk->put($key, $content);
        }
    }

    /** @param array<string, string> $templateVars */
    private function resolveKey(string $pathPrefix, string $filename, array $templateVars): string
    {
        $resolved = $pathPrefix;

        foreach ($templateVars as $var => $value) {
            $resolved = str_replace('{'.$var.'}', $value, $resolved);
        }

        $resolved = rtrim($resolved, '/');

        return $resolved !== '' ? $resolved.'/'.$filename : $filename;
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
