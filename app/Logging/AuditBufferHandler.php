<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler that forwards every record into the shared AuditBuffer singleton.
 *
 * Bridges the Laravel Log facade calls (`Log::info('event', ['key' => 'val'])`) to
 * the audit log artefact without exposing AuditBuffer as a logging concern.
 */
final class AuditBufferHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly AuditBuffer $buffer,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        /** @var array<string, mixed> $context */
        $context = $record->context;

        $this->buffer->record(
            datetime: $record->datetime,
            level: $record->level->getName(),
            event: $record->message,
            context: $context,
        );
    }
}
