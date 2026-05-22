<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\ColumnCloningConfigData;
use Faker\Factory;
use Faker\Generator;

class AnonymizationEngine
{
    private readonly Generator $faker;

    public function __construct(string $locale = 'en_US')
    {
        $this->faker = Factory::create($locale);
    }

    /**
     * Apply transformation to a single value.
     * Returns the transformed value.
     */
    public function transform(mixed $value, ColumnCloningConfigData $config): mixed
    {
        return match ($config->strategy) {
            'null' => null,
            'keep' => $value,
            'static' => $config->staticValue,
            'fake' => $this->applyFake($config),
            'hash' => $this->applyHash(is_scalar($value) ? (string) $value : '', $config),
            'mask' => $this->applyMask(is_scalar($value) ? (string) $value : '', $config),
            'template' => $this->applyTemplate($config),
            default => $value,
        };
    }

    private function applyFake(ColumnCloningConfigData $config): mixed
    {
        $method = $config->fakerMethod;

        if ($method === null || $method === '') {
            return null;
        }

        /** @var mixed $result */
        $result = $this->faker->{$method}(...$config->fakerArguments);

        if (is_array($result)) {
            return implode(' ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $result));
        }

        return $result;
    }

    private function applyHash(string $value, ColumnCloningConfigData $config): string
    {
        return hash($config->hashAlgorithm ?? 'sha256', ($config->hashSalt ?? '').$value);
    }

    /**
     * Expand a template string by replacing `{fakerMethod}` placeholders with
     * Faker output. Literal text passes through unchanged. Useful for mixing
     * randomized parts with fixed parts (e.g. fixed email domain).
     *
     * Example: `{userName}@acme.test` → `alice.j42@acme.test`
     *
     * Unknown methods on the Faker generator render as the empty string so
     * pipelines fail soft; the validator rejects them at config-load time.
     */
    private function applyTemplate(ColumnCloningConfigData $config): string
    {
        $template = $config->template;

        if ($template === null || $template === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{([a-zA-Z][a-zA-Z0-9]*)\}/',
            function (array $matches): string {
                $method = $matches[1];

                if (! method_exists($this->faker, $method)) {
                    return '';
                }

                /** @var mixed $result */
                $result = $this->faker->{$method}();

                if (is_array($result)) {
                    return implode(' ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $result));
                }

                return is_scalar($result) ? (string) $result : '';
            },
            $template,
        );
    }

    private function applyMask(string $value, ColumnCloningConfigData $config): string
    {
        $visible = $config->visibleChars ?? 0;
        $maskChar = $config->maskChar ?? '*';
        $preserve = $config->preserveFormat ?? false;

        if ($preserve) {
            $result = '';
            $alphanumSeen = 0;

            foreach (str_split($value) as $char) {
                if (! preg_match('/[a-zA-Z0-9]/', $char)) {
                    $result .= $char;
                } elseif ($alphanumSeen < $visible) {
                    $result .= $char;
                    $alphanumSeen++;
                } else {
                    $result .= $maskChar;
                }
            }

            return $result;
        }

        $len = mb_strlen($value);

        if ($len <= $visible) {
            return $value;
        }

        return mb_substr($value, 0, $visible).str_repeat($maskChar, $len - $visible);
    }
}
