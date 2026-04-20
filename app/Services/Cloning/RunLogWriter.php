<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use DateTimeImmutable;
use DateTimeZone;

final class RunLogWriter
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var (callable(string, string, array<string, mixed>): void)|null */
    private $liveOutput;

    private readonly DateTimeImmutable $startedAt;

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** @param callable(string, string, array<string, mixed>): void $callback */
    public function setLiveOutput(callable $callback): void
    {
        $this->liveOutput = $callback;
    }

    /** @param array<string, mixed> $extra */
    public function log(string $level, string $event, array $extra = []): void
    {
        $ms = sprintf('%03d', (int) (microtime(true) * 1000) % 1000);
        $ts = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.').$ms.'Z';

        $this->events[] = array_merge([
            'ts' => $ts,
            'level' => $level,
            'event' => $event,
        ], $extra);

        if ($this->liveOutput !== null) {
            ($this->liveOutput)($level, $event, $extra);
        }
    }

    public function flush(): string
    {
        return implode("\n", array_map(
            static fn (array $e): string => (string) json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->events
        ))."\n";
    }
}
