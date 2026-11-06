<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Services\Config\DotenvFile;
use Illuminate\Support\Env;

/**
 * Finds the key that encrypts the secrets in clonio.json and signs audit logs.
 *
 * Clonio reads its own CLONIO_KEY and falls back to APP_KEY, so setups from
 * before CLONIO_KEY keep working. `key:rotate` moves them to CLONIO_KEY.
 */
final class EncryptionKey
{
    public const string NAME = 'CLONIO_KEY';

    public const string LEGACY_NAME = 'APP_KEY';

    /**
     * Which variable the key is read from, and whether its value comes from
     * .env (rather than from the process environment). Null when neither
     * variable is set.
     *
     * @return array{0: string, 1: bool}|null
     */
    public static function locate(DotenvFile $dotenv): ?array
    {
        foreach ([self::NAME, self::LEGACY_NAME] as $name) {
            $inFile = $dotenv->get($name);
            $inEnvironment = getenv($name);

            if (is_string($inEnvironment) && $inEnvironment !== '') {
                return [$name, $inEnvironment === $inFile];
            }

            if ($inFile !== null) {
                return [$name, true];
            }
        }

        return null;
    }

    /**
     * The key (`base64:…`), or null when neither variable is set.
     */
    public static function value(): ?string
    {
        return self::read(self::NAME) ?? self::read(self::LEGACY_NAME);
    }

    private static function read(string $name): ?string
    {
        $value = Env::get($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
