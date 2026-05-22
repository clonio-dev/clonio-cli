<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * In-memory capture of structured log events for the audit PDF / process log artefact.
 *
 * The `audit_buffer` log channel writes every log record here via AuditBufferHandler.
 * RunCommand reads `flush()` once the cloning run finishes and embeds the JSONL in
 * the audit delivery payload.
 */
final class AuditBuffer
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    private readonly DateTimeImmutable $startedAt;

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** @param array<string, mixed> $context */
    public function record(DateTimeInterface $datetime, string $level, string $event, array $context): void
    {
        $utc = $datetime instanceof DateTimeImmutable
            ? $datetime->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('@'.$datetime->getTimestamp())->setTimezone(new DateTimeZone('UTC'));

        $ms = sprintf('%03d', (int) ($datetime->format('u') / 1000));
        $ts = $utc->format('Y-m-d\TH:i:s.').$ms.'Z';

        $this->records[] = array_merge([
            'ts' => $ts,
            'level' => strtolower($level),
            'event' => $event,
        ], $context);
    }

    /** Reset the buffer (useful for tests and multi-run scenarios). */
    public function clear(): void
    {
        $this->records = [];
    }

    /** @return list<array<string, mixed>> */
    public function records(): array
    {
        return $this->records;
    }

    /** JSONL (one event per line, trailing newline) for embedding in the audit artefact. */
    public function flush(): string
    {
        if ($this->records === []) {
            return '';
        }

        return implode("\n", array_map(
            static fn (array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->records,
        ))."\n";
    }
}
