<?php

declare(strict_types=1);

namespace App\Services\SqlDump;

use App\Data\SqlDump\DumpResultData;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Compresses a `.sql` file (relative to the local disk root / cwd) into a ZIP
 * archive, optionally AES-256 encrypted, then removes the intermediate file.
 */
class DumpArchiver
{
    public function archive(string $sqlFileName, ?string $password): DumpResultData
    {
        throw_unless(class_exists(ZipArchive::class), RuntimeException::class, 'ZIP support unavailable — the PHP zip extension is not loaded.');

        $encrypted = $password !== null && $password !== '';

        throw_if($encrypted && ! defined('ZipArchive::EM_AES_256'), RuntimeException::class, 'AES-256 ZIP encryption unavailable — requires libzip >= 1.2.0.');

        $zipFileName = preg_replace('/\.sql$/', '.zip', $sqlFileName) ?? ($sqlFileName.'.zip');
        $sqlPath = Storage::disk('local')->path($sqlFileName);
        $zipPath = Storage::disk('local')->path($zipFileName);
        $entryName = basename($sqlFileName);

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException(sprintf('Cannot create ZIP archive: %s (code %s)', $zipFileName, (string) $opened));
        }

        if ($encrypted) {
            $zip->setPassword((string) $password);
        }

        if (! $zip->addFile($sqlPath, $entryName)) {
            $zip->close();

            throw new RuntimeException(sprintf('Cannot add %s to ZIP archive.', $entryName));
        }

        if ($encrypted) {
            $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
        }

        if (! $zip->close()) {
            throw new RuntimeException(sprintf('Failed to finalise ZIP archive: %s', $zipFileName));
        }

        Storage::disk('local')->delete($sqlFileName);

        $sha256 = hash_file('sha256', $zipPath);
        $zipBytes = filesize($zipPath) ?: 0;

        return new DumpResultData(
            zipPath: $zipPath,
            zipFileName: $zipFileName,
            sha256: $sha256 === false ? '' : $sha256,
            encrypted: $encrypted,
            zipBytes: $zipBytes,
        );
    }
}
