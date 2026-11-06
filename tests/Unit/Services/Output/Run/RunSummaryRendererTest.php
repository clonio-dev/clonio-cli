<?php

declare(strict_types=1);

use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\SqlDump\DumpResultData;
use App\Services\Output\Run\RunSummaryRenderer;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  list<TableRunResultData>  $tables
 */
function runSummary(array $tables = [], float $seconds = 1.0, ?DumpResultData $dump = null, bool $ci = false): string
{
    $output = new BufferedOutput;
    $result = new RunResultData(true, $tables, 7, 0, $seconds, null);

    new RunSummaryRenderer($output)->render($result, $dump, $ci);

    return $output->fetch();
}

function runSummaryTable(string $name, TableRunStatus $status, array $notes = []): TableRunResultData
{
    return new TableRunResultData($name, $status, 0, 0, 0.0, null, $notes);
}

function runSummaryDump(int $bytes, bool $encrypted = false): DumpResultData
{
    return new DumpResultData('/tmp/dump.zip', 'dump.zip', 'abc', $encrypted, $bytes);
}

it('prints the totals line', function (): void {
    expect(runSummary([
        runSummaryTable('users', TableRunStatus::Transferred),
        runSummaryTable('orders', TableRunStatus::Failed),
    ]))->toBe("\n  Tables: 1/2  Rows: 7  Duration: 1s\n\n");
});

it('lists tables not found in the source and tables skipped after a schema failure', function (): void {
    expect(runSummary([
        runSummaryTable('ghost', TableRunStatus::NotFound),
        runSummaryTable('broken', TableRunStatus::SkippedBySchemaFailure),
        runSummaryTable('phantom', TableRunStatus::NotFound),
    ], ci: true))->toBe(
        "\n  Warning: 2 tables not found in source (ghost, phantom)\n"
        ."\n  Error: 1 table skipped due to schema replication failure (broken)\n",
    );
});

it('prints the notes of each table', function (): void {
    expect(runSummary([
        runSummaryTable('users', TableRunStatus::Transferred, ['first', 'second']),
    ], ci: true))->toBe("  Note: users: first\n  Note: users: second\n");
});

it('formats durations', function (float $seconds, string $expected): void {
    expect(runSummary(seconds: $seconds))->toContain('Duration: '.$expected."\n");
})->with([
    [0.0, '0s'],
    [59.0, '59s'],
    [59.4, '59s'],
    [60.0, '1m 0s'],
    [75.4, '1m 15s'],
    [3600.0, '60m 0s'],
]);

it('prints the dump archive with its size', function (int $bytes, string $expected): void {
    expect(runSummary(dump: runSummaryDump($bytes), ci: true))->toBe('  Dump: dump.zip  ('.$expected.")\n");
})->with([
    [0, '0 B'],
    [1023, '1023 B'],
    [1024, '1.0 KB'],
    [1536, '1.5 KB'],
    [1048575, '1024.0 KB'],
    [1048576, '1.0 MB'],
    [1073741824, '1.0 GB'],
    [1099511627776, '1024.0 GB'],
]);

it('marks an encrypted dump and adds a blank line outside CI mode', function (): void {
    expect(runSummary(dump: runSummaryDump(10, encrypted: true)))
        ->toEndWith("  Dump: dump.zip  (10 B, AES-256)\n\n");
});
