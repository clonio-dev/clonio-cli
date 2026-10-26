<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit\Support;

use RuntimeException;

/**
 * Tiny loopback TCP server (HTTP or SMTP) running in a child PHP process.
 *
 * Used by the audit delivery adapter tests to capture the exact bytes a cURL /
 * socket based adapter sends, without touching the real network.
 */
final class G7FakeServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function __construct(
        private readonly string $dir,
        private readonly int $port,
        mixed $process,
    ) {
        $this->process = $process;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Start an HTTP server answering every request with the given raw status line and body.
     */
    public static function http(int $status = 200, string $body = 'ok', string $reason = 'OK'): self
    {
        return self::start('http', ['status' => $status, 'reason' => $reason, 'body' => $body]);
    }

    /**
     * Start an SMTP server; $replies maps an upper-cased command prefix to a full reply line.
     *
     * @param  array<string, string>  $replies
     */
    public static function smtp(array $replies = []): self
    {
        return self::start('smtp', ['replies' => $replies]);
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:'.$this->port.$path;
    }

    public function port(): int
    {
        return $this->port;
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public function requests(): array
    {
        $log = $this->dir.'/log';
        if (! is_file($log)) {
            return [];
        }

        $requests = [];
        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{method: string, path: string, headers: array<string, string>, body: string} $decoded */
            $decoded = json_decode(base64_decode($line), true);
            $requests[] = $decoded;
        }

        return $requests;
    }

    /** Everything the SMTP client sent, verbatim. */
    public function transcript(): string
    {
        $log = $this->dir.'/log';

        return is_file($log) ? (string) file_get_contents($log) : '';
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $config */
    private static function start(string $mode, array $config): self
    {
        $dir = sys_get_temp_dir().'/g7-fake-server-'.bin2hex(random_bytes(6));
        mkdir($dir);

        file_put_contents($dir.'/config.json', (string) json_encode(['mode' => $mode] + $config));
        file_put_contents($dir.'/server.php', self::script());

        $process = proc_open(
            [PHP_BINARY, $dir.'/server.php', $dir],
            // Pipes rather than 'file' descriptors: Pest's mutation runner wraps the file:// stream.
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start fake server');
        }

        $deadline = microtime(true) + 5;
        while (! is_file($dir.'/port') || filesize($dir.'/port') === 0) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Fake server did not start');
            }

            usleep(10_000);
            clearstatcache();
        }

        $server = new self($dir, (int) file_get_contents($dir.'/port'), $process);
        $server->pipes = $pipes;

        return $server;
    }

    private static function script(): string
    {
        return <<<'PHP'
<?php
$dir = $argv[1];
$config = json_decode(file_get_contents($dir.'/config.json'), true);
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($dir.'/port.tmp', substr($name, strrpos($name, ':') + 1));
rename($dir.'/port.tmp', $dir.'/port');

while ($conn = @stream_socket_accept($server, 600)) {
    if ($config['mode'] === 'http') {
        $raw = '';
        while (! str_contains($raw, "\r\n\r\n") && ($chunk = fread($conn, 8192)) !== false && $chunk !== '') {
            $raw .= $chunk;
        }
        if ($raw === '') { fclose($conn); continue; }
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);
        [$method, $path] = explode(' ', array_shift($lines));
        $headers = [];
        foreach ($lines as $line) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
        $length = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $length && ($chunk = fread($conn, 8192)) !== false && $chunk !== '') {
            $body .= $chunk;
        }
        file_put_contents($dir.'/log', base64_encode(json_encode(compact('method', 'path', 'headers', 'body')))."\n", FILE_APPEND);
        fwrite($conn, sprintf("HTTP/1.1 %d %s\r\nContent-Type: text/plain\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s", $config['status'], $config['reason'], strlen($config['body']), $config['body']));
        fclose($conn);
        continue;
    }

    // SMTP
    $replies = $config['replies'];
    $reply = function (string $command, string $default) use ($replies): string {
        foreach ($replies as $prefix => $line) {
            if (str_starts_with(strtoupper($command), $prefix)) {
                return $line;
            }
        }
        return $default;
    };
    fwrite($conn, ($replies['GREETING'] ?? '220 fake.smtp ESMTP')."\r\n");
    $inData = false;
    $data = '';
    while (($line = fgets($conn)) !== false) {
        file_put_contents($dir.'/log', $line, FILE_APPEND);
        if ($inData) {
            $data .= $line;
            if (str_ends_with($data, "\r\n.\r\n")) {
                $inData = false;
                fwrite($conn, $reply('.', '250 queued')."\r\n");
            }
            continue;
        }
        $command = rtrim($line, "\r\n");
        $upper = strtoupper($command);
        if (str_starts_with($upper, 'EHLO')) {
            $out = $reply($command, "250-fake.smtp\r\n250 AUTH LOGIN");
        } elseif (str_starts_with($upper, 'AUTH LOGIN')) {
            $out = $reply($command, '334 VXNlcm5hbWU6');
        } elseif (str_starts_with($upper, 'DATA')) {
            $out = $reply($command, '354 go ahead');
            $inData = ! str_starts_with($out, '5') && ! str_starts_with($out, '4');
            $data = '';
        } elseif (str_starts_with($upper, 'QUIT')) {
            fwrite($conn, $reply($command, '221 bye')."\r\n");
            break;
        } else {
            $out = $reply($command, '250 OK');
        }
        fwrite($conn, $out."\r\n");
    }
    fclose($conn);
}
PHP;
    }
}
