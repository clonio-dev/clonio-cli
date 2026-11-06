<?php

declare(strict_types=1);

namespace App\Services\Output;

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunPhase;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Live nested progress for `cloning:run` on an interactive terminal: a scrolling
 * results log on top, the per-table bar in the middle and the overall table bar
 * pinned at the bottom.
 *
 * The bars own the terminal, so every other write must go through `$log`, or
 * Symfony's cursor math desyncs and re-prints the bars.
 */
final class CloningProgressDisplay
{
    private const string INDETERMINATE_FORMAT = '  %table%  [%bar%] %current%  %message%';

    private const string DETERMINATE_FORMAT = '  %table%  [%bar%] %current%/%approx%%max% (%percent:3s%%)  %message%';

    /** A stopped bar shows what was done, never a full bar: finish() fills it to max. */
    private const string STOPPED_TABLE_FORMAT = '  %table%  stopped after %message% rows';

    private const string STOPPED_OVERALL_FORMAT = "\n  <info>tables</info>  stopped at %message%";

    private readonly ProgressBar $tableBar;

    private readonly ProgressBar $overallBar;

    private bool $tableOpen = false;

    /**
     * @param  bool  $phaseDetail  Show the per-phase pace breakdown on the per-table bar (`-vv`).
     * @param  float  $minSecondsBetweenRedraws  Redraw throttle of the per-table bar, which updates per chunk.
     */
    public function __construct(
        public readonly ConsoleSectionOutput $log,
        ConsoleSectionOutput $tableSection,
        ConsoleSectionOutput $overallSection,
        private readonly bool $phaseDetail,
        float $minSecondsBetweenRedraws = 1 / 25,
    ) {
        $this->tableBar = new ProgressBar($tableSection, minSecondsBetweenRedraws: $minSecondsBetweenRedraws);
        // Unthrottled: it moves once per table, and a skipped redraw would leave
        // the count stale until the next table finishes.
        $this->overallBar = new ProgressBar($overallSection, minSecondsBetweenRedraws: 0); // @pest-mutate-ignore: DecrementInteger
        // Leading newline keeps a blank line between the per-table bar and the
        // overall bar (the section owns both lines, so it stays stable on redraw).
        $this->overallBar->setFormat("\n  <info>tables</info>  [%bar%] %current%/%max%");
    }

    /**
     * Sections created first render higher up: the log, then the per-table bar,
     * then the overall bar.
     */
    public static function forConsole(ConsoleOutput $output, bool $phaseDetail): self
    {
        return new self($output->section(), $output->section(), $output->section(), $phaseDetail);
    }

    public function start(int $totalTables): void
    {
        $this->overallBar->setMaxSteps($totalTables);
        $this->overallBar->start();
    }

    /**
     * (Re)initialise the per-table bar for a new table. The row total is not
     * known yet, so the bar starts as a plain row counter. The `%message%` slot
     * carries the one-shot activity first, then the per-chunk ETA and pace.
     */
    public function startTable(string $tableName): void
    {
        $this->tableBar->setFormat(self::INDETERMINATE_FORMAT);
        $this->tableBar->setMessage($tableName, 'table');
        $this->tableBar->setMessage('', 'message');
        $this->tableBar->start(0);

        $this->tableOpen = true;
    }

    /**
     * Update the per-table bar from the table's live stats. It is determinate
     * while the (possibly estimated) total holds, and falls back to a plain row
     * counter once an estimate turns out too low.
     */
    public function advanceTable(StatsTableTransferData $stats): void
    {
        if ($stats->status?->isOneShot() === true) {
            $this->tableBar->setMessage($stats->status->label().'…', 'message');
            $this->tableBar->display();

            return;
        }

        $this->tableBar->setMessage($this->formatProgress($stats), 'message');

        $max = $stats->hasKnownTotal ? $stats->totalRows : 0;
        $switchesFormat = $this->tableBar->getMaxSteps() !== $max;

        if ($switchesFormat) {
            $this->tableBar->setMaxSteps($max);
            $this->tableBar->setFormat($stats->hasKnownTotal ? self::DETERMINATE_FORMAT : self::INDETERMINATE_FORMAT);
        }

        $this->tableBar->setMessage($stats->totalRowsEstimated ? '~' : '', 'approx');
        $this->tableBar->setProgress($stats->rowsProcessed);

        // setProgress() throttles redraws; show a format switch right away.
        if ($switchesFormat) {
            $this->tableBar->display();
        }
    }

