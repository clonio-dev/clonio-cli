<?php

declare(strict_types=1);

namespace App\Commands\Cloning\Matchers;

use App\Data\Pii\PiiMatcherData;
use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherLoader;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'matchers:check
        {column  : Column name to test against the active matcher set}
        {--path= : Path to pii-matchers.yaml (default: pii-matchers.yaml in cwd)}';

    /**
     * @var string
     */
    protected $description = 'Check which PII matcher (if any) fires for a given column name';

    public function handle(PiiMatcherLoader $loader): int
    {
        $column = (string) $this->argument('column');

        $pathOption = $this->option('path');
        $filePath = is_string($pathOption) && $pathOption !== '' ? $pathOption : null;

        try {
            $matcherSet = $loader->load($filePath);
        } catch (RuntimeException $runtimeException) {
            $this->error(sprintf('  Error loading matchers: %s', $runtimeException->getMessage()));

            return ExitCode::Success->value;
        }

        $matched = $matcherSet->match($column);

        $this->line('');

        if (! $matched instanceof PiiMatcherData) {
            $this->line(sprintf('  Column "%s" — no matcher found', $column));
            $this->line('');
            $this->line('  This column will be treated as strategy: keep by cloning:dump.');
            $this->line('');

            return ExitCode::Success->value;
        }

        $this->line(sprintf('  Column "%s" matched:', $column));
        $this->line('');
        $this->line(sprintf('    Matcher:        %s', $matched->key));
        $this->line(sprintf('    Group:          %s', $matched->group));
        $this->line(sprintf('    PII category:   "%s"', $matched->name));
        $this->line(sprintf('    Source:         %s', $matched->isBaseline ? 'binary baseline' : 'pii-matchers.yaml'));

        // Determine which pattern matched and its type
        $matchedPattern = $this->findMatchedPattern($column, $matched->patterns);

        if ($matchedPattern !== null) {
            $patternType = $this->detectPatternType($matchedPattern);
            $this->line(sprintf('    Matched by:     %s  (%s)', $matchedPattern, $patternType));
        }

        $this->line('');
        $this->line('    Transformation:');

        $t = $matched->transformation;
        $this->line(sprintf('      strategy:       %s', $t->strategy));

        if ($t->strategy === 'fake') {
            $this->line(sprintf('      faker_method:   %s', $t->fakerMethod ?? ''));

            $argsJson = json_encode($t->fakerArguments) ?: '[]';
            $this->line(sprintf('      faker_arguments: %s', $argsJson));
        } elseif ($t->strategy === 'hash') {
            $this->line(sprintf('      algorithm:      %s', $t->hashAlgorithm ?? 'sha256'));
            $this->line(sprintf('      salt:           "%s"', $t->hashSalt ?? ''));
        } elseif ($t->strategy === 'mask') {
            $this->line(sprintf('      visible_chars:  %d', $t->visibleChars ?? 0));
            $this->line(sprintf('      mask_char:      "%s"', $t->maskChar ?? '*'));
            $this->line(sprintf('      preserve_format: %s', ($t->preserveFormat ?? false) ? 'true' : 'false'));
        } elseif ($t->strategy === 'static') {
            $this->line(sprintf('      value:          "%s"', $t->staticValue ?? ''));
        }

        $this->line('');

        return ExitCode::Success->value;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function findMatchedPattern(string $columnName, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($columnName, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    private function matchesPattern(string $columnName, string $pattern): bool
    {
        if (str_starts_with($pattern, '/')) {
            return (bool) preg_match($pattern, $columnName);
        }

        if (str_contains($pattern, '*')) {
            $parts = explode('*', $pattern);
            $regex = '/^'.implode('.*', array_map(fn (string $p): string => preg_quote($p, '/'), $parts)).'$/i';

            return (bool) preg_match($regex, $columnName);
        }

        return strcasecmp($columnName, $pattern) === 0;
    }

    private function detectPatternType(string $pattern): string
    {
        if (str_starts_with($pattern, '/')) {
            return 'regex';
        }

        if (str_contains($pattern, '*')) {
            return 'glob';
        }

        return 'literal';
    }
}
