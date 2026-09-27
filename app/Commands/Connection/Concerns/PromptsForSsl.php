<?php

declare(strict_types=1);

namespace App\Commands\Connection\Concerns;

use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Database\Tls\CertificateFiles;
use LaravelZero\Framework\Commands\Command;

/**
 * Transport-security prompts shared by connection:add and connection:update (PRD-connection-tls §6).
 *
 * @mixin Command
 */
trait PromptsForSsl
{
    private const string DRIVER_DEFAULT_LABEL = 'Driver default';

    /** Returns null for "Driver default" (no ssl block). */
    private function askSslMode(?SslMode $default): ?SslMode
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

        $labels = array_map(static fn (SslMode $mode): string => $mode->label(), SslMode::cases());
        $labels[] = self::DRIVER_DEFAULT_LABEL;

        $defaultIndex = $default instanceof SslMode
            ? (int) array_search($default->label(), $labels, true)
            : count($labels) - 1;

        $answer = $this->choice('Transport security', $labels, $defaultIndex);

        foreach (SslMode::cases() as $mode) {
            if ($mode->label() === $answer) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * Asks for the files the mode and driver can use; everything else is dropped.
     */
    private function askSslFiles(DatabaseConnectionType $type, SslMode $mode, ?SslConfig $current): SslConfig
    {
        if ($mode === SslMode::Disable || $type === DatabaseConnectionType::SqlServer) {
            return new SslConfig($mode);
        }

        $ca = $mode === SslMode::Verify ? $this->askCertificatePath('CA certificate path', $current?->ca) : null;
        $cert = null;
        $key = null;

        $wantsClientCert = $current?->cert !== null;

        if ($this->input->isInteractive()) {
            $wantsClientCert = $this->confirm('Use a client certificate (mutual TLS)?', $wantsClientCert);
        }

        if ($wantsClientCert) {
            $cert = $this->askCertificatePath('Client certificate path', $current?->cert);
            $key = $this->askCertificatePath('Client key path', $current?->key);
        }

        return new SslConfig($mode, $ca, $cert, $key);
    }

    /**
     * connection:add variant of askSslFiles: prompts only for the files whose option wasn't
     * already given on the command line (M2), instead of dropping them silently.
     */
    private function askSslFilesForAdd(DatabaseConnectionType $type, SslMode $mode, ?string $ca, ?string $cert, ?string $key): SslConfig
    {
        if ($mode === SslMode::Disable || $type === DatabaseConnectionType::SqlServer) {
            return new SslConfig($mode, $ca, $cert, $key);
        }

        if ($mode === SslMode::Verify && $ca === null) {
            $ca = $this->askCertificatePath('CA certificate path', null);
        }

        $wantsClientCert = $cert !== null || $key !== null;

        if (! $wantsClientCert && $this->input->isInteractive()) {
            $wantsClientCert = $this->confirm('Use a client certificate (mutual TLS)?', false);
        }

        if ($wantsClientCert) {
            $cert ??= $this->askCertificatePath('Client certificate path', null);
            $key ??= $this->askCertificatePath('Client key path', null);
        }

        return new SslConfig($mode, $ca, $cert, $key);
    }

    /**
     * Enter keeps $current, "none" removes it, anything else replaces it. No default is passed
     * to ask(), otherwise an empty answer could not be told apart from "keep".
     */
    private function askCertificatePath(string $label, ?string $current): ?string
    {
        if (! $this->input->isInteractive()) {
            return $current;
        }

        $question = $current === null
            ? $label.' (leave empty for none)'
            : sprintf('%s [%s] (Enter = keep, "none" = remove)', $label, $current);

        $answer = $this->ask($question);

        if (! is_string($answer) || trim($answer) === '') {
            return $current;
        }

        return strtolower(trim($answer)) === 'none' ? null : trim($answer);
    }

    /** @return list<string> */
    private function sslErrors(DatabaseConnectionType $type, ?SslConfig $ssl, bool $modeExplicit = true, bool $checkFiles = true): array
    {
        if (! $ssl instanceof SslConfig) {
            return [];
        }

        $errors = $ssl->violations($type);

        if ($errors !== []) {
            if (! $modeExplicit) {
                return array_map(
                    static fn (string $error): string => $error === 'a CA certificate is only used with mode verify'
                        ? $error.' (pass --ssl-mode=verify)'
                        : $error,
                    $errors
                );
            }

            return $errors;
        }

        if (! $checkFiles) {
            return $errors;
        }

        foreach (resolve(CertificateFiles::class)->unreadable($ssl) as $path) {
            $errors[] = 'Certificate file not found or not readable: '.$path;
        }

        return $errors;
    }

    private function warnIfKeyExposed(?SslConfig $ssl): void
    {
        if (! $ssl instanceof SslConfig || $ssl->key === null) {
            return;
        }

        $files = resolve(CertificateFiles::class);

        if ($files->isKeyExposed($ssl->key)) {
            $this->warn(sprintf('Client key %s is readable by other users. Run: chmod 600 %s', $ssl->key, $files->resolve($ssl->key)));
        }
    }
}
