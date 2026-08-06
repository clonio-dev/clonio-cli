<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'connection:update {name? : The name of the connection to update}';

    /**
     * @var string
     */
    protected $description = 'Update an existing database connection';

    public function handle(ConfigService $config): int
    {
        $name = $this->resolveConnectionName($config);

        if ($name === null) {
            return ExitCode::ConfigError->value;
        }

        $current = $config->getConnection($name);

        if (! $current instanceof ConnectionData) {
            $this->error(sprintf('No connection named %s found.', $name));

            return ExitCode::ConfigError->value;
        }

        $updated = $this->promptForFields($current);

        $newName = $updated->name;
        $nameChanged = $newName !== $name;

        if ($nameChanged && $config->hasConnection($newName)) {
            $this->error(sprintf("A connection named '%s' already exists.", $newName));

            return ExitCode::ValidationError->value;
        }

        $this->showDiff($current, $updated);

        if (! $this->confirm('Save changes?', true)) {
            $this->info('No changes saved.');

            return ExitCode::Success->value;
        }

        try {
            if ($nameChanged) {
                $config->renameConnection($name, $newName, $updated);
            } else {
                $config->setConnection($name, $updated);
            }
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf("Connection '%s' updated successfully.", $newName));

        return ExitCode::Success->value;
    }

    private function resolveConnectionName(ConfigService $config): ?string
    {
        $nameArg = $this->argument('name');

        if (is_string($nameArg) && $nameArg !== '') {
            return $nameArg;
        }

        $connections = $config->getConnections();

        if ($connections === []) {
            $this->error('No connections found in clonio.json.');

            return null;
        }

        $names = array_keys($connections);

        if (count($names) === 1) {
            return $names[0];
        }

        $selected = $this->choice('Which connection do you want to update?', $names);

        return is_string($selected) ? $selected : $names[0];
    }

    private function promptForFields(ConnectionData $current): ConnectionData
    {
        $newName = $this->askString('Connection name', $current->name);

        $typeValues = DatabaseConnectionType::values();
        $currentTypeIndex = array_search($current->type->value, $typeValues, true);
        $selectedDriver = $this->choice(
            'Database driver',
            $typeValues,
            $currentTypeIndex !== false ? (int) $currentTypeIndex : 0,
        );
        $newTypeValue = is_string($selectedDriver) ? $selectedDriver : $current->type->value;
        $newType = DatabaseConnectionType::from($newTypeValue);
        $typeChanged = $newType !== $current->type;

        $attrSslCa = null;
        if ($newType->requiresNetworkConfig()) {
            if ($typeChanged) {
                $host = $this->askString('Host', 'localhost');
                $portRaw = $this->askString('Port', (string) $newType->defaultPort());
                $port = $portRaw !== '' ? (int) $portRaw : $newType->defaultPort();
                $database = $this->askString('Database', '');
                $username = $this->askString('Username', '');
            } else {
                $host = $this->askString('Host', $current->host ?? '');
                $portRaw = $this->askString('Port', (string) $current->port);
                $port = $portRaw !== '' ? (int) $portRaw : $current->port;
                $database = $this->askString('Database', $current->database ?? '');
                $username = $this->askString('Username', $current->username ?? '');
            }
        } else {
            $host = null;
            $port = null;
            if ($typeChanged) {
                $database = $this->askString('Database file path', '');
                $username = null;
            } else {
                $database = $this->askString('Database file path', $current->database ?? '');
                $username = null;
            }
        }

        $schema = null;
        if ($newType->requiresSchema()) {
            $schema = $typeChanged ? $this->askString('Schema', 'public') : $this->askString('Schema', $current->schema ?? 'public');
        }

        $passwordInput = $this->askString('Password (press Enter to keep current)', '');
        if ($passwordInput === '') {
            $password = $current->password;
        } else {
            $password = Crypt::encryptString($passwordInput);
        }

        if($newType->requiresNetworkConfig() && ($newType === DatabaseConnectionType::Mysql || $newType === DatabaseConnectionType::MariaDB)) {
            $attrSslCa = $this->ask('SSL Certificate Authority', $current->attrSslCa ?? null);
        }

        $isProduction = $this->confirm('Is this a production connection?', $current->isProduction);

        $trustServerCertificate = false;
        if ($newType === DatabaseConnectionType::SqlServer) {
            $trustServerCertificate = $this->confirm(
                'Trust server certificate? (required for self-signed certs)',
                $typeChanged ? false : $current->trustServerCertificate,
            );
        }

        return new ConnectionData(
            name: $newName,
            type: $newType,
            host: $host !== '' ? $host : null,
            port: $port !== null && $port !== 0 ? $port : null,
            database: $database !== '' ? $database : null,
            schema: $schema !== null && $schema !== '' ? $schema : null,
            username: $username !== null && $username !== '' ? $username : null,
            password: $password,
            isProduction: $isProduction,
            trustServerCertificate: $trustServerCertificate,
            attrSslCa: $attrSslCa,
        );
    }

    private function askString(string $question, string $default): string
    {
        $answer = $this->ask($question, $default !== '' ? $default : null);

        return is_string($answer) ? $answer : $default;
    }

    private function showDiff(ConnectionData $old, ConnectionData $new): void
    {
        $passwordChanged = $old->password !== $new->password;

        /** @var array<string, array{string, string}> $fields */
        $fields = [
            'name' => [$old->name, $new->name],
            'type' => [$old->type->value, $new->type->value],
            'host' => [$old->host ?? '', $new->host ?? ''],
            'port' => [(string) ($old->port ?? ''), (string) ($new->port ?? '')],
            'database' => [$old->database ?? '', $new->database ?? ''],
            'schema' => [$old->schema ?? '', $new->schema ?? ''],
            'username' => [$old->username ?? '', $new->username ?? ''],
            'password' => [
                $passwordChanged ? '••••••••' : '(unchanged)',
                $passwordChanged ? '••••••••' : '(unchanged)',
            ],
            'is_production' => [
                $old->isProduction ? 'true' : 'false',
                $new->isProduction ? 'true' : 'false',
            ],
            'trust_server_certificate' => [
                $old->trustServerCertificate ? 'true' : 'false',
                $new->trustServerCertificate ? 'true' : 'false',
            ],
        ];

        $changed = array_filter($fields, static fn (array $pair): bool => $pair[0] !== $pair[1]);

        if ($changed === []) {
            $this->info('No fields changed.');

            return;
        }

        $rows = array_map(
            static fn (string $field, array $pair): array => [$field, $pair[0], $pair[1]],
            array_keys($changed),
            array_values($changed),
        );

        $this->table(['Field', 'Old', 'New'], $rows);
    }
}
