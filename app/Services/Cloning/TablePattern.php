<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use InvalidArgumentException;

/**
 * A table name written as a regular expression: `/pattern/flags`, for
 * `tables:` keys and `skip:` entries in cloning.yaml.
 */
final class TablePattern
{
    /**
     * Tells whether the name is written as a pattern: it starts with `/` and
     * ends with `/` plus optional flags.
     */
    public static function isPattern(string $name): bool
    {
        return preg_match('#^/.+/[a-zA-Z]*$#s', $name) === 1;
    }

    /**
     * Tells whether the pattern compiles.
     */
    public static function compiles(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false; // @pest-mutate-ignore: EmptyStringToNotEmpty
    }

    /**
     * @throws InvalidArgumentException when the pattern does not compile
     */
    public static function matches(string $pattern, string $tableName): bool
    {
        $result = @preg_match($pattern, $tableName);

        if ($result === false) {
            throw new InvalidArgumentException(sprintf('Table pattern "%s" is not a valid regular expression.', $pattern));
        }

        return $result === 1;
    }
}
