<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class EmailDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $host = (string) ($channelConfig['host'] ?? '');
        $port = (int) ($channelConfig['port'] ?? 587);
        $encryption = (string) ($channelConfig['encryption'] ?? 'tls');
        $username = (string) ($channelConfig['username'] ?? '');
        $password = $this->decryptIfNeeded((string) ($channelConfig['password'] ?? ''));
        $fromAddress = (string) ($channelConfig['from_address'] ?? '');
        $fromName = (string) ($channelConfig['from_name'] ?? 'Clonio');
        $to = is_array($channelConfig['to'] ?? null) ? $channelConfig['to'] : [];

        if ($to === []) {
            throw new RuntimeException('Email channel has no recipients configured');
        }

        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');
        $transport->setUsername($username);
        $transport->setPassword($password);

        $mailer = new Mailer($transport);
        $subject = $this->buildSubject($templateVars);

        $email = (new Email)
            ->from(sprintf('%s <%s>', $fromName, $fromAddress))
            ->subject($subject)
            ->text('Clonio audit log attached. See HTML attachment for the full report.');

        foreach ($to as $recipient) {
            if (is_string($recipient) && $recipient !== '') {
                $email->addTo($recipient);
            }
        }

        foreach ($artefacts as $filename => $content) {
            $email->attach($content, $filename);
        }

        $mailer->send($email);
    }

    /** @param array<string, string> $templateVars */
    private function buildSubject(array $templateVars): string
    {
        $source = $templateVars['source'] ?? 'unknown';
        $target = $templateVars['target'] ?? 'unknown';
        $timestamp = $templateVars['timestamp'] ?? date('Y-m-d');

        return sprintf('Clonio Audit Log — %s → %s (%s)', $source, $target, $timestamp);
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
