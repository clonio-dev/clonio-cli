<?php

declare(strict_types=1);

namespace App\Services\Config;

use App\Data\ConnectionData;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ConfigService
{
    private const string FILENAME = 'clonio.json';

    public function getConfigPath(): string
    {
        return Storage::path(self::FILENAME);
    }

    public function exists(): bool
    {
        return Storage::exists(self::FILENAME);
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (! Storage::exists(self::FILENAME)) {
            return ['connections' => []];
        }

        $content = Storage::get(self::FILENAME);

        if ($content === null) {
            throw new RuntimeException(sprintf('Cannot read %s: permission denied', $this->getConfigPath()));
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON in %s', $this->getConfigPath()));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        throw_if($content === false, RuntimeException::class, 'Failed to encode configuration as JSON');

        $result = Storage::put(self::FILENAME, $content);

        if (! $result) {
            throw new RuntimeException(sprintf('Cannot write %s: permission denied', $this->getConfigPath()));
        }

        chmod($this->getConfigPath(), 0600);
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
