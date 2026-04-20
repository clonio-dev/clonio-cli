<?php

declare(strict_types=1);

use App\Services\Audit\NtfyDeliveryAdapter;

it('builds correct ntfy URL from config', function (): void {
    $adapter = new NtfyDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildUrl');
    $url = $reflection->invoke($adapter, ['url' => 'https://ntfy.sh', 'topic' => 'clonio-alerts']);
    expect($url)->toBe('https://ntfy.sh/clonio-alerts');
});

it('builds correct ntfy URL with trailing slash in base URL', function (): void {
    $adapter = new NtfyDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildUrl');
    $url = $reflection->invoke($adapter, ['url' => 'https://ntfy.example.com/', 'topic' => 'audit']);
    expect($url)->toBe('https://ntfy.example.com/audit');
});
