<?php

declare(strict_types=1);

namespace App\Data\Pii;

final readonly class PiiMatcherSetData
{
    /** @param list<PiiMatcherData> $matchers */
    public function __construct(
        public array $matchers,
    ) {}

    public function match(string $columnName): ?PiiMatcherData
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->enabled && $this->columnMatchesPatterns($columnName, $matcher->patterns)) {
                return $matcher;
            }
        }

        return null;
    }

    /** @param list<string> $patterns */
    private function columnMatchesPatterns(string $columnName, array $patterns): bool
    {
        return array_any($patterns, fn (string $pattern): bool => $this->matchesPattern($columnName, $pattern));
    }

    private function matchesPattern(string $columnName, string $pattern): bool
    {
        // Regex: starts and ends with /
        if (str_starts_with($pattern, '/')) {
            return (bool) preg_match($pattern, $columnName);
        }

        // Glob: contains *
        if (str_contains($pattern, '*')) {
            $parts = explode('*', $pattern);
            $regex = '/^'.implode('.*', array_map(fn (string $p): string => preg_quote($p, '/'), $parts)).'$/i';

            return (bool) preg_match($regex, $columnName);
        }

        // Literal: exact case-insensitive match
        return strcasecmp($columnName, $pattern) === 0;
    }
}
