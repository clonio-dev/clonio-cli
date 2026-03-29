<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Update\BinaryResolver;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class UpdateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'update';

    /**
     * @var string
     */
    protected $description = 'Update Clonio to the latest release';

    private const string GITHUB_REPO = 'clonio-dev/clonio-cli';

    private const string RELEASES_API = 'https://api.github.com/repos/'.self::GITHUB_REPO.'/releases/latest';

    private const string DOWNLOAD_BASE = 'https://github.com/'.self::GITHUB_REPO.'/releases/latest/download';

    public function handle(BinaryResolver $resolver): int
    {
        $this->info('Checking for updates...');

        [$currentPath, $filename] = $resolver->resolve();

        try {
            $response = Http::timeout(10)
                ->retry(3, 100)
                ->withUserAgent('clonio-cli')
                ->accept('application/vnd.github.v3+json')
                ->get(self::RELEASES_API);
        } catch (RequestException|Throwable) {
            $this->error('Could not reach GitHub. Please check your internet connection.');

            return Command::FAILURE;
        }

        if ($response->failed()) {
            $this->error('Could not reach GitHub. Please check your internet connection.');

            return Command::FAILURE;
        }

        $latestTag = $response->json('tag_name');

        if (! is_string($latestTag)) {
            $this->error('Unexpected response from GitHub API.');

            return Command::FAILURE;
        }

        $currentVersion = $this->normalizeVersion(app()->version());
        $latestVersion = $this->normalizeVersion($latestTag);

        if ($currentVersion === $latestVersion) {
            $this->info("Already up to date ({$currentVersion}).");

            return Command::SUCCESS;
        }

        $this->info("New version available: {$latestVersion} (current: {$currentVersion})");
        $this->info("Downloading {$filename}...");

        $tmpPath = $currentPath.'.update';

        try {
            $download = Http::timeout(120)
                ->retry(3, 100)
                ->withUserAgent('clonio-cli')
                ->sink($tmpPath)
                ->get(self::DOWNLOAD_BASE.'/'.$filename);
        } catch (RequestException|Throwable) {
            @unlink($tmpPath);
            $this->error('Download failed.');

            return Command::FAILURE;
        }

        if ($download->failed()) {
            @unlink($tmpPath);
            $this->error('Download failed.');

            return Command::FAILURE;
        }

        if (! file_exists($tmpPath) || filesize($tmpPath) === 0) {
            @unlink($tmpPath);
            $this->error('Download failed.');

            return Command::FAILURE;
        }

        chmod($tmpPath, 0755);

        if (! rename($tmpPath, $currentPath)) {
            @unlink($tmpPath);
            $this->error("Could not replace binary at {$currentPath}. Try running with sudo.");

            return Command::FAILURE;
        }

        $this->info("Updated successfully to {$latestVersion}.");

        return Command::SUCCESS;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'v');
    }
}
