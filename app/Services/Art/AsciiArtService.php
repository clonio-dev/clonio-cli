<?php

declare(strict_types=1);

namespace App\Services\Art;

use Illuminate\Console\OutputStyle;

class AsciiArtService
{
    public function clonioLogo(OutputStyle $output, string $indent = ''): void
    {
        $content = file_get_contents(resource_path('ascii-art/clonio-logo.txt'));
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);

        $this->paintToConsole($output, $lines, $indent);
    }

    public function clonioLogoWithShadow(OutputStyle $output, string $indent = ''): void
    {
        $content = file_get_contents(resource_path('ascii-art/clonio-logo-with-shadow.txt'));
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);

        $this->paintToConsole($output, $lines, $indent);
    }

    /**
     * @param  string[]  $lines
     */
    private function paintToConsole(OutputStyle $output, array $lines, string $indent = ''): void
    {
        array_map(fn (string $line) => $output->writeln($indent.$line), $lines);
    }
}
