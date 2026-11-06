<?php

declare(strict_types=1);

namespace App\Services\Output\Run;

use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\SqlDump\DumpResultData;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the end of a `cloning:run`: tables not found or skipped after a
 * schema failure, per-table notes, the totals line and the dump archive.
 * In CI mode the totals line and the blank lines around it are left out.
 */
final readonly class RunSummaryRenderer
{
    public function __construct(private OutputInterface $output) {}

    public function render(RunResultData $result, ?DumpResultData $dumpResult, bool $ci): void
    {
        $this->renderTableList(
            $this->tablesWithStatus($result, TableRunStatus::NotFound),
            '  <comment>Warning: %d table%s not found in source (%s)</comment>',
        );

        $this->renderTableList(
            $this->tablesWithStatus($result, TableRunStatus::SkippedBySchemaFailure),
            '  <error>Error: %d table%s skipped due to schema replication failure (%s)</error>',
        );

        foreach ($result->tables as $tableResult) {
            foreach ($tableResult->notes as $note) {
                $this->output->writeln(sprintf('  <comment>Note: %s: %s</comment>', $tableResult->tableName, $note));
            }
        }

        if (! $ci) {
            $transferred = count($this->tablesWithStatus($result, TableRunStatus::Transferred));

            $this->output->writeln('');
            $this->output->writeln(sprintf(
                '  Tables: %d/%d  Rows: %d  Duration: %s',
                $transferred,
                count($result->tables),
                $result->totalRows,
                $this->formatDuration($result->durationSeconds),
            ));
            $this->output->writeln('');
        }

        if ($dumpResult instanceof DumpResultData) {
            $this->output->writeln(sprintf(
                '  Dump: %s  (%s%s)',
                $dumpResult->zipFileName,
                $this->formatBytes($dumpResult->zipBytes),
                $dumpResult->encrypted ? ', AES-256' : '',
            ));

            if (! $ci) {
                $this->output->writeln('');
            }
        }
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.0fs', $seconds);
        }

        $minutes = (int) ($seconds / 60); // @pest-mutate-ignore: RemoveIntegerCast
        $remaining = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $remaining);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }

    /**
     * @param  list<string>  $tables
     */
    private function renderTableList(array $tables, string $format): void
    {
        if ($tables === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf($format, count($tables), count($tables) === 1 ? '' : 's', implode(', ', $tables)));
    }

    /**
     * @return list<string>
     */
    private function tablesWithStatus(RunResultData $result, TableRunStatus $status): array
    {
        return array_values(array_map( // @pest-mutate-ignore: UnwrapArrayValues
            static fn (TableRunResultData $t): string => $t->tableName,
            array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === $status),
        ));
    }
}
