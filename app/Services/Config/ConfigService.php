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

    // ─── Audit Channels ───────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAuditChannels(): array
    {
        $data = $this->load();
        $auditSection = $data['audit'] ?? null;
        $raw = is_array($auditSection) ? ($auditSection['channels'] ?? null) : null;
        if (! is_array($raw)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $raw */
        return $raw;
    }

    /** @return array<string, mixed>|null */
    public function getAuditChannel(string $name): ?array
    {
        $channels = $this->getAuditChannels();

        return $channels[$name] ?? null;
    }

    public function hasAuditChannel(string $name): bool
    {
        return $this->getAuditChannel($name) !== null;
    }

    /** @param array<string, mixed> $channelConfig */
    public function setAuditChannel(string $name, array $channelConfig): void
    {
        $data = $this->load();
        if (! isset($data['audit']) || ! is_array($data['audit'])) {
            $data['audit'] = [
                'channels' => [],
                'use' => [],
            ];
        }

        if (! isset($data['audit']['channels']) || ! is_array($data['audit']['channels'])) {
            $data['audit']['channels'] = [];
        }

        /** @var array<string, mixed> $channels */
        $channels = $data['audit']['channels'];
        $channels[$name] = $channelConfig;
        $data['audit']['channels'] = $channels;
        $this->save($data);
    }

    /** @return list<string> */
    public function getAuditUse(): array
    {
        $data = $this->load();
        $auditSection = $data['audit'] ?? null;
        $raw = is_array($auditSection) ? ($auditSection['use'] ?? null) : null;
        if (! is_array($raw)) {
            return [];
        }

        /** @var list<string> $filtered */
        $filtered = array_values(array_filter($raw, is_string(...)));

        return $filtered;
    }

    /** @param list<string> $channels */
    public function setAuditUse(array $channels): void
    {
        $data = $this->load();
        if (! isset($data['audit']) || ! is_array($data['audit'])) {
            $data['audit'] = ['channels' => [], 'use' => []];
        }

        $data['audit']['use'] = $channels;
        $this->save($data);
    }

    public function addAuditUse(string $name): void
    {
        $current = $this->getAuditUse();
        if (in_array($name, $current, true)) {
            return;
        }

        $current[] = $name;
        $this->setAuditUse($current);
    }

    public function deleteAuditChannel(string $name): void
    {
        $data = $this->load();
        if (! isset($data['audit']) || ! is_array($data['audit'])) {
            return;
        }

        /** @var array<string, mixed> $audit */
        $audit = $data['audit'];
        if (isset($audit['channels']) && is_array($audit['channels'])) {
            /** @var array<string, mixed> $channels */
            $channels = $audit['channels'];
            unset($channels[$name]);
            $audit['channels'] = $channels;
        }

        if (isset($audit['use']) && is_array($audit['use'])) {
            $audit['use'] = array_values(array_filter(
                $audit['use'],
                static fn (mixed $ch): bool => is_string($ch) && $ch !== $name,
            ));
        }

        $data['audit'] = $audit;
        $this->save($data);
    }
}
