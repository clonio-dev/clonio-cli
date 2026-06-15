<?php

declare(strict_types=1);

use App\Logging\AuditBuffer;

it('exposes the buffer start time', function (): void {
    $buffer = new AuditBuffer;

    expect($buffer->startedAt())->toBeInstanceOf(DateTimeImmutable::class);
});

it('flushes to an empty string when no records exist', function (): void {
    expect((new AuditBuffer)->flush())->toBe('');
});

it('records an event from a mutable DateTime and flushes JSONL', function (): void {
    $buffer = new AuditBuffer;
    // A mutable DateTime exercises the non-immutable branch of record()
    $buffer->record(new DateTime('2026-04-01T14:32:00.123456', new DateTimeZone('UTC')), 'INFO', 'thing_happened', ['table' => 'users']);

    $records = $buffer->records();
    expect($records)->toHaveCount(1)
        ->and($records[0]['level'])->toBe('info')
        ->and($records[0]['event'])->toBe('thing_happened')
        ->and($records[0]['table'])->toBe('users')
        ->and($records[0]['ts'])->toMatch('/^2026-04-01T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');

    $jsonl = $buffer->flush();
    expect($jsonl)->toEndWith("\n")
        ->and($jsonl)->toContain('"event":"thing_happened"');
});

it('clears recorded events', function (): void {
    $buffer = new AuditBuffer;
    $buffer->record(new DateTimeImmutable, 'info', 'e', []);
    $buffer->clear();

    expect($buffer->records())->toBe([])
        ->and($buffer->flush())->toBe('');
});
