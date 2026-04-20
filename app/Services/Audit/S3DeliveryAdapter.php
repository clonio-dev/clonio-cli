<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

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

        foreach ($artefacts as $filename => $content) {
            $key = $this->resolveKey($pathPrefix, $filename, $templateVars);
            $this->putObject($endpoint, $bucket, $key, $content, $region, $accessKey, $secretKey);
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

    private function putObject(string $endpoint, string $bucket, string $key, string $content, string $region, string $accessKey, string $secretKey): void
    {
        $parsedHost = parse_url($endpoint, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : '';
        $url = rtrim($endpoint, '/').'/'.$bucket.'/'.ltrim($key, '/');
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');
        $contentHash = hash('sha256', $content);

        $canonicalUri = '/'.$bucket.'/'.ltrim($key, '/');
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $contentHash,
            'x-amz-date' => $date,
        ];

        ksort($headers);
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = '';

        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k.':'.$v."\n";
        }

        $canonicalRequest = implode("\n", ['PUT', $canonicalUri, '', $canonicalHeaders, $signedHeaders, $contentHash]);
        $scope = $dateShort.'/'.$region.'/s3/aws4_request';
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $date, $scope, hash('sha256', $canonicalRequest)]);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $dateShort, 'AWS4'.$secretKey, true),
                    true),
                true),
            true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $accessKey, $scope, $signedHeaders, $signature
        );

        $ch = curl_init($url);

        throw_if($ch === false, RuntimeException::class, 'Failed to initialize cURL for S3 upload');

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: '.$authorization,
                'x-amz-content-sha256: '.$contentHash,
                'x-amz-date: '.$date,
                'Content-Length: '.strlen($content),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('S3 PUT failed (HTTP %d): %s', $httpCode, is_string($response) ? $response : $error));
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
