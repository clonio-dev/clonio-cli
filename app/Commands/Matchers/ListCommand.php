<?php

declare(strict_types=1);

namespace App\Commands\Matchers;

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
    protected $signature = 'matchers:list
        {--path=  : Path to clonio.pii-matchers.yaml (default: clonio.pii-matchers.yaml in cwd)}';

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
        $filePath = is_string($pathOption) && $pathOption !== '' ? $pathOption : 'clonio.pii-matchers.yaml';

        $fileExists = Storage::disk('local')->exists($filePath);

        $sourceLabel = $fileExists ? $filePath : 'binary baseline — run matchers:init to customise';

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

        $rows = [];

        foreach ($groupedMatchers as $groupKey => $matchers) {
            $groupDisplayName = $groupNames[$groupKey] ?? ucwords(str_replace('_', ' ', $groupKey));

            foreach ($matchers as $matcher) {
                if ($matcher->enabled) {
                    $totalActive++;

                    if ($matcher->transformation->strategy === 'fake' && $matcher->transformation->fakerMethod !== null) {
                        $transformLabel = sprintf('fake → %s', $matcher->transformation->fakerMethod);
                    } elseif ($matcher->transformation->strategy === 'hash') {
                        $transformLabel = sprintf('hash → %s', $matcher->transformation->hashAlgorithm ?? 'sha256');
                    } elseif ($matcher->transformation->strategy === 'mask') {
                        $transformLabel = 'mask';
                    } else {
                        $transformLabel = $matcher->transformation->strategy;
                    }

                    $rows[] = [
                        '✓',
                        $groupDisplayName,
                        $matcher->key,
                        $matcher->name,
                        $matcher->sensitivity->label(),
                        $transformLabel,
                        $matcher->isBaseline ? 'baseline' : 'file',
                    ];
                } else {
                    $totalDisabled++;

                    $rows[] = [
                        '—',
                        $groupDisplayName,
                        $matcher->key,
                        $matcher->name,
                        $matcher->sensitivity->label(),
                        '',
                        ($matcher->isBaseline ? 'baseline' : 'file').', disabled',
                    ];
                }
            }
        }

        $this->table(['', 'Group', 'Key', 'Name', 'Sensitivity', 'Transformation', 'Source'], $rows);
        $this->line('');

        $disabledSuffix = $totalDisabled > 0 ? sprintf(' (%d disabled)', $totalDisabled) : '';
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
