<?php

declare(strict_types=1);

namespace App\Commands;

use App\Enums\ExitCode;
use App\Services\Art\AsciiArtService;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class InitCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'init
        {--force : Regenerate APP_KEY even if one already exists}';

    /**
     * @var string
     */
    protected $description = 'Bootstrap Clonio in the current directory by ensuring APP_KEY is available';

    public function handle(AsciiArtService $asciiArtService): int
    {
        $asciiArtService->clonioLogoWithShadow($this->output, '  ');
        $this->line('  Checking for APP_KEY ...');

        $keyInEnv = $this->keyInSystemEnv();
        $keyInDotenv = $this->keyInDotenvFile();

        if (($keyInEnv || $keyInDotenv) && ! $this->option('force')) {
            if ($keyInEnv) {
                $this->info('  ✓  APP_KEY found in environment — no .env file needed.');
            } else {
                $this->info('  ✓  APP_KEY found in .env — ready.');
            }

            return ExitCode::Success->value;
        }

        if (($keyInEnv || $keyInDotenv) && $this->option('force')) {
            $this->warn('  APP_KEY already exists. --force was passed — regenerating.');
            $this->line('');
            $this->warn('  ⚠  Warning: regenerating the key will make all existing encrypted');
            $this->warn('     passwords in clonio.json unreadable. You will need to re-enter');
            $this->warn('     them via `connection:update`.');
            $this->line('');

            if (! $this->confirm('  Regenerate key?', false)) {
                $this->line('  Cancelled.');
                $this->line('');

                return ExitCode::Success->value;
            }

            $this->line('');
        }

        $this->line('  No APP_KEY found. Generating .env with a new key ...');
        $this->line('');

        $newKey = 'base64:'.base64_encode(random_bytes(32));

        try {
            $this->writeDotenv($newKey, $keyInDotenv);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());
            $this->line('');

            return ExitCode::IoError->value;
        }

        $cwd = getcwd();
        $cwdDisplay = is_string($cwd) ? $cwd : '.';

        $this->info(sprintf('  ✓  Created .env with APP_KEY in %s', $cwdDisplay));
        $this->line('');

        $this->showGitignoreHint();

        return ExitCode::Success->value;
    }

    private function keyInSystemEnv(): bool
    {
        $value = getenv('APP_KEY');

        return is_string($value) && $value !== '';
    }

    private function keyInDotenvFile(): bool
    {
        if (! Storage::exists('.env')) {
            return false;
        }

        $content = Storage::get('.env');

        if (! is_string($content)) {
            return false;
        }

        return (bool) preg_match('/^APP_KEY\s*=\s*.+$/m', $content);
    }

    private function writeDotenv(string $newKey, bool $fileHasKey): void
    {
        if (! Storage::exists('.env')) {
            $result = Storage::put('.env', 'APP_KEY='.$newKey."\n");

            if (! $result) {
                $cwd = getcwd();
                $path = is_string($cwd) ? $cwd.'/.env' : '.env';

                throw new RuntimeException(sprintf('Cannot write %s: permission denied', $path));
            }

            chmod(Storage::path('.env'), 0600);

            return;
        }

        $content = Storage::get('.env');

        if (! is_string($content)) {
            throw new RuntimeException(sprintf('Cannot read %s: permission denied', Storage::path('.env')));
        }

        if ($fileHasKey) {
            $updated = preg_replace('/^APP_KEY\s*=.*$/m', 'APP_KEY='.$newKey, $content);
            $updated = is_string($updated) ? $updated : $content;
        } else {
            $updated = rtrim($content)."\nAPP_KEY=".$newKey."\n";
        }

        $result = Storage::put('.env', $updated);

        if (! $result) {
            throw new RuntimeException(sprintf('Cannot write %s: permission denied', Storage::path('.env')));
        }

        chmod(Storage::path('.env'), 0600);
    }

    private function showGitignoreHint(): void
    {
        if (! Storage::exists('.gitignore')) {
            return;
        }

        $content = Storage::get('.gitignore');

        if (! is_string($content)) {
            return;
        }

        if (preg_match('/^\.env$/m', $content)) {
            return;
        }

        $this->line('  ℹ  Remember to add .env to your .gitignore to avoid committing your APP_KEY.');
        $this->line('');
    }
}
