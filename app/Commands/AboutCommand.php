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

    public function handle(AsciiArtService $asciiArtService): int
    {
        $asciiArtService->clonioLogoWithShadow($this->output);

        $this->info('Clonio transfers your production database to your test and dev environments');
        $this->info('with automatic anonymization, fake data generation and full audit trails.');
        $this->newLine();
        $this->info('It is open source software. It is free for individuals and NGOs.');
        $this->newLine();
        $this->warn('See https://clonio.io for more information.');

        return Command::SUCCESS;
    }
}
