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
use App\Data\ConnectionData;
use App\Data\SqlDump\DumpResultData;
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
        ?ConnectionData $sourceConnectionData = null,
        ?ConnectionData $targetConnectionData = null,
        ?string $dumpDialect = null,
        ?DumpResultData $dumpResult = null,
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

        $sourceDetails = $this->projectConnectionDetails($config->connectionName, $sourceConnectionData);
        $targetDetails = $this->projectConnectionDetails($targetConnection, $targetConnectionData);

        $dumpFile = $dumpResult instanceof DumpResultData ? $dumpResult->zipPath : null;
        $dumpSha256 = $dumpResult instanceof DumpResultData ? $dumpResult->sha256 : null;
        $dumpEncrypted = $dumpResult instanceof DumpResultData ? $dumpResult->encrypted : null;

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
            sourceConnectionDetails: $sourceDetails,
            targetConnectionDetails: $targetDetails,
            dumpDialect: $dumpDialect,
            dumpFile: $dumpFile,
            dumpSha256: $dumpSha256,
            dumpEncrypted: $dumpEncrypted,
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
            sourceConnectionDetails: $sourceDetails,
            targetConnectionDetails: $targetDetails,
            dumpDialect: $dumpDialect,
            dumpFile: $dumpFile,
            dumpSha256: $dumpSha256,
            dumpEncrypted: $dumpEncrypted,
        );
    }

    /**
     * Project a ConnectionData into a password-free details array suitable for the audit record.
     * Returns a stub array with only the connection name when no ConnectionData is provided.
     *
     * @return array{name: string, type: string, host: ?string, port: ?int, database: ?string, schema: ?string, username: ?string}
     */
    private function projectConnectionDetails(string $name, ?ConnectionData $connection): array
    {
        if (! $connection instanceof ConnectionData) {
            return [
                'name' => $name,
                'type' => '',
                'host' => null,
                'port' => null,
                'database' => null,
                'schema' => null,
                'username' => null,
            ];
        }

        return [
            'name' => $connection->name,
            'type' => $connection->type->value,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'schema' => $connection->schema,
            'username' => $connection->username,
        ];
    }
}
