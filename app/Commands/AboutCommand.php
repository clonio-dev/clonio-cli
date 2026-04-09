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
        $asciiArtService->clonioLogoWithClaim($this->output, '  ');

        $this->info('  It is open source software. It is free for individuals and NGOs.');
        $this->newLine();
        $this->warn('  See https://clonio.dev for more information.');
        $this->newLine();

        return Command::SUCCESS;
    }
}
