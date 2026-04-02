<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Data\ConnectionData;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'connection:list';

    /**
     * @var string
     */
    protected $description = 'List all configured database connections';

    public function handle(ConfigService $config): int
    {
        $connections = $config->getConnections();

        if ($connections === []) {
            $this->line('No connections configured. Run `connection:add` to add one.');

            return ExitCode::Success->value;
        }

        $rows = [];

        foreach ($connections as $name => $connection) {
            /** @var ConnectionData $connection */
            $host = $connection->host !== null
                ? $connection->host.($connection->port !== null ? ':'.$connection->port : '')
                : '—';

            $rows[] = [
                $name,
                $connection->type->value,
                $host,
                $connection->database ?? '—',
                $connection->isProduction ? 'Yes' : 'No',
            ];
        }

        $this->table(['Name', 'Driver', 'Host', 'Database', 'Production'], $rows);

        return ExitCode::Success->value;
    }
}
