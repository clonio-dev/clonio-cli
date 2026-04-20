<?php

declare(strict_types=1);

use App\Services\Audit\S3DeliveryAdapter;

it('builds correct S3 object keys from path prefix and template vars', function (): void {
    $adapter = new S3DeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'resolveKey');
    $key = $reflection->invoke($adapter, 'clonio/{year}/{month}/', 'audit.html', [
        'year' => '2026',
        'month' => '04',
    ]);
    expect($key)->toBe('clonio/2026/04/audit.html');
});

it('builds correct S3 object keys without trailing slash', function (): void {
    $adapter = new S3DeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'resolveKey');
    $key = $reflection->invoke($adapter, 'backups', 'process.jsonl', []);
    expect($key)->toBe('backups/process.jsonl');
});

it('builds correct S3 object key with empty prefix', function (): void {
    $adapter = new S3DeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'resolveKey');
    $key = $reflection->invoke($adapter, '', 'audit.html', []);
    expect($key)->toBe('audit.html');
});
