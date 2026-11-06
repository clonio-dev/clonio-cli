<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Exceptions\SecretEncryptionException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the secrets stored in clonio.json.
 *
 * A stored secret is one of:
 * - `encrypted:<payload>`: encrypted with the Clonio encryption key
 * - `env:<NAME>`: read from the environment variable NAME at run time, so
 *   the secret is never written to disk
 * - anything else: used as plain text
 */
final class Secret
{
    public const string ENCRYPTED_PREFIX = 'encrypted:';

    public const string ENV_PREFIX = 'env:';

    private const string ENV_NAME_PATTERN = '/^[A-Za-z_]\w*\z/';

    /**
     * Returns the plain-text value of a stored secret.
     *
     * @param  string  $label  what the secret is, for error messages (e.g. "password")
     *
     * @throws RuntimeException when the value cannot be decrypted or the environment variable is not set
     */
    public static function resolve(string $value, string $label = 'secret'): string
    {
        if (str_starts_with($value, self::ENV_PREFIX)) {
            return self::fromEnvironment(substr($value, strlen(self::ENV_PREFIX)), $label);
        }

        if (! str_starts_with($value, self::ENCRYPTED_PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::ENCRYPTED_PREFIX)));
        } catch (Throwable) {
            throw new RuntimeException(sprintf('Could not decrypt %s — check CLONIO_KEY (or APP_KEY).', $label));
        }
    }

    /**
     * Returns the value to store for a secret the user entered: an `env:NAME`
     * reference is kept as is, anything else is encrypted.
     *
     * @throws InvalidArgumentException when an `env:` reference has an invalid variable name
     * @throws SecretEncryptionException when encryption fails (e.g. no encryption key)
     */
    public static function protect(string $input): string
    {
        if (str_starts_with($input, self::ENV_PREFIX)) {
            self::assertValidName(substr($input, strlen(self::ENV_PREFIX)));

            return $input;
        }

        try {
            return self::ENCRYPTED_PREFIX.Crypt::encryptString($input);
        } catch (Throwable $throwable) {
            throw new SecretEncryptionException('Failed to encrypt the secret. Ensure CLONIO_KEY (or APP_KEY) is set in your environment.', $throwable->getCode(), previous: $throwable);
        }
    }

    /**
     * Whether the stored value is encrypted (and must be masked when shown).
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENCRYPTED_PREFIX);
    }

    /**
     * @throws RuntimeException
     */
    private static function fromEnvironment(string $name, string $label): string
    {
        try {
            self::assertValidName($name);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new RuntimeException(sprintf('Could not read %s — %s', $label, $invalidArgumentException->getMessage()), $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }

        $value = Env::getRepository()->get($name);

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('Could not read %s — environment variable %s is not set.', $label, $name));
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertValidName(string $name): void
    {
        if (preg_match(self::ENV_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s%s" is not a valid environment variable reference.', self::ENV_PREFIX, $name));
        }
    }
}
