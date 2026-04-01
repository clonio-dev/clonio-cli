<?php

declare(strict_types=1);

namespace App\Commands\Cloning\Matchers;

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherLoader;
use App\Services\Pii\PiiMatcherYamlReader;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ListCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cloning:matchers:list
        {--path=  : Path to pii-matchers.yaml (default: pii-matchers.yaml in cwd)}';

    /**
     * @var string
     */
    protected $description = 'List the effective PII matchers (file if present, otherwise baseline)';

    public function handle(PiiMatcherLoader $loader, PiiMatcherYamlReader $reader): int
    {
        if ((bool) $this->option('no-interaction')) {
            return ExitCode::Success->value;
        }

        $pathOption = $this->option('path');
        $filePath = is_string($pathOption) && $pathOption !== '' ? $pathOption : 'pii-matchers.yaml';

        $fileExists = Storage::disk('local')->exists($filePath);

        $sourceLabel = $fileExists ? $filePath : 'binary baseline — run cloning:matchers init to customise';

        $this->line('');
        $this->line(sprintf('  Effective PII matchers  (source: %s)', $sourceLabel));
        $this->line('');

        try {
            $matcherSet = $loader->load($filePath);
        } catch (RuntimeException $runtimeException) {
            $this->error(sprintf('  Error loading matchers: %s', $runtimeException->getMessage()));

            return ExitCode::ValidationError->value;
        }

        // Group the flat list back by group for display
        $groupedMatchers = [];

        foreach ($matcherSet->matchers as $matcher) {
            if (! isset($groupedMatchers[$matcher->group])) {
                $groupedMatchers[$matcher->group] = [];
            }

            $groupedMatchers[$matcher->group][] = $matcher;
        }

        $totalActive = 0;
        $totalDisabled = 0;

        // Re-read groups if file exists to get group names, otherwise use a display map
        $groupNames = [];

        if ($fileExists) {
            $content = Storage::disk('local')->get($filePath);

            if (is_string($content)) {
                try {
                    $groups = $reader->read($content);

                    foreach ($groups as $group) {
                        $groupNames[$group->key] = $group->name;
                    }
                } catch (RuntimeException) {
                    // Fall through to use group key as name
                }
            }
        }

        foreach ($groupedMatchers as $groupKey => $matchers) {
            $groupDisplayName = $groupNames[$groupKey] ?? ucwords(str_replace('_', ' ', $groupKey));
            $this->line(sprintf('  %s', $groupDisplayName));

            foreach ($matchers as $matcher) {
                if ($matcher->enabled) {
                    $totalActive++;
                    $statusSymbol = '✓';
                    $transformLabel = '';

                    if ($matcher->transformation->strategy === 'fake' && $matcher->transformation->fakerMethod !== null) {
                        $transformLabel = sprintf('fake → %s', $matcher->transformation->fakerMethod);
                    } elseif ($matcher->transformation->strategy === 'hash') {
                        $transformLabel = sprintf('hash → %s', $matcher->transformation->hashAlgorithm ?? 'sha256');
                    } elseif ($matcher->transformation->strategy === 'mask') {
                        $transformLabel = 'mask';
                    } else {
                        $transformLabel = $matcher->transformation->strategy;
                    }

                    $sourceAnnotation = $matcher->isBaseline ? '[baseline]' : '[file]';
                    $this->line(sprintf(
                        '    %s  %-20s  %-30s  %-25s  %s',
                        $statusSymbol,
                        $matcher->key,
                        sprintf('"%s"', $matcher->name),
                        $transformLabel,
                        $sourceAnnotation,
                    ));
                } else {
                    $totalDisabled++;
                    $sourceAnnotation = $matcher->isBaseline ? '[baseline, disabled]' : '[file, disabled]';
                    $this->line(sprintf(
                        '    —  %-20s  %-30s  %s',
                        $matcher->key,
                        sprintf('"%s"', $matcher->name),
                        $sourceAnnotation,
                    ));
                }
            }

            $this->line('');
        }

        $disabledSuffix = $totalDisabled > 0 ? sprintf('  (%d disabled)', $totalDisabled) : '';
        $this->line(sprintf(
            '  Total: %d active matcher%s across %d group%s%s',
            $totalActive,
            $totalActive === 1 ? '' : 's',
            count($groupedMatchers),
            count($groupedMatchers) === 1 ? '' : 's',
            $disabledSuffix,
        ));
        $this->line(sprintf('  Source: %s', $sourceLabel));
        $this->line('');

        return ExitCode::Success->value;
    }
}
