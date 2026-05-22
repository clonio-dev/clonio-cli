<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Logger;

/**
 * Custom-driver factory for the `audit_buffer` log channel.
 *
 * Wired via config/logging.php `'driver' => 'custom', 'via' => CreateAuditBufferLogger::class`.
 * Laravel's LogManager invokes `__invoke($config)` and the returned Monolog Logger becomes
 * the channel implementation.
 */
final readonly class CreateAuditBufferLogger
{
    public function __construct(private AuditBuffer $buffer) {}

    /** @param array<string, mixed> $config */
    public function __invoke(array $config): Logger
    {
        $name = is_string($config['name'] ?? null) ? $config['name'] : 'audit_buffer';

        return new Logger($name, [new AuditBufferHandler($this->buffer)]);
    }
}