    /**
     * Close the per-table bar. A transferred table ends as a full bar with its
     * exact row count, as any estimate is moot by then. A failed table ends as
     * a plain "stopped after N rows", so it never shows as complete.
     */
    public function finishTable(bool $transferred, ?StatsTableTransferData $stats = null): void
    {
        $this->tableOpen = false;

        if (! $transferred) {
            $this->tableBar->setMessage(number_format($this->tableBar->getProgress()), 'message');
            $this->tableBar->setFormat(self::STOPPED_TABLE_FORMAT);
            $this->tableBar->finish();

            return;
        }

        // Without a max, finish() sizes the bar to the rows processed.
        $this->tableBar->setMaxSteps(null);
        $this->tableBar->setFormat(self::INDETERMINATE_FORMAT);
        $this->tableBar->setMessage($stats instanceof StatsTableTransferData ? $this->formatProgress($stats, withEta: false) : '', 'message');
        $this->tableBar->finish();
    }

    public function advanceOverall(): void
    {
        $this->overallBar->advance();
    }

    /**
     * Close the bars. A run that stopped early (--break-on-failure, an exception)
     * shows where it stopped rather than a full overall bar, and closes a table
     * that was still transferring as stopped.
     */
    public function finish(): void
    {
        if ($this->tableOpen) {
            $this->finishTable(transferred: false);
        }

        $done = $this->overallBar->getProgress();
        $planned = $this->overallBar->getMaxSteps();

        if ($done < $planned) {
            $this->overallBar->setMessage(sprintf('%d/%d', $done, $planned), 'message');
            $this->overallBar->setFormat(self::STOPPED_OVERALL_FORMAT);
        }

        $this->overallBar->finish();
    }

    /**
     * Per-phase timing table for one finished table (`-vvv`). Used with and
     * without live bars; writes nothing for a table that never ran a chunk.
     */
    public static function renderTimingSummary(OutputInterface $sink, StatsTableTransferData $timings): void
    {
        if ($timings->loopAggregate->count === 0) {
            return;
        }

        $rows = [];

        foreach (TableRunPhase::cases() as $phase) {
            $agg = $timings->aggregate($phase);
            if ($agg->count === 0) {
                continue;
            }

            if ($phase === TableRunPhase::Loop || $phase === TableRunPhase::Select) {
                $rows[] = new TableSeparator;
            }

            // count > 0 here, so min/max/average are always set.
            $rows[] = [
                $phase->value,
                $agg->count,
                self::formatSeconds($agg->min ?? 0.0), // @pest-mutate-ignore: IncrementFloat,DecrementFloat
                self::formatSeconds($agg->max ?? 0.0), // @pest-mutate-ignore: IncrementFloat,DecrementFloat
                self::formatSeconds($agg->averageSeconds ?? 0.0), // @pest-mutate-ignore: IncrementFloat,DecrementFloat
                self::formatSeconds($agg->sum),
                self::formatThroughput($agg->pacePerMillion),
            ];
        }

        $indent = str_repeat(' ', 6);
        $sink->writeln(sprintf('%s── timing summary ──', $indent));
        $buffer = new BufferedOutput($sink->getVerbosity(), $sink->isDecorated(), $sink->getFormatter());
        $table = new Table($buffer);
        $table->setHeaders(['phase', 'chunks', 'min', 'max', 'avg', 'total', 's/1M rows']);
        $table->setRows($rows);
        $table->render();

        foreach (explode("\n", rtrim($buffer->fetch(), "\n")) as $line) {
            $sink->writeln($indent.$line);
        }
    }

    /**
     * ETA plus the smoothed overall pace, expanded to the per-phase pace
     * breakdown when `$phaseDetail` is set. Smoothed paces keep the figures
     * steady between chunks.
     */
    private function formatProgress(StatsTableTransferData $stats, bool $withEta = true): string
    {
        $parts = $withEta && $stats->estimatedSecondsRemaining !== null
            ? ['ETA '.$this->formatEta($stats->estimatedSecondsRemaining)]
            : [];
        $overall = trim(self::formatThroughput($stats->loopAggregate->smoothedPacePerMillion));

        if (! $this->phaseDetail) {
            if ($overall !== '—') {
                $parts[] = $overall;
            }

            return implode(' · ', $parts);
        }

        $parts[] = 'all '.$overall;
        $parts[] = 'sel '.trim(self::formatThroughput($stats->selectAggregate->smoothedPacePerMillion));
        $parts[] = 'tr '.trim(self::formatThroughput($stats->transformAggregate->smoothedPacePerMillion));
        $parts[] = 'ins '.trim(self::formatThroughput($stats->insertAggregate->smoothedPacePerMillion));

        return implode(' · ', $parts);
    }

    private function formatEta(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return match (true) {
            $seconds < 60 => sprintf('%ds', $seconds),
            $seconds < 3600 => sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
    }

    private static function formatSeconds(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%6.1f ms', $seconds * 1000.0);
        }

        return sprintf('%5.1f s', $seconds);
    }

    private static function formatThroughput(?float $secondsPerMillion): string
    {
        if ($secondsPerMillion === null) {
            return '—';
        }

        if ($secondsPerMillion >= 1.0) {
            return sprintf('%5.1f s/M', $secondsPerMillion);
        }

        return sprintf('%5.1f ms/M', $secondsPerMillion * 1000.0);
    }
}
