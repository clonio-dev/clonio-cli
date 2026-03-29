<?php

declare(strict_types=1);

namespace App\Services\Art;

use Illuminate\Console\OutputStyle;

class AsciiArtService
{
    public function clonioLogo(OutputStyle $output): void
    {
        $content = file_get_contents(resource_path('ascii-art/clonio-logo.txt'));
        $lines = explode("\n", $content);

        $this->paintToConsole($output, $lines);
    }

    public function clonioLogoWithShadow(OutputStyle $output): void
    {
        $content = file_get_contents(resource_path('ascii-art/clonio-logo-with-shadow.txt'));
        $lines = explode("\n", $content);

        $this->paintToConsole($output, $lines);
    }

    /**
     * @param \Illuminate\Console\OutputStyle $output
     * @param string[] $lines
     * @return void
     */
    private function paintToConsole(OutputStyle $output, array $lines): void
    {
        array_map(fn(string $line) => $output->writeln($line), $lines);
    }
}
