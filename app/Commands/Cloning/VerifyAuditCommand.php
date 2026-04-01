<?php

declare(strict_types=1);

namespace App\Commands\Cloning;

use App\Enums\ExitCode;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class VerifyAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cloning:verify-audit
        {file   : Path to the .html audit log file}
        {--sig= : Path to the .sig file (default: <file>.sig in same directory)}';

    /**
     * @var string
     */
    protected $description = 'Verify the integrity of a Clonio audit log file';

    public function handle(): int
    {
        $filePath = (string) $this->argument('file');

        // Locate sig file
        $sigOption = $this->option('sig');
        $sigPath = is_string($sigOption) && $sigOption !== '' ? $sigOption : $this->resolveSigPath($filePath);

        // Read HTML file
        if (! file_exists($filePath)) {
            $this->error(sprintf('Audit file not found: %s', $filePath));

            return ExitCode::IoError->value;
        }

        // Read sig file
        if (! file_exists($sigPath)) {
            $this->error(sprintf('Signature file not found: %s', $sigPath));

            return ExitCode::IoError->value;
        }

        try {
            $htmlContent = (string) file_get_contents($filePath);
            $sigContent = trim((string) file_get_contents($sigPath));
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to read files: %s', $throwable->getMessage()));

            return ExitCode::IoError->value;
        }

        // Extract JSON from HTML
        $canonicalJson = $this->extractAuditJson($htmlContent);

        if ($canonicalJson === null) {
            $this->error('Could not find audit data in the HTML file. The file may be corrupted.');

            return ExitCode::GeneralError->value;
        }

        // Recompute hash
        $recomputedHash = hash('sha256', $canonicalJson);
        $appKey = config('app.key');
        $recomputedHmac = hash_hmac('sha256', $recomputedHash, is_string($appKey) ? $appKey : '');
        $expectedSig = str_starts_with($sigContent, 'sha256:') ? substr($sigContent, 7) : $sigContent;

        $filename = basename($filePath);

        if (hash_equals($expectedSig, $recomputedHmac)) {
            $this->line('');
            $this->line('  <info>✓</info>  Audit log verified — signature matches');
            $this->line(sprintf('     File:      %s', $filename));
            $this->line(sprintf('     SHA-256:   %s', $recomputedHash));
            $this->line('');

            return ExitCode::Success->value;
        }

        $this->line('');
        $this->line('  <error>✗</error>  Audit log verification FAILED — document may have been tampered with');
        $this->line(sprintf('     File:      %s', $filename));
        $this->line(sprintf('     Expected:  %s  (from .sig)', $expectedSig));
        $this->line(sprintf('     Got:       %s  (recomputed)', $recomputedHmac));
        $this->line('');

        return ExitCode::GeneralError->value;
    }

    private function resolveSigPath(string $htmlPath): string
    {
        $dir = dirname($htmlPath);
        $base = pathinfo($htmlPath, PATHINFO_FILENAME);

        return $dir.'/'.$base.'.sig';
    }

    private function extractAuditJson(string $html): ?string
    {
        // Find <script type="application/json" id="audit-data">...</script>
        $pattern = '/<script\s[^>]*type=["\']application\/json["\'][^>]*id=["\']audit-data["\'][^>]*>(.*?)<\/script>/si';

        if (! preg_match($pattern, $html, $matches)) {
            // Try alternate attribute order
            $pattern2 = '/<script\s[^>]*id=["\']audit-data["\'][^>]*type=["\']application\/json["\'][^>]*>(.*?)<\/script>/si';

            if (! preg_match($pattern2, $html, $matches)) {
                return null;
            }
        }

        $raw = $matches[1];

        // Unescape HTML entities
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
