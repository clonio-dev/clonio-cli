<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Commands\Connection\Concerns\PromptsForSsl;
use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    use PromptsForSsl;

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

        // Only re-check the certificate files on disk when the ssl block actually changed
        // (M4): an unrelated field update (e.g. password) shouldn't fail because a
        // previously-accepted certificate path is now missing.
        $sslChanged = $current->ssl?->toArray() !== $updated->ssl?->toArray();
        $sslErrors = $this->sslErrors($updated->type, $updated->ssl, checkFiles: $sslChanged);

        if ($sslErrors !== []) {
            foreach ($sslErrors as $sslError) {
                $this->error($sslError);
            }

            return ExitCode::ValidationError->value;
        }

        $this->warnIfKeyExposed($updated->ssl);

        $newName = $updated->name;
        $nameChanged = $newName !== $name;

        if ($nameChanged && $config->hasConnection($newName)) {
            $this->error(sprintf("A connection named '%s' already exists.", $newName));

            return ExitCode::ValidationError->value;
        }

        $this->showDiff($current, $updated);

        $save = $this->input->isInteractive() ? $this->confirm('Save changes?', true) : true;

        if (! $save) {
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

        if ($this->input->isInteractive()) {
            $selectedDriver = $this->choice(
                'Database driver',
                $typeValues,
                $currentTypeIndex !== false ? (int) $currentTypeIndex : 0,
            );
            $newTypeValue = is_string($selectedDriver) ? $selectedDriver : $current->type->value;
        } else {
            $newTypeValue = $current->type->value;
        }

        $newType = DatabaseConnectionType::from($newTypeValue);
        $typeChanged = $newType !== $current->type;

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

        $ssl = $newType->requiresNetworkConfig() ? $this->promptForSsl($current, $newType) : null;

        $isProduction = $this->input->isInteractive()
            ? $this->confirm('Is this a production connection?', $current->isProduction)
            : $current->isProduction;

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
            dialect: $current->dialect,
            ssl: $ssl,
        );
    }

    /**
     * Pre-selects the stored mode. Connections without ssl keep "Driver default", except a
     * legacy sqlsrv trust flag (migrated to require) and a switch from sqlite/dump (new default require).
     */
    private function promptForSsl(ConnectionData $current, DatabaseConnectionType $newType): ?SslConfig
    {
        $currentSsl = $current->type->requiresNetworkConfig() ? $current->ssl : null;

        $defaultMode = match (true) {
            $currentSsl instanceof SslConfig => $currentSsl->mode,
            $current->trustServerCertificate, ! $current->type->requiresNetworkConfig() => SslMode::Require,
            default => null,
        };

        $mode = $this->askSslMode($defaultMode);

        if (! $mode instanceof SslMode) {
            return null;
        }

        if ($newType === DatabaseConnectionType::SqlServer && $currentSsl?->hasCertificateFiles() === true) {
            $this->info('Certificate paths are not supported for sqlsrv and were removed.');
        }

        return $this->askSslFiles($newType, $mode, $currentSsl);
    }

    private function askString(string $question, string $default): string
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

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
            'ssl.mode' => [$old->ssl?->mode->value ?? 'default', $new->ssl?->mode->value ?? 'default'],
            'ssl.ca' => [$old->ssl->ca ?? '', $new->ssl->ca ?? ''],
            'ssl.cert' => [$old->ssl->cert ?? '', $new->ssl->cert ?? ''],
            'ssl.key' => [$old->ssl->key ?? '', $new->ssl->key ?? ''],
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
