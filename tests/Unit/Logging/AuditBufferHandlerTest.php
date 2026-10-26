<?php

declare(strict_types=1);

use App\Logging\AuditBuffer;
use App\Logging\AuditBufferHandler;
use Monolog\Level;
use Monolog\LogRecord;

it('forwards records at or above its level into the buffer', function (): void {
    $buffer = new AuditBuffer;
    $handler = new AuditBufferHandler($buffer, Level::Warning, false);

    $info = new LogRecord(new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')), 'audit', Level::Info, 'ignored', []);
    $error = new LogRecord(new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')), 'audit', Level::Error, 'boom', ['table' => 'users']);

    expect($handler->isHandling($info))->toBeFalse()
        ->and($handler->handle($info))->toBeFalse()
        ->and($handler->getBubble())->toBeFalse()
        ->and($handler->getLevel())->toBe(Level::Warning)
        ->and($handler->handle($error))->toBeTrue();

    expect($buffer->records())->toBe([
        ['ts' => '2026-04-01T14:32:00.000Z', 'level' => 'error', 'event' => 'boom', 'table' => 'users'],
    ]);
});

it('handles every level and bubbles by default', function (): void {
    $buffer = new AuditBuffer;
    $handler = new AuditBufferHandler($buffer);

    expect($handler->getLevel())->toBe(Level::Debug)
        ->and($handler->getBubble())->toBeTrue()
        ->and($handler->handle(new LogRecord(new DateTimeImmutable, 'audit', Level::Debug, 'dbg', [])))->toBeFalse()
        ->and($buffer->records())->toHaveCount(1);
});
