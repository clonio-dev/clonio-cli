<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\DatabaseConnectionType;

final readonly class ConnectionData
{
    public function __construct(
        public string $name,
        public DatabaseConnectionType $type,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $schema,
        public ?string $username,
        public string $password,
        public bool $isProduction,
        public bool $trustServerCertificate = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $type = $data['type'] ?? null;
        $host = $data['host'] ?? null;
        $port = $data['port'] ?? null;
        $database = $data['database'] ?? null;
        $schema = $data['schema'] ?? null;
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;

        return new self(
            name: $name,
            type: DatabaseConnectionType::from(is_string($type) ? $type : ''),
            host: is_string($host) ? $host : null,
            port: is_int($port) ? $port : (is_numeric($port) ? (int) $port : null),
            database: is_string($database) ? $database : null,
            schema: is_string($schema) ? $schema : null,
            username: is_string($username) ? $username : null,
            password: is_string($password) ? $password : '',
            isProduction: (bool) ($data['is_production'] ?? false),
            trustServerCertificate: (bool) ($data['trust_server_certificate'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => $this->type->value];

        if ($this->host !== null) {
            $data['host'] = $this->host;
        }

        if ($this->port !== null) {
            $data['port'] = $this->port;
        }

        if ($this->database !== null) {
            $data['database'] = $this->database;
        }

        if ($this->schema !== null) {
            $data['schema'] = $this->schema;
        }

        if ($this->username !== null) {
            $data['username'] = $this->username;
        }

        $data['password'] = $this->password;
        $data['is_production'] = $this->isProduction;

        if ($this->trustServerCertificate) {
            $data['trust_server_certificate'] = true;
        }

        return $data;
    }
}
