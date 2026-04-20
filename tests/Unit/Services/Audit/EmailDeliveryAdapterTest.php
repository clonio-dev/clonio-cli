<?php

declare(strict_types=1);

use App\Services\Audit\EmailDeliveryAdapter;

it('builds email subject with source and target from template vars', function (): void {
    $adapter = new EmailDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildSubject');
    $subject = $reflection->invoke($adapter, [
        'source' => 'production',
        'target' => 'staging',
        'timestamp' => '2026-04-20T10-00-00Z',
    ]);
    expect($subject)->toBe('Clonio Audit Log — production → staging (2026-04-20T10-00-00Z)');
});

it('builds email subject with defaults when template vars missing', function (): void {
    $adapter = new EmailDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildSubject');
    $subject = $reflection->invoke($adapter, []);
    expect($subject)->toContain('unknown');
});
