<?php

declare(strict_types=1);

use App\Logging\AuditBuffer;
use App\Logging\AuditBufferHandler;
use App\Logging\CreateAuditBufferLogger;

it('creates a logger named after the channel config that writes into the buffer', function (): void {
    $buffer = new AuditBuffer;
    $logger = (new CreateAuditBufferLogger($buffer))(['name' => 'custom_audit']);

    $logger->info('hello', ['k' => 'v']);

    expect($logger->getName())->toBe('custom_audit')
        ->and($logger->getHandlers())->toHaveCount(1)
        ->and($logger->getHandlers()[0])->toBeInstanceOf(AuditBufferHandler::class)
        ->and($buffer->records()[0]['event'])->toBe('hello')
        ->and($buffer->records()[0]['k'])->toBe('v');
});

it('falls back to the audit_buffer name when none (or a non-string) is configured', function (array $config): void {
    $logger = (new CreateAuditBufferLogger(new AuditBuffer))($config);

    expect($logger->getName())->toBe('audit_buffer');
})->with([
    'missing' => [[]],
    'non-string' => [['name' => 42]],
]);
