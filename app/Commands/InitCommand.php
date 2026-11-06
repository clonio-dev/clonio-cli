<?php

declare(strict_types=1);

namespace App\Commands;

use App\Enums\ExitCode;
use App\Services\Art\AsciiArtService;
use App\Services\Config\ConfigService;
use App\Services\Config\DotenvFile;
use App\Services\Secrets\EncryptionKey;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class InitCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'init
        {--force : Generate a new CLONIO_KEY even if a key already exists}';

    /**
     * @var string
     */
    protected $description = 'Bootstrap Clonio in the current directory by ensuring an encryption key (CLONIO_KEY) is available';

    public function handle(AsciiArtService $asciiArtService, ConfigService $config, DotenvFile $dotenv): int
    {
        $asciiArtService->clonioLogoWithClaim($this->output, '  ');
        $this->line('  Checking for CLONIO_KEY ...');

        $found = EncryptionKey::locate($dotenv);

        if ($found !== null && ! $this->option('force')) {
            [$name, $fromDotenv] = $found;

            if (! $fromDotenv) {
                $this->info(sprintf('  ✓  %s found in environment — no .env file needed.', $name));
            } else {
                $this->info(sprintf('  ✓  %s found in .env — ready.', $name));
            }

            if ($name === EncryptionKey::LEGACY_NAME) {
                $this->line('  ℹ  Clonio uses APP_KEY because CLONIO_KEY is not set. Run `clonio key:rotate`');
                $this->line('     to move to a dedicated CLONIO_KEY.');
            }

            $this->ensureGitignoreEntries();
            $this->ensureDefaultAuditChannels($config);

            return ExitCode::Success->value;
        }

        if ($found !== null) {
            $this->warn(sprintf('  %s already exists. --force was passed — regenerating.', $found[0]));
            $this->line('');
            $this->warn('  ⚠  Warning: a new key makes all existing encrypted secrets in');
            $this->warn('     clonio.json unreadable. To keep them, run `clonio key:rotate`');
            $this->warn('     instead.');
            $this->line(''); // @pest-mutate-ignore: RemoveMethodCall

            if (! $this->confirm('  Regenerate key?', false)) {
                $this->line('  Cancelled.');
                $this->line('');

                $this->ensureGitignoreEntries();
                $this->ensureDefaultAuditChannels($config);

                return ExitCode::Success->value;
            }

            $this->line('');
        }

        $this->line('  Writing a new CLONIO_KEY to .env ...');
        $this->line('');

        try {
            $dotenv->set(EncryptionKey::NAME, 'base64:'.base64_encode(random_bytes(32)));
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());
            $this->line('');

            return ExitCode::IoError->value;
        }

        $cwd = getcwd();

        $this->info(sprintf('  ✓  Wrote CLONIO_KEY to .env in %s', is_string($cwd) ? $cwd : '.'));
        $this->line('');

        $this->ensureGitignoreEntries();
        $this->ensureDefaultAuditChannels($config);

        return ExitCode::Success->value;
    }

    private function ensureDefaultAuditChannels(ConfigService $config): void
    {
        $defaults = [
            'local' => [
                'type' => 'local',
                'path' => './',
            ],
            'stdout' => ['type' => 'stdout'],
            'stderr' => ['type' => 'stderr'],
        ];

        $created = [];

        foreach ($defaults as $name => $channelConfig) {
            if (! $config->hasAuditChannel($name)) {
                $config->setAuditChannel($name, $channelConfig);
                $created[] = $name;
            }
        }

        if ($config->getAuditUse() === []) {
            $config->setAuditUse(['local']);
        }

        if ($created !== []) {
            $this->info(sprintf(
                '  ✓  Created clonio.json with default audit channels: %s',
                implode(', ', $created),
            ));
        } else {
            $this->info('  ✓  Audit channels in clonio.json — ready.');
        }

        $this->line('');
    }

    private function ensureGitignoreEntries(): void
    {
        $entries = ['.env', 'clonio.json'];

        if (! Storage::exists('.gitignore')) {
            $this->line('  ℹ  No .gitignore found. Add .env and clonio.json to avoid committing secrets.');
            $this->line('');

            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $content = Storage::get('.gitignore');

        if (! is_string($content)) {
            return;
        }

        foreach ($entries as $entry) {
            $escaped = preg_quote($entry, '/');

            if (preg_match('/^\\/?'.$escaped.'\\s*$/m', $content)) {
                continue;
            }

            $content = rtrim($content)."\n".$entry."\n";
            Storage::put('.gitignore', $content);
            $this->info(sprintf('  ✓  Added to .gitignore: %s', $entry));
        }
    }
}
