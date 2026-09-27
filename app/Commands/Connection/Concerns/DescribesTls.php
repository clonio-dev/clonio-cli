<?php

declare(strict_types=1);

namespace App\Commands\Connection\Concerns;

use App\Data\ConnectionData;

/**
 * Shared TLS-mode labelling for connection:list and connection:test (PRD-connection-tls §6).
 */
trait DescribesTls
{
    /** '—' for connection types without network config (sqlite, dump), else the configured/'default' mode. */
    private function tlsLabel(ConnectionData $connection): string
    {
        return $connection->type->requiresNetworkConfig()
            ? ($connection->ssl?->mode->value ?? 'default')
            : '—';
    }
}
