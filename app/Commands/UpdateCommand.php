<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Update\BinaryResolver;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class UpdateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'update
        {version?          : Target version (e.g. 1.2.0 or v1.2.0) — defaults to latest}
        {--no-verify-ssl : Skip SSL certificate verification (use behind corporate VPNs)}';

    /**
     * @var string
     */
    protected $description = 'Update Clonio to the latest release';

    private const string GITHUB_REPO = 'clonio-dev/clonio-cli';

    private const string RELEASES_API = 'https://api.github.com/repos/'.self::GITHUB_REPO.'/releases';

    private const string DOWNLOAD_BASE = 'https://github.com/'.self::GITHUB_REPO.'/releases/download';

    public function handle(BinaryResolver $resolver): int
    {
        $this->info('Checking for updates...');

        [$currentPath, $filename] = $resolver->resolve();

        $skipSsl = (bool) $this->option('no-verify-ssl');

        if ($skipSsl) {
            $this->warn('SSL verification disabled. Use only when behind a corporate VPN or proxy.');
        }

        $versionArg = $this->argument('version');
        $requestedVersion = is_string($versionArg) && $versionArg !== '' ? $versionArg : null;

        // Resolve target version
        $targetTag = $this->resolveTargetTag($requestedVersion, $skipSsl);

        if ($targetTag === null) {
            return Command::FAILURE;
        }

        $currentVersion = $this->normalizeVersion(app()->version());
        $targetVersion = $this->normalizeVersion($targetTag);

        if ($currentVersion === $targetVersion) {
            $this->info(sprintf('Already at version %s.', $currentVersion));

            return Command::SUCCESS;
        }

        $this->info(sprintf('Target version: %s (current: %s)', $targetVersion, $currentVersion));
        $this->info(sprintf('Downloading %s...', $filename));

        $tmpPath = $currentPath.'.update';

        try {
            $downloadRequest = Http::timeout(120)
                ->retry(3, 100)
                ->withUserAgent('clonio-cli')
                ->sink($tmpPath);

            if ($skipSsl) {
                $downloadRequest = $downloadRequest->withoutVerifying();
            }

            $download = $downloadRequest->get(self::DOWNLOAD_BASE.'/'.$targetTag.'/'.$filename);
        } catch (Throwable) {
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
            $this->error(sprintf('Could not replace binary at %s. Try running with sudo.', $currentPath));

            return Command::FAILURE;
        }

        $this->info(sprintf('Updated successfully to %s.', $targetVersion));

        return Command::SUCCESS;
    }

    private function resolveTargetTag(?string $requestedVersion, bool $skipSsl): ?string
    {
        if ($requestedVersion !== null) {
            // Ensure the tag starts with 'v'
            $tag = str_starts_with($requestedVersion, 'v') ? $requestedVersion : 'v'.$requestedVersion;

            // Verify the release exists
            try {
                $request = Http::timeout(10)
                    ->retry(3, 100)
                    ->withUserAgent('clonio-cli')
                    ->accept('application/vnd.github.v3+json');

                if ($skipSsl) {
                    $request = $request->withoutVerifying();
                }

                $response = $request->get(self::RELEASES_API.'/tags/'.$tag);
            } catch (Throwable) {
                $this->error('Could not reach GitHub. Please check your internet connection.');

                return null;
            }

            if ($response->failed()) {
                $this->error(sprintf('Version %s not found on GitHub.', $tag));

                return null;
            }

            $tagName = $response->json('tag_name');

            return is_string($tagName) ? $tagName : $tag;
        }

        // No version specified — fetch latest
        try {
            $request = Http::timeout(10)
                ->retry(3, 100)
                ->withUserAgent('clonio-cli')
                ->accept('application/vnd.github.v3+json');

            if ($skipSsl) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get(self::RELEASES_API.'/latest');
        } catch (Throwable) {
            $this->error('Could not reach GitHub. Please check your internet connection.');

            return null;
        }

        if ($response->failed()) {
            $this->error('Could not reach GitHub. Please check your internet connection.');

            return null;
        }

        $latestTag = $response->json('tag_name');

        if (! is_string($latestTag)) {
            $this->error('Unexpected response from GitHub API.');

            return null;
        }

        return $latestTag;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'v');
    }
}
