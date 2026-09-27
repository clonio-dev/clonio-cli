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

        $name = $this->resolveConnectionName($connections);

        if ($name === null) {
            return ExitCode::ConfigError->value;
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

    /**
     * @param  array<string, ConnectionData>  $connections
     */
    private function resolveConnectionName(array $connections): ?string
    {
        $nameArg = $this->argument('name');

        if (is_string($nameArg) && $nameArg !== '') {
            return $nameArg;
        }

        $names = array_keys($connections);

        if (count($names) === 1) {
            return $names[0];
        }

        if (! $this->input->isInteractive()) {
            $this->error('Multiple connections found; pass the connection name: clonio connection:delete <name>.');

            return null;
        }

        $selected = $this->choice('Select a connection to delete', $names);

        return is_string($selected) ? $selected : $names[0];
    }
}
