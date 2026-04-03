<?php

declare(strict_types=1);

namespace App\Commands\Cloning\Matchers;

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'matchers:init
        {--force  : Overwrite an existing pii-matchers.yaml without confirmation}
        {--path=  : Output path (default: pii-matchers.yaml in cwd)}';

    /**
     * @var string
     */
    protected $description = 'Write the baseline PII matcher configuration to pii-matchers.yaml';

    public function handle(PiiMatcherBaselineProvider $baselineProvider, PiiMatcherYamlWriter $writer): int
    {
        $pathOption = $this->option('path');
        $outputPath = is_string($pathOption) && $pathOption !== '' ? $pathOption : 'pii-matchers.yaml';

        if (Storage::disk('local')->exists($outputPath) && ! $this->option('force')) {
            $confirmed = $this->confirm(sprintf('  %s already exists. Overwrite?', $outputPath), false);

            if (! $confirmed) {
                $this->line('  Cancelled.');

                return ExitCode::Success->value;
            }
        }

        $this->line('');
        $this->line(sprintf('  Writing PII matcher baseline to %s ...', $outputPath));
        $this->line('');

        $groups = $baselineProvider->getGroups();

        $writer->write($groups, $outputPath);

        $this->line('  Groups written:');

        $totalMatchers = 0;
        $totalGroups = count($groups);

        foreach ($groups as $group) {
            $count = count($group->matchers);
            $totalMatchers += $count;
            $this->line(sprintf('    %-20s  %d matchers', $group->key, $count));
        }

        $this->line('');
        $this->line(sprintf('  Total: %d matchers across %d groups', $totalMatchers, $totalGroups));
        $this->line('');
        $this->line(sprintf('  Edit %s to customise detection, then commit it to your repository.', $outputPath));
        $this->line('  Run matchers:update after upgrading Clonio to add new baseline matchers.');
        $this->line('');
        $this->line(sprintf('  Tip: %s contains no credentials and is safe to commit.', $outputPath));
        $this->line('       Make sure clonio.json is in your .gitignore.');
        $this->line('');

        return ExitCode::Success->value;
    }
}
