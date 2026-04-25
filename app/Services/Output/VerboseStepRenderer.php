<?php

declare(strict_types=1);

namespace App\Services\Output;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class VerboseStepRenderer
{
    private const int TARGET_COLUMN = 80;

    private readonly bool $enabled;

    private ?string $currentLabel = null;

    public function __construct(
        private readonly OutputInterface $output,
        bool $ci,
    ) {
        $this->enabled = ! $ci && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * Start a step, run the closure, and finalize with ✓ on return or ✗ on Throwable (then re-throw).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(string $label, Closure $work): mixed
    {
        $this->start($label);

        try {
            $result = $work();
        } catch (Throwable $throwable) {
            $this->fail();

            throw $throwable;
        }

        $this->success();

        return $result;
    }

    public function start(string $label): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->currentLabel = $label;
        $this->output->writeln('  '.$label.' ...');
    }

    public function success(?string $suffix = null): void
    {
        $this->finalize('<info>✓</info>', $suffix);
    }

    public function fail(?string $suffix = null): void
    {
        $this->finalize('<error>✗</error>', $suffix);
    }

    public function note(string $line): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->output->writeln($line);
    }

    private function finalize(string $symbol, ?string $suffix): void
    {
        if (! $this->enabled || $this->currentLabel === null) {
            return;
        }

        $label = $this->currentLabel;
        $suffixText = $suffix !== null && $suffix !== '' ? ' '.$suffix : '';
        $compose = '  '.$label.$suffixText;

        if ($this->output->isDecorated()) {
            $padLength = max(self::TARGET_COLUMN - mb_strlen($compose) - 2, 1);
            $padding = ' '.str_repeat('.', max($padLength - 1, 1));
            $this->output->write("\x1b[1A\r\x1b[K");
            $this->output->writeln(sprintf('%s%s  %s', $compose, $padding, $symbol));
        } else {
            $this->output->writeln(sprintf('%s %s', $compose, $symbol));
        }

        $this->currentLabel = null;
    }
}
