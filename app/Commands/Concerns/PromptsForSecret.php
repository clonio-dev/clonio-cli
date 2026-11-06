<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\SecretEncryptionException;
use App\Services\Secrets\Secret;
use Illuminate\Support\Env;
use InvalidArgumentException;

/**
 * Asks how Clonio gets a secret. An environment variable is the default, so
 * the secret is never written to disk; entering it now stores it encrypted.
 */
trait PromptsForSecret
{
    private const string SECRET_FROM_ENV = 'Read it from an environment variable (recommended)';

    private const string SECRET_STORED = 'Enter it now and store it encrypted in clonio.json';

    /**
     * Returns the value to store for the secret: `env:NAME` or `encrypted:…`.
     * Returns null when the user picks $skipChoice (e.g. "no password" or
     * "keep the current one"), or enters nothing although a $skipChoice exists.
     *
     * $skipChoice is the default when given, otherwise a reference to
     * $suggestedEnvName. Without interaction, the default applies.
     *
     * @param  string  $label  what the secret is, e.g. "password"
     * @param  string  $suggestedEnvName  the default environment variable name
     * @param  ?string  $skipChoice  an extra choice that stores nothing
     *
     * @throws SecretEncryptionException when encryption fails
     */
    protected function promptForSecret(string $label, string $suggestedEnvName, ?string $skipChoice = null): ?string
    {
        $skipIsDefault = $skipChoice !== null;

        if (! $this->input->isInteractive()) {
            return $skipIsDefault ? null : $this->envReference($suggestedEnvName, $label);
        }

        $choices = [self::SECRET_FROM_ENV, self::SECRET_STORED];

        if ($skipChoice !== null) {
            $choices[] = $skipChoice;
        }

        $answer = $this->choice(sprintf('How should Clonio get the %s?', $label), $choices, $skipIsDefault ? 2 : 0);

        if ($answer === $skipChoice) {
            return null;
        }

        if ($answer === self::SECRET_STORED) {
            $asked = $this->secret(ucfirst($label));
            $value = is_string($asked) ? $asked : '';

            // An optional secret left blank means "none", not an empty secret.
            if ($value === '' && $skipChoice !== null) {
                return null;
            }

            return Secret::protect($value);
        }

        return $this->askForEnvReference($label, $suggestedEnvName);
    }

    /**
     * Builds an environment variable name such as `CLONIO_PROD_PASSWORD` from
     * the given parts. The result is always a valid variable name.
     */
    protected function secretEnvName(string ...$parts): string
    {
        $normalized = array_map(
            // preg_replace() only returns null on a regex error; the pattern is fixed.
            // @pest-mutate-ignore: RemoveStringCast
            static fn (string $part): string => trim(strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $part)), '_'),
            ['clonio', ...$parts],
        );

        return implode('_', array_filter($normalized, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Asks for the variable name until it is valid.
     */
    private function askForEnvReference(string $label, string $suggestedEnvName): string
    {
        while (true) {
            $asked = $this->ask('Environment variable name', $suggestedEnvName);
            $name = ltrim(trim(is_string($asked) ? $asked : $suggestedEnvName), '$');

            if (str_starts_with($name, Secret::ENV_PREFIX)) {
                $name = substr($name, strlen(Secret::ENV_PREFIX));
            }

            try {
                return $this->envReference($name, $label);
            } catch (InvalidArgumentException $invalidArgumentException) {
                $this->error($invalidArgumentException->getMessage());
            }
        }
    }

    /**
     * Returns `env:NAME` and warns when the variable is not set yet.
     *
     * @throws InvalidArgumentException when the name is not a valid variable name
     */
    private function envReference(string $name, string $label): string
    {
        $reference = Secret::protect(Secret::ENV_PREFIX.$name);

        if (Env::getRepository()->get($name) === null) {
            $this->warn(sprintf('  %s is not set. Set it before Clonio uses the %s.', $name, $label));
        }

        return $reference;
    }
}
