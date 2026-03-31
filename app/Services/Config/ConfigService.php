<?php

declare(strict_types=1);

namespace App\Services\Config;

use App\Data\ConnectionData;
use RuntimeException;

class ConfigService
{
    private const string FILENAME = 'clonio.json';

    private readonly string $configPath;

    public function __construct(?string $workingDirectory = null)
    {
        $dir = $workingDirectory ?? (string) getcwd();
        $this->configPath = $dir.DIRECTORY_SEPARATOR.self::FILENAME;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function exists(): bool
    {
        return file_exists($this->configPath);
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (! file_exists($this->configPath)) {
            return ['connections' => []];
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new RuntimeException(sprintf('Cannot read %s: permission denied', $this->configPath));
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON in '.$this->configPath);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        throw_if($content === false, RuntimeException::class, 'Failed to encode configuration as JSON');

        $result = file_put_contents($this->configPath, $content);
        if ($result === false) {
            throw new RuntimeException(sprintf('Cannot write to %s: permission denied', $this->configPath));
        }

        chmod($this->configPath, 0600);
    }

    /** @return array<string, ConnectionData> */
    public function getConnections(): array
    {
        $data = $this->load();
        $raw = $data['connections'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $connections = [];

        foreach ($raw as $name => $connectionData) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_array($connectionData)) {
                continue;
            }

            /** @var array<string, mixed> $connectionData */
            $connections[$name] = ConnectionData::fromArray($name, $connectionData);
        }

        return $connections;
    }

    public function getConnection(string $name): ?ConnectionData
    {
        $data = $this->load();
        $raw = $data['connections'] ?? null;

        if (! is_array($raw) || ! array_key_exists($name, $raw)) {
            return null;
        }

        $connectionData = $raw[$name];
        if (! is_array($connectionData)) {
            return null;
        }

        /** @var array<string, mixed> $connectionData */
        return ConnectionData::fromArray($name, $connectionData);
    }

    public function hasConnection(string $name): bool
    {
        return $this->getConnection($name) instanceof ConnectionData;
    }

    public function setConnection(string $name, ConnectionData $connection): void
    {
        $data = $this->load();
        $raw = $data['connections'] ?? null;
        /** @var array<string, mixed> $connections */
        $connections = is_array($raw) ? $raw : [];
        $connections[$name] = $connection->toArray();
        $data['connections'] = $connections;
        $this->save($data);
    }

    public function deleteConnection(string $name): void
    {
        $data = $this->load();
        $raw = $data['connections'] ?? null;
        /** @var array<string, mixed> $connections */
        $connections = is_array($raw) ? $raw : [];
        unset($connections[$name]);
        $data['connections'] = $connections;
        $this->save($data);
    }

    public function renameConnection(string $oldName, string $newName, ConnectionData $connection): void
    {
        $data = $this->load();
        $raw = $data['connections'] ?? null;
        /** @var array<string, mixed> $connections */
        $connections = is_array($raw) ? $raw : [];
        unset($connections[$oldName]);
        $connections[$newName] = $connection->toArray();
        $data['connections'] = $connections;
        $this->save($data);
    }
}
