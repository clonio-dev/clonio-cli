<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Config\DotenvFile;
use App\Services\Secrets\EncryptionKey;
use App\Services\Secrets\SecretFiles;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Warns on stderr before a command runs when clonio.json or .env are
 * accessible to other users, and when the key still comes from APP_KEY.
 * stdout stays clean for pipes and scripts.
 */
final readonly class WarnAboutInsecureSecrets
{
    /** Commands that never touch secrets. */
    private const array SKIPPED = ['', 'list', 'help', 'completion', '_complete', 'about', 'update'];

    /** Commands that deal with APP_KEY themselves. */
    private const array HANDLE_LEGACY_KEY = ['init', 'key:rotate'];

    public function __construct(
        private SecretFiles $files,
        private DotenvFile $dotenv,
    ) {}

    public function handle(CommandStarting $event): void
    {
        $command = $event->command;

        if (in_array($command, self::SKIPPED, true)) {
            return;
        }

        $output = $event->output instanceof ConsoleOutputInterface
            ? $event->output->getErrorOutput()
            : $event->output;

        foreach ($this->files->exposed() as $path => $mode) {
            $output->writeln(sprintf(
                '<comment>  ⚠  %s is accessible to other users (mode %04o). Run: chmod 600 %s</comment>',
                $path,
                $mode,
                escapeshellarg($path),
            ));
        }

        if (in_array($command, self::HANDLE_LEGACY_KEY, true)) {
            return;
        }

        if ((EncryptionKey::locate($this->dotenv)[0] ?? null) === EncryptionKey::LEGACY_NAME) {
            $output->writeln('<comment>  ⚠  Clonio reads its key from APP_KEY. Run `clonio key:rotate` to move to a dedicated CLONIO_KEY.</comment>');
        }
    }
}
