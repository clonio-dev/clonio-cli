<?php

declare(strict_types=1);

namespace App\Commands\Cloning\Matchers;

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherUpdateService;
use App\Services\Pii\PiiMatcherYamlReader;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cloning:matchers:update
        {--dry-run  : Show what would be added without writing anything}
        {--path=    : Path to the pii-matchers.yaml file (default: pii-matchers.yaml in cwd)}';

    /**
     * @var string
     */
    protected $description = 'Add new baseline matchers to an existing pii-matchers.yaml';

    public function handle(
        PiiMatcherBaselineProvider $baselineProvider,
        PiiMatcherYamlReader $reader,
        PiiMatcherYamlWriter $writer,
        PiiMatcherUpdateService $updateService,
    ): int {
        $pathOption = $this->option('path');
        $filePath = is_string($pathOption) && $pathOption !== '' ? $pathOption : 'pii-matchers.yaml';

        $this->line('');
        $this->line(sprintf('  Checking %s against binary baseline ...', $filePath));
        $this->line('');

        if (! Storage::disk('local')->exists($filePath)) {
            $this->error(sprintf('  File not found: %s', $filePath));
            $this->line(sprintf('  Run `cloning:matchers:init` to create %s first.', $filePath));
            $this->line('');

            return ExitCode::IoError->value;
        }

        $content = Storage::disk('local')->get($filePath);

        if (! is_string($content)) {
            $this->error(sprintf('  Cannot read %s.', $filePath));

            return ExitCode::IoError->value;
        }

        try {
            $fileGroups = $reader->read($content);
        } catch (RuntimeException $runtimeException) {
            $this->error(sprintf('  Parse error: %s', $runtimeException->getMessage()));

            return ExitCode::ValidationError->value;
        }

        $baselineGroups = $baselineProvider->getGroups();
        $diff = $updateService->computeDiff($fileGroups, $baselineGroups);

        if (! $diff->hasChanges && $diff->orphans === []) {
            $this->line(sprintf('  %s is up to date. No changes needed.', $filePath));
            $this->line('');

            return ExitCode::Success->value;
        }

        $isDryRun = (bool) $this->option('dry-run');

        if ($diff->additions !== []) {
            $this->line('  New matchers added:');

            foreach ($diff->additions as $addition) {
                $groupLabel = $addition->groupIsNew ? sprintf('%s  (new group)', $addition->groupKey) : $addition->groupKey;
                $this->line(sprintf('    %-20s  →  %-30s  "%s"', $groupLabel, $addition->matcherKey, $addition->matcherName));
            }

            $this->line('');
        }

        if ($diff->orphans !== []) {
            $this->line('  Orphaned matchers (in file but no longer in baseline):');

            foreach ($diff->orphans as $orphan) {
                $this->line(sprintf('    %-20s  →  %-30s  "%s"', $orphan->groupKey, $orphan->matcherKey, $orphan->matcherName));
            }

            $this->line('  ⚠  These are no longer recognised by the current Clonio baseline.');
            $this->line('     They may be custom entries or from an older version.');
            $this->line('     Remove them manually if they are no longer needed.');
            $this->line('');
        }

        if ($isDryRun) {
            $this->line('  Dry run — no changes written.');
            $this->line('');

            return ExitCode::Success->value;
        }

        $updatedGroups = $updateService->applyDiff($fileGroups, $diff, $baselineGroups);
        $writer->write($updatedGroups, $filePath);

        $addedCount = count($diff->additions);
        $orphanCount = count($diff->orphans);

        $summary = sprintf('  %d matcher%s added.', $addedCount, $addedCount === 1 ? '' : 's');

        if ($orphanCount > 0) {
            $summary .= sprintf(' %d orphaned matcher%s reported.', $orphanCount, $orphanCount === 1 ? '' : 's');
        }

        $summary .= ' Your existing customisations were not changed.';

        $this->line($summary);
        $this->line('');

        return ExitCode::Success->value;
    }
}
