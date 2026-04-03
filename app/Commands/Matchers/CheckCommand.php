<?php

declare(strict_types=1);

namespace App\Commands\Matchers;

use App\Data\Pii\PiiMatcherData;
use App\Enums\ExitCode;
use App\Services\Cloning\AnonymizationEngine;
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
        {value?  : Optional value to test the transformation with (omit to use the built-in example)}
        {--path= : Path to clonio.pii-matchers.yaml (default: clonio.pii-matchers.yaml in cwd)}';

    /**
     * @var string
     */
    protected $description = 'Check which PII matcher (if any) fires for a given column name';

    public function handle(PiiMatcherLoader $loader, AnonymizationEngine $engine): int
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

        // Resolve the input value for the example
        $rawValue = $this->argument('value');
        $inputValue = null;

        if (is_string($rawValue) && $rawValue !== '') {
            $inputValue = $this->validateUserValue($rawValue);

            if ($inputValue === null) {
                return ExitCode::Success->value;
            }
        } elseif ($matched->exampleValue !== null) {
            $inputValue = $matched->exampleValue;
        }

        $this->line(sprintf('  Column "%s" matched:', $column));
        $this->line('');
        $this->line(sprintf('    Matcher:        %s', $matched->key));
        $this->line(sprintf('    Group:          %s', $matched->group));
        $this->line(sprintf('    PII category:   "%s"', $matched->name));
        $this->line(sprintf('    Sensitivity:    %s', $matched->sensitivity->label()));
        $this->line(sprintf('    Source:         %s', $matched->isBaseline ? 'binary baseline' : 'clonio.pii-matchers.yaml'));

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

        $this->showExample($matched, $engine, $inputValue);

        return ExitCode::Success->value;
    }

    private function showExample(PiiMatcherData $matched, AnonymizationEngine $engine, ?string $inputValue): void
    {
        if ($inputValue === null) {
            $this->line('    Example:');
            $this->line('      (pass a value as the 2nd argument to test the transformation)');
            $this->line('');

            return;
        }

        $output = $engine->transform($inputValue, $matched->transformation);

        $outputStr = match (true) {
            $output === null => '(null)',
            is_scalar($output) => (string) $output,
            default => '(non-scalar)',
        };

        $isFake = $matched->transformation->strategy === 'fake';

        $this->line('    Example:');
        $this->line(sprintf('      Input:   %s', $inputValue));

        if ($isFake) {
            $this->line(sprintf('      Output:  %s  (faker generates fresh data — input value is not used)', $outputStr));
        } else {
            $this->line(sprintf('      Output:  %s', $outputStr));
        }

        $this->line('');
    }

    private function validateUserValue(string $raw): ?string
    {
        if (trim($raw) === '') {
            $this->error('  Value cannot be empty or whitespace only.');

            return null;
        }

        if (mb_strlen($raw) > 10000) {
            $this->error('  Value is too long (max 10,000 characters).');

            return null;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) === 1) {
            $this->error('  Value contains invalid control characters.');

            return null;
        }

        return $raw;
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
