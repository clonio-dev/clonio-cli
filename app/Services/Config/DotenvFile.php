<?php

declare(strict_types=1);

namespace App\Services\Config;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads and writes variables in the .env file of the working directory.
 */
class DotenvFile
{
    private const string FILENAME = '.env';

    /**
     * The variable's value in the file, or null when the file does not set it
     * (or sets it empty), does not exist, cannot be read or cannot be parsed.
     */
    public function get(string $name): ?string
    {
        $content = Storage::exists(self::FILENAME) ? Storage::get(self::FILENAME) : null;

        try {
            $value = Dotenv::parse((string) $content)[$name] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * The file's content, or null when it does not exist.
     *
     * @throws RuntimeException when the file exists but cannot be read
     */
    public function read(): ?string
    {
        if (! Storage::exists(self::FILENAME)) {
            return null;
        }

        $content = Storage::get(self::FILENAME);

        if (! is_string($content)) {
            throw new RuntimeException(sprintf('Cannot read %s: permission denied', $this->path()));
        }

        return $content;
    }

    /**
     * Sets the variable: replaces its line, or appends one, or creates the file.
     *
     * @throws RuntimeException when the file cannot be read or written
     */
    public function set(string $name, string $value): void
    {
        $content = $this->read();
        $line = $name.'='.$value;

        if ($content === null) {
            $this->write($line."\n");

            return;
        }

        $pattern = '/^'.preg_quote($name, '/').'[ \t]*=.*$/m';

        $this->write(preg_match($pattern, $content) === 1
            ? (string) preg_replace_callback($pattern, fn (): string => $line, $content) // @pest-mutate-ignore: RemoveStringCast
            : rtrim($content)."\n".$line."\n");
    }

    /**
     * Puts back content returned by read(): null deletes the file.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function restore(?string $content): void
    {
        if ($content === null) {
            Storage::delete(self::FILENAME);

            return;
        }

        $this->write($content);
    }

    /**
     * @throws RuntimeException
     */
    private function write(string $content): void
    {
        if (! Storage::put(self::FILENAME, $content)) {
            throw new RuntimeException(sprintf('Cannot write %s: permission denied', $this->path()));
        }

        chmod(Storage::path(self::FILENAME), 0600);
    }

    private function path(): string
    {
        $cwd = getcwd();

        return is_string($cwd) ? $cwd.'/'.self::FILENAME : self::FILENAME;
    }
}
