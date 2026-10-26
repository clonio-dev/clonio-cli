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

it('normalises an immutable non-UTC timestamp to UTC with millisecond precision', function (): void {
    $buffer = new AuditBuffer;
    $buffer->record(new DateTimeImmutable('2026-04-01T16:32:00.999999', new DateTimeZone('Europe/Berlin')), 'WARNING', 'late', []);
    $buffer->record(new DateTimeImmutable('2026-04-01T16:32:00.001000', new DateTimeZone('Europe/Berlin')), 'DEBUG', 'early', []);

    expect($buffer->records())->toBe([
        ['ts' => '2026-04-01T14:32:00.999Z', 'level' => 'warning', 'event' => 'late'],
        ['ts' => '2026-04-01T14:32:00.001Z', 'level' => 'debug', 'event' => 'early'],
    ]);
});

it('converts a mutable DateTime to UTC without modifying the caller\'s instance', function (): void {
    $datetime = new DateTime('2026-04-01T16:32:00.123456', new DateTimeZone('Europe/Berlin'));

    $buffer = new AuditBuffer;
    $buffer->record($datetime, 'INFO', 'thing', []);

    expect($buffer->records()[0]['ts'])->toBe('2026-04-01T14:32:00.123Z')
        ->and($datetime->getTimezone()->getName())->toBe('Europe/Berlin')
        ->and($datetime->format('H:i'))->toBe('16:32');
});

it('flushes JSONL without escaping unicode or slashes', function (): void {
    $buffer = new AuditBuffer;
    $buffer->record(new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')), 'info', 'a', ['path' => 'a/b', 'name' => 'Müller']);
    $buffer->record(new DateTimeImmutable('2026-04-01T14:32:01', new DateTimeZone('UTC')), 'info', 'b', []);

    expect($buffer->flush())->toBe(
        '{"ts":"2026-04-01T14:32:00.000Z","level":"info","event":"a","path":"a/b","name":"Müller"}'."\n"
        .'{"ts":"2026-04-01T14:32:01.000Z","level":"info","event":"b"}'."\n"
    );
});

it('flushes an unencodable record as an empty line', function (): void {
    $buffer = new AuditBuffer;
    $buffer->record(new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')), 'info', 'broken', ['bytes' => "\xB1\x31"]);

    expect($buffer->flush())->toBe("\n");
});
