<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use RuntimeException;

/**
 * File-backed key-remapping store with AES-256-CBC encryption.
 *
 * Motivation: the default in-memory store holds ALL primary-key mappings for
 * ALL tables in RAM at once.  On very large databases this can exhaust PHP's
 * memory limit.  This store writes each table's mappings to a separate
 * encrypted temporary file and loads exactly one table at a time, keeping
 * RAM usage bounded to the largest single table.
 *
 * Security properties:
 * - A unique 32-byte key is generated per Clonio run and kept only in memory.
 * - Each table's temp file is encrypted with AES-256-CBC using that key and a
 *   random per-file IV.  An attacker with read access to the temp directory
 *   cannot recover mapping data without the in-memory key.
 * - Temp files are created with mode 0600 (owner-readable only).
 * - A shutdown handler and (when available) POSIX signal handlers ensure
 *   temp files are deleted even if Clonio crashes or is interrupted.
 */
class EncryptedFileKeyRemappingStore
{
    private const string CIPHER = 'aes-256-cbc';
    private const int IV_LENGTH = 16;

    /** Per-run encryption key (32 bytes, never written to disk). */
    private readonly string $key;

    /** @var array<string, string> table name → temp file path */
    private array $tableFiles = [];

    /** Currently loaded table name */
    private string $loadedTable = '';

    /** @var array<string, string> Currently loaded mapping [oldValue => newValue] */
    private array $loadedMapping = [];

    public function __construct()
    {
        $this->key = random_bytes(32);

        register_shutdown_function([$this, 'cleanup']);

        if (function_exists('pcntl_signal')) {
            $handler = function (): never {
                $this->cleanup();
                exit(1);
            };

            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
    }

    /**
     * Encrypt and persist a full table mapping to a temp file.
     * Frees the in-memory mapping after writing.
     *
     * @param  array<string, string>  $mappings  [oldValue => newValue]
     */
    public function storeTable(string $table, array $mappings): void
    {
        $json = json_encode($mappings, JSON_THROW_ON_ERROR);
        $iv = random_bytes(self::IV_LENGTH);

        $encrypted = openssl_encrypt($json, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new RuntimeException(sprintf("Failed to encrypt key-mapping data for table '%s'.", $table));
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'clonio-km-');

        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file for key mapping store.');
        }

        chmod($tmpFile, 0600);
        file_put_contents($tmpFile, $iv.$encrypted);

        $this->tableFiles[$table] = $tmpFile;
    }

    /**
     * Decrypt and load a table's mappings into memory.
     * Only one table is held in memory at a time.
     */
    public function loadTable(string $table): void
    {
        if ($this->loadedTable === $table) {
            return;
        }

        $this->loadedMapping = [];
        $this->loadedTable = $table;

        $tmpFile = $this->tableFiles[$table] ?? null;

        if (! is_string($tmpFile) || ! file_exists($tmpFile)) {
            return;
        }

        $data = file_get_contents($tmpFile);

        if ($data === false || strlen($data) <= self::IV_LENGTH) {
            return;
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $encrypted = substr($data, self::IV_LENGTH);
        $json = openssl_decrypt($encrypted, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($json === false) {
            return;
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->loadedMapping = $decoded;
    }

    /**
     * Look up the remapped value for an old PK/FK value.
     * Automatically loads the table's mapping file if needed.
     */
    public function lookup(string $table, string $oldValue): ?string
    {
        if ($this->loadedTable !== $table) {
            $this->loadTable($table);
        }

        $result = $this->loadedMapping[$oldValue] ?? null;

        return is_string($result) ? $result : null;
    }

    /**
     * Delete all temp files and wipe the encryption key from memory.
     * Safe to call multiple times.
     */
    public function cleanup(): void
    {
        foreach ($this->tableFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        $this->tableFiles = [];
        $this->loadedMapping = [];
        $this->loadedTable = '';
    }
}
