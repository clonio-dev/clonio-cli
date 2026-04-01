<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Data\Audit\AuditRecordData;

class AuditLogSigner
{
    /**
     * Produce the canonical JSON, content hash, and HMAC signature.
     * Returns [canonicalJson, contentHash, hmacSignature]
     *
     * @return array{string, string, string}
     */
    public function sign(AuditRecordData $record): array
    {
        $signable = [
            'clonio_version' => $record->clonioVersion,
            'source_connection' => $record->sourceConnection,
            'target_connection' => $record->targetConnection,
            'yaml_file_name' => $record->yamlFileName,
            'started_at' => $record->startedAt->format('c'),
            'finished_at' => $record->finishedAt->format('c'),
            'success' => $record->success,
            'total_rows_transferred' => $record->totalRowsTransferred,
            'total_rows_skipped' => $record->totalRowsSkipped,
        ];

        ksort($signable);

        $canonicalJson = (string) json_encode($signable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $contentHash = hash('sha256', $canonicalJson);
        $appKey = config('app.key');
        $hmacSignature = hash_hmac('sha256', $contentHash, is_string($appKey) ? $appKey : '');

        return [$canonicalJson, $contentHash, $hmacSignature];
    }
}
