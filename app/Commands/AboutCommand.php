<?php

namespace App\Commands;

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
    public function handle(): int
    {
        $this->info('Clonio');

        return static::SUCCESS;
    }
}
