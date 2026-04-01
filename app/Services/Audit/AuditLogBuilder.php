<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Data\Audit\AuditColumnRecordData;
use App\Data\Audit\AuditRecordData;
use App\Data\Audit\AuditTableRecordData;
use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRunStatus;
use DateTimeImmutable;

class AuditLogBuilder
{
    public function __construct(private readonly AuditLogSigner $signer) {}

    public function build(
        CloningConfigData $config,
        RunResultData $result,
        string $targetConnection,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        string $yamlFileName,
    ): AuditRecordData {
        $auditTables = [];

        foreach ($result->tables as $tableResult) {
            $tableConfig = $config->getTable($tableResult->tableName);

            $transformedColumns = [];
            $keptColumnCount = 0;

            if ($tableConfig instanceof TableCloningConfigData) {
                foreach ($tableConfig->columns as $col) {
                    if ($col->strategy !== 'keep') {
                        $transformedColumns[] = new AuditColumnRecordData(
                            columnName: $col->columnName,
                            strategy: $col->strategy,
                        );
                    } else {
                        $keptColumnCount++;
                    }
                }
            }

            $skippedByFlag = $tableResult->status === TableRunStatus::SkippedByFlag;
            $existed = $tableResult->status !== TableRunStatus::NotFound;
            $rowStrategy = $tableConfig instanceof TableCloningConfigData ? $tableConfig->rows->strategy : 'full';
            $rowLimit = $tableConfig instanceof TableCloningConfigData ? $tableConfig->rows->limit : null;

            $auditTables[] = new AuditTableRecordData(
                tableName: $tableResult->tableName,
                existed: $existed,
                skippedByFlag: $skippedByFlag,
                rowStrategy: $rowStrategy,
                rowLimit: $rowLimit,
                rowsTransferred: $tableResult->rowsTransferred,
                rowsSkipped: $tableResult->rowsSkipped,
                durationSeconds: $tableResult->durationSeconds,
                transformedColumns: $transformedColumns,
                keptColumnCount: $keptColumnCount,
            );
        }

        $rawVersion = config('app.version', '1.0.0');
        $clonioVersion = is_string($rawVersion) ? $rawVersion : '1.0.0';

        // Create a placeholder record (without hash/sig) to sign
        $placeholder = new AuditRecordData(
            clonioVersion: $clonioVersion,
            sourceConnection: $config->connectionName,
            targetConnection: $targetConnection,
            yamlFileName: $yamlFileName,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            success: $result->success,
            options: $config->options,
            tables: $auditTables,
            totalRowsTransferred: $result->totalRows,
            totalRowsSkipped: $result->skippedRows,
            channels: [],
            contentHash: '',
            hmacSignature: '',
        );

        [, $contentHash, $hmacSignature] = $this->signer->sign($placeholder);

        return new AuditRecordData(
            clonioVersion: $clonioVersion,
            sourceConnection: $config->connectionName,
            targetConnection: $targetConnection,
            yamlFileName: $yamlFileName,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            success: $result->success,
            options: $config->options,
            tables: $auditTables,
            totalRowsTransferred: $result->totalRows,
            totalRowsSkipped: $result->skippedRows,
            channels: [],
            contentHash: $contentHash,
            hmacSignature: $hmacSignature,
        );
    }
}
