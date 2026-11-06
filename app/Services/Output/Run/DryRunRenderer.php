<?php

declare(strict_types=1);

namespace App\Services\Output\Run;

use App\Data\Cloning\DryRunResultData;
use App\Data\Cloning\DryRunTableData;
use App\Data\Schema\SchemaDiffData;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the `cloning:run --dry-run` report: the schema diff against the
 * target (when it could be inspected) and one row per configured table with
 * its estimated rows, row strategy and transformed columns.
 */
final readonly class DryRunRenderer
{
    private const int TABLE_WIDTH = 24;

    private const int ROWS_WIDTH = 14;

    private const int STRATEGY_WIDTH = 10;

    public function __construct(private OutputInterface $output) {}

    public function render(DryRunResultData $result, string $sourceName, ?SchemaDiffData $schemaDiff = null): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('  Dry-run: %s', $sourceName));
        $this->output->writeln('');

        if ($schemaDiff instanceof SchemaDiffData) {
            $this->renderSchemaDiff($schemaDiff);
        }

        $separator = '  '.str_repeat('─', self::TABLE_WIDTH + self::ROWS_WIDTH + self::STRATEGY_WIDTH + 20);

        $this->output->writeln($this->row('Table', 'Rows (est.)', 'Strategy', 'Transformations'));
        $this->output->writeln($separator);

        foreach ($result->tables as $table) {
            $rowsLabel = $table->exists
                ? ($table->estimatedRows !== null ? ($table->estimated ? '~' : '').number_format($table->estimatedRows) : '?')
                : 'NOT FOUND';

            $strategyLabel = $table->exists
                ? (in_array($table->rowStrategy, ['first', 'last'], true) ? $table->rowStrategy.' '.number_format((int) $table->rowLimit) : $table->rowStrategy) // @pest-mutate-ignore: RemoveIntegerCast
                : ('—');

            $transformLabel = $table->transformedColumns !== []
                ? implode(', ', $table->transformedColumns)
                : '—';

            $this->output->writeln($this->row($table->tableName, $rowsLabel, $strategyLabel, $transformLabel));
        }

        $this->output->writeln($separator);

        $notFoundMsg = $result->notFoundCount > 0
            ? sprintf(', %d not found, will be skipped', $result->notFoundCount)
            : '';

        $this->output->writeln(sprintf(
            '  %-'.self::TABLE_WIDTH.'s %-'.self::ROWS_WIDTH.'s', // @pest-mutate-ignore: ConcatRemoveRight
            'Total',
            (array_any($result->tables, static fn (DryRunTableData $t): bool => $t->estimated) ? '~' : '').number_format($result->totalEstimatedRows).' rows across '.count($result->tables).' tables'.$notFoundMsg,
        ));

        $this->output->writeln('');
        $this->output->writeln('  No data will be transferred. Run without --dry-run to execute.');
        $this->output->writeln('');
    }

    private function row(string $table, string $rows, string $strategy, string $transformations): string
    {
        return sprintf(
            '  %-'.self::TABLE_WIDTH.'s %-'.self::ROWS_WIDTH.'s %-'.self::STRATEGY_WIDTH.'s %s',
            $table,
            $rows,
            $strategy,
            $transformations,
        );
    }

    private function renderSchemaDiff(SchemaDiffData $diff): void
    {
        if ($diff->isIdentical()) {
            $this->output->writeln('  Schema diff: target matches source');
            $this->output->writeln('');

            return;
        }

        $this->output->writeln('  Schema diff: source → target');
        $this->output->writeln('');

        if ($diff->missingTables !== []) {
            $this->output->writeln(sprintf('  Missing tables (%d):   %s', count($diff->missingTables), implode(', ', $diff->missingTables)));
        }

        if ($diff->extraTables !== []) {
            $this->output->writeln(sprintf('  Extra tables (%d):     %s', count($diff->extraTables), implode(', ', $diff->extraTables)));
        }

        foreach ($diff->modifiedTables as $tableDiff) {
            $parts = [];

            if ($tableDiff->missingColumns !== []) {
                $parts[] = sprintf('+%d col%s (%s)', count($tableDiff->missingColumns), count($tableDiff->missingColumns) === 1 ? '' : 's', implode(', ', $tableDiff->missingColumns));
            }

            if ($tableDiff->extraColumns !== []) {
                $parts[] = sprintf('-%d col%s (%s)', count($tableDiff->extraColumns), count($tableDiff->extraColumns) === 1 ? '' : 's', implode(', ', $tableDiff->extraColumns));
            }

            foreach ($tableDiff->modifiedColumns as $colDiff) {
                $parts[] = sprintf('~%s (%s → %s%s)', $colDiff->name, $colDiff->sourceType, $colDiff->targetType, $colDiff->sourceNullable !== $colDiff->targetNullable ? ', nullable changed' : '');
            }

            $this->output->writeln(sprintf('  Modified table:        %s: %s', $tableDiff->tableName, implode(', ', $parts)));
        }

        $this->output->writeln('');
    }
}
