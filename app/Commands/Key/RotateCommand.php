<?php

declare(strict_types=1);

namespace App\Commands\Key;

use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use App\Services\Config\DotenvFile;
use App\Services\Secrets\EncryptionKey;
use App\Services\Secrets\Secret;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RotateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'key:rotate
        {--force : Rotate without asking for confirmation}';

    /**
     * @var string
     */
    protected $description = 'Replace the encryption key with a new CLONIO_KEY and re-encrypt every encrypted secret in clonio.json';

    private int $reencrypted = 0;

    public function handle(ConfigService $config, DotenvFile $dotenv): int
    {
        $found = EncryptionKey::locate($dotenv);

        if ($found === null) {
            $this->error('No encryption key found. Set CLONIO_KEY or run `clonio init` first.');

            return ExitCode::ConfigError->value;
        }

        $cipher = Config::string('app.cipher');
        $rawKey = Encrypter::generateKey($cipher);
        $newKey = 'base64:'.base64_encode($rawKey);

        try {
            $this->reencrypted = 0;
            $rotated = $this->reencrypt($config->load(), new Encrypter($rawKey, $cipher));
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::ConfigError->value;
        }

        [$current, $fromDotenv] = $found;

        $this->line(sprintf('  Current key: %s (%s)', $current, $fromDotenv ? '.env' : 'environment'));
        $this->line(sprintf('  Encrypted secrets in clonio.json: %d', $this->reencrypted));
        $this->line('');
        $this->warn('  ⚠  Audit logs signed so far verify only with the current key.');
        $this->warn('     Keep it for as long as you need to verify them.');
        $this->line('');

        if (! $this->option('force') && ! $this->confirm('  Rotate the key?', false)) {
            $this->line('  Cancelled.');

            return ExitCode::Success->value;
        }

        return $fromDotenv
            ? $this->rotateInDotenv($config, $dotenv, $rotated, $newKey, $current)
            : $this->rotateInEnvironment($config, $rotated, $newKey);
    }

    /**
     * The key comes from the process environment (e.g. a CI secret), which
     * Clonio cannot change: save clonio.json and show the new key once.
     *
     * @param  array<string, mixed>  $rotated
     */
    private function rotateInEnvironment(ConfigService $config, array $rotated, string $newKey): int
    {
        try {
            $this->saveConfig($config, $rotated);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf('  ✓  Re-encrypted %d %s in clonio.json.', $this->reencrypted, Str::plural('secret', $this->reencrypted)));
        $this->line('');
        $this->line('  Store the new key as CLONIO_KEY wherever Clonio runs (e.g. your CI secret store).');
        $this->line('  It is shown only now:');
        $this->line('');
        $this->line(sprintf('  CLONIO_KEY=%s', $newKey));
        $this->line('');

        return ExitCode::Success->value;
    }

    /**
     * The key comes from .env: write the new CLONIO_KEY there, then save
     * clonio.json. When saving fails, .env is put back, so the stored secrets
     * and the key always match.
     *
     * @param  array<string, mixed>  $rotated
     */
    private function rotateInDotenv(ConfigService $config, DotenvFile $dotenv, array $rotated, string $newKey, string $current): int
    {
        try {
            $previous = $dotenv->read();
            $dotenv->set(EncryptionKey::NAME, $newKey);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        try {
            $this->saveConfig($config, $rotated);
        } catch (RuntimeException $runtimeException) {
            $dotenv->restore($previous);
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info('  ✓  Wrote the new CLONIO_KEY to .env.');
        $this->info(sprintf('  ✓  Re-encrypted %d %s in clonio.json.', $this->reencrypted, Str::plural('secret', $this->reencrypted)));

        if ($current === EncryptionKey::LEGACY_NAME) {
            $this->line('  ℹ  Clonio now uses CLONIO_KEY. APP_KEY stays in .env for other tools;');
            $this->line('     remove it if nothing else needs it.');
        }

        $this->line('');

        return ExitCode::Success->value;
    }

    /**
     * @param  array<string, mixed>  $rotated
     */
    private function saveConfig(ConfigService $config, array $rotated): void
    {
        if ($config->exists()) {
            $config->save($rotated);
        }
    }

    /**
     * Decrypts every `encrypted:` value with the current key and encrypts it
     * with the new one. Other values (`env:` references, plain text) stay.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RuntimeException when a value cannot be decrypted with the current key
     */
    private function reencrypt(array $data, Encrypter $encrypter): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = $this->reencryptValue($value, $encrypter, (string) $key);
        }

        return $data;
    }

    /**
     * @param  string  $location  where the value sits in clonio.json, for error messages
     *
     * @throws RuntimeException
     */
    private function reencryptValue(mixed $value, Encrypter $encrypter, string $location): mixed
    {
        if (is_string($value) && Secret::isEncrypted($value)) {
            $this->reencrypted++;

            return Secret::ENCRYPTED_PREFIX.$encrypter->encryptString(Secret::resolve($value, $location));
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->reencryptValue($item, $encrypter, $location.'.'.$key);
            }
        }

        return $value;
    }
}
