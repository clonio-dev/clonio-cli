<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Data\ConnectionData;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DeleteCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'connection:delete
        {name? : The name of the connection to delete}
        {--force : Skip confirmation}';

    /**
     * @var string
     */
    protected $description = 'Delete a saved database connection';

    public function handle(ConfigService $config): int
    {
        $connections = $config->getConnections();

        if ($connections === []) {
            $this->error('No connections found.');

            return ExitCode::ConfigError->value;
        }

        $nameArg = $this->argument('name');
        $name = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($name === null) {
            if (count($connections) === 1) {
                $name = array_key_first($connections);
            } else {
                $choice = $this->choice('Select a connection to delete', array_keys($connections));
                $name = is_string($choice) ? $choice : '';
            }
        }

        $connection = $config->getConnection($name);

        if (! $connection instanceof ConnectionData) {
            $this->error(sprintf('No connection named %s found.', $name));

            return ExitCode::ConfigError->value;
        }

        $rows = [
            ['Name', $connection->name],
            ['Type', $connection->type->label()],
        ];

        if ($connection->host !== null) {
            $rows[] = ['Host', $connection->host];
        }

        if ($connection->port !== null) {
            $rows[] = ['Port', (string) $connection->port];
        }

        if ($connection->database !== null) {
            $rows[] = ['Database', $connection->database];
        }

        if ($connection->schema !== null) {
            $rows[] = ['Schema', $connection->schema];
        }

        if ($connection->username !== null) {
            $rows[] = ['Username', $connection->username];
        }

        $rows[] = ['Password', '••••••••'];
        $rows[] = ['Production', $connection->isProduction ? 'Yes' : 'No'];

        $this->table(['Field', 'Value'], $rows);

        if ($connection->isProduction) {
            $this->warn('Warning: This is a production connection!');
        }

        if (! $this->option('force') && ! $this->confirm('Delete this connection?', false)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        try {
            $config->deleteConnection($name);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf('Connection %s deleted.', $name));

        return ExitCode::Success->value;
    }
}
