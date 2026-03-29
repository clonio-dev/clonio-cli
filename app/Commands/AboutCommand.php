<?php

namespace App\Commands;

use App\Services\Art\AsciiArtService;
use LaravelZero\Framework\Commands\Command;

class AboutCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'about';

    /**
     * @var string
     */
    protected $description = 'Shows information about Clonio';

    /**
     * Execute the console command.
     */
    public function handle(AsciiArtService $asciiArtService): int
    {
        $asciiArtService->clonioLogoWithShadow($this->output);

        $this->info('Clonio transfers your production database to your test and dev enironments');
        $this->info('with automatic anonymization, fake data generation and full audit trails.');
        $this->newLine();
        $this->info('It open source software. It is free for individuals and NGOs.');
        $this->newLine();
        $this->warn('See https://clonio.io for more information.');

        return static::SUCCESS;
    }
}
