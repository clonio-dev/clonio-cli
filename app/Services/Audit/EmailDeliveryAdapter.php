<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class EmailDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $host = is_string($channelConfig['host'] ?? null) ? $channelConfig['host'] : '';
        $port = is_int($channelConfig['port'] ?? null) ? $channelConfig['port'] : 587;
        $encryption = is_string($channelConfig['encryption'] ?? null) ? $channelConfig['encryption'] : 'tls';
        $username = is_string($channelConfig['username'] ?? null) ? $channelConfig['username'] : '';
        $password = $this->decryptIfNeeded(is_string($channelConfig['password'] ?? null) ? $channelConfig['password'] : '');
        $fromAddress = is_string($channelConfig['from_address'] ?? null) ? $channelConfig['from_address'] : '';
        $fromName = is_string($channelConfig['from_name'] ?? null) ? $channelConfig['from_name'] : 'Clonio';
        $to = is_array($channelConfig['to'] ?? null) ? $channelConfig['to'] : [];

        throw_if($to === [], RuntimeException::class, 'Email channel has no recipients configured');

        $recipients = array_values(array_filter($to, is_string(...)));

        throw_if($recipients === [], RuntimeException::class, 'Email channel has no valid recipients');

        $subject = $this->buildSubject($templateVars);
        $boundary = bin2hex(random_bytes(16));
        $body = $this->buildMimeBody($artefacts, $boundary);

        $headers = [
            'From' => sprintf('%s <%s>', $fromName, $fromAddress),
            'To' => implode(', ', $recipients),
            'Subject' => $subject,
            'MIME-Version' => '1.0',
            'Content-Type' => sprintf('multipart/mixed; boundary="%s"', $boundary),
            'Date' => gmdate('r'),
        ];

        $this->sendSmtp($host, $port, $encryption, $username, $password, $fromAddress, $recipients, $headers, $body);
    }

    /** @param array<string, string> $templateVars */
    private function buildSubject(array $templateVars): string
    {
        $source = $templateVars['source'] ?? 'unknown';
        $target = $templateVars['target'] ?? 'unknown';
        $timestamp = $templateVars['timestamp'] ?? date('Y-m-d');

        return sprintf('Clonio Audit Log — %s → %s (%s)', $source, $target, $timestamp);
    }

    /** @param array<string, string> $artefacts */
    private function buildMimeBody(array $artefacts, string $boundary): string
    {
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= "Clonio audit log attached. See HTML attachment for the full report.\r\n\r\n";

        foreach ($artefacts as $filename => $content) {
            $body .= "--{$boundary}\r\n";
            $body .= sprintf("Content-Type: application/octet-stream; name=\"%s\"\r\n", $filename);
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= sprintf("Content-Disposition: attachment; filename=\"%s\"\r\n\r\n", $filename);
            $body .= chunk_split(base64_encode($content));
        }

        return $body."--{$boundary}--\r\n";
    }

    /**
     * @param  list<string>  $recipients
     * @param  array<string, string>  $headers
     */
    private function sendSmtp(string $host, int $port, string $encryption, string $username, string $password, string $from, array $recipients, array $headers, string $body): void
    {
        $address = $encryption === 'ssl'
            ? sprintf('ssl://%s:%d', $host, $port)
            : sprintf('tcp://%s:%d', $host, $port);

        $socket = @stream_socket_client($address, $errno, $errstr, 15);

        if ($socket === false) {
            throw new RuntimeException(sprintf('SMTP connection failed: %s (%d)', $errstr, $errno));
        }

        try {
            $this->smtpRead($socket);
            $this->smtpCommand($socket, sprintf('EHLO %s', gethostname() ?: 'localhost'));

            if ($encryption === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS');
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $this->smtpCommand($socket, sprintf('EHLO %s', gethostname() ?: 'localhost'));
            }

            if ($username !== '' && $password !== '') {
                $this->smtpCommand($socket, 'AUTH LOGIN');
                $this->smtpCommand($socket, base64_encode($username));
                $this->smtpCommand($socket, base64_encode($password));
            }

            $this->smtpCommand($socket, sprintf('MAIL FROM:<%s>', $from));

            foreach ($recipients as $recipient) {
                $this->smtpCommand($socket, sprintf('RCPT TO:<%s>', $recipient));
            }

            $this->smtpCommand($socket, 'DATA');

            $message = '';
            foreach ($headers as $key => $value) {
                $message .= sprintf("%s: %s\r\n", $key, $value);
            }

            $message .= "\r\n".$body;

            fwrite($socket, $message."\r\n.\r\n");
            $this->smtpRead($socket);

            $this->smtpCommand($socket, 'QUIT');
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command."\r\n");

        return $this->smtpRead($socket);
    }

    /** @param resource $socket */
    private function smtpRead($socket): string
    {
        $response = '';

        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if ($code >= 400) {
            throw new RuntimeException(sprintf('SMTP error %d: %s', $code, trim($response)));
        }

        return $response;
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
