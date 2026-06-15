<?php

declare(strict_types=1);

use App\Services\Audit\S3DeliveryAdapter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

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

it('builds an S3 disk per delivery and puts each artefact via flysystem', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->with('backups/2026/audit.html', 'html-content');
    $disk->shouldReceive('put')->once()->with('backups/2026/process.jsonl', 'jsonl');

    Storage::shouldReceive('build')->once()->with(Mockery::on(function (array $cfg): bool {
        return $cfg['driver'] === 's3'
            && $cfg['key'] === 'AKIA'
            && $cfg['secret'] === 'shhh'
            && $cfg['region'] === 'eu-central-1'
            && $cfg['bucket'] === 'audits'
            && $cfg['endpoint'] === 'https://minio.local:9000'
            && $cfg['use_path_style_endpoint'] === true
            && $cfg['throw'] === true;
    }))->andReturn($disk);

    $adapter = new S3DeliveryAdapter;
    $adapter->deliver(
        ['audit.html' => 'html-content', 'process.jsonl' => 'jsonl'],
        [
            'endpoint' => 'https://minio.local:9000',
            'bucket' => 'audits',
            'region' => 'eu-central-1',
            'access_key' => 'AKIA',
            'secret_key' => 'shhh',
            'path_prefix' => 'backups/{year}/',
        ],
        ['year' => '2026'],
    );
});

it('omits endpoint and disables path-style addressing when targeting AWS S3 directly', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->with('audit.html', 'content');

    Storage::shouldReceive('build')->once()->with(Mockery::on(function (array $cfg): bool {
        return $cfg['endpoint'] === null
            && $cfg['use_path_style_endpoint'] === false
            && $cfg['region'] === 'us-east-1';
    }))->andReturn($disk);

    $adapter = new S3DeliveryAdapter;
    $adapter->deliver(
        ['audit.html' => 'content'],
        ['bucket' => 'audits', 'access_key' => 'AKIA', 'secret_key' => 'shhh'],
        [],
    );
});

it('decrypts an encrypted secret_key before building the S3 disk', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);

    $encrypted = 'encrypted:'.Crypt::encryptString('real-secret');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->with('audit.html', 'content');

    Storage::shouldReceive('build')->once()->with(Mockery::on(function (array $cfg): bool {
        return $cfg['secret'] === 'real-secret';
    }))->andReturn($disk);

    (new S3DeliveryAdapter)->deliver(
        ['audit.html' => 'content'],
        [
            'bucket' => 'audits',
            'region' => 'eu-central-1',
            'access_key' => 'AKIA',
            'secret_key' => $encrypted,
        ],
        [],
    );
});
