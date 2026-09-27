<?php

declare(strict_types=1);

namespace App\Services\Database\Tls;

use App\Data\ConnectionData;
use App\Enums\SslMode;
use Throwable;

/**
 * Maps opaque driver TLS errors to actionable hints (PRD-connection-tls §8).
 * mysqlnd reports every TLS failure as the same "[2002] Cannot connect to MySQL using SSL",
 * so the configured mode decides which hint applies.
 */
final class ConnectionErrorHint
{
    public static function for(Throwable $throwable, ConnectionData $connection, ?string $host): ?string
    {
        $message = $throwable->getMessage();

        $serverRequiresTls = str_contains($message, '[3159]')
            || str_contains($message, 'insecure transport are prohibited')
            // "no pg_hba.conf entry …" and "pg_hba.conf rejects connection …" (hostnossl … reject)
            || (str_contains($message, 'pg_hba.conf') && str_contains($message, 'no encryption'));

        if ($serverRequiresTls) {
            return sprintf('The server requires TLS. Run "clonio connection:update %s" and set transport security to "require" or "verify".', $connection->name);
        }

        $mysqlHandshakeFailed = str_contains($message, 'Cannot connect to MySQL using SSL');
        $verificationFailed = str_contains($message, 'certificate verify failed')
            || str_contains($message, 'does not match host name')
            || str_contains($message, 'root certificate file');

        if ($verificationFailed || ($mysqlHandshakeFailed && $connection->ssl?->mode === SslMode::Verify)) {
            return sprintf('TLS handshake failed. Likely causes: the CA file did not sign the server certificate, or the host name "%s" is not in the certificate. Use mode "require" to skip verification.', $host ?? '');
        }

        // mysqlnd against a server without TLS: "[2006] MySQL server has gone away" (Docker spike, §8).
        // Only meaningful when TLS was requested; otherwise [2006] is an ordinary dropped connection.
        $mysqlServerWithoutTls = str_contains($message, '[2006]')
            && in_array($connection->ssl?->mode, [SslMode::Require, SslMode::Verify], true);

        if ($mysqlHandshakeFailed || $mysqlServerWithoutTls || str_contains($message, 'server does not support SSL')) {
            return 'TLS handshake failed. The server may not support TLS — use mode "disable" if the connection is on a trusted network.';
        }

        return null;
    }
}
