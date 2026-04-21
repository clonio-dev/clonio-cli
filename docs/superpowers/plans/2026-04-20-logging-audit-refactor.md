# Logging & Audit Config Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor console output to use proper Symfony verbosity levels (dots/text/live-log), restructure audit config to Laravel's `database.php` pattern (`default` + `stack` channel type), and implement all missing delivery adapters (S3, Email, MS Teams, Slack, ntfy).

**Architecture:** Console output is decoupled from the audit system. The `RunLogWriter` gains a live-output callback for `-vv` mode. The audit config moves from `audit_log.deliver_to` + `run_log.deliver_to` to a single `audit.default` key referencing a channel name. A new `stack` channel type fans out to multiple child channels. Each delivery adapter implements a new `DeliveryAdapterInterface`. The existing `config/logging.php` (Monolog) is removed entirely.

**Tech Stack:** PHP 8.5, Laravel Zero 12, PestPHP 4, Symfony Console, Mockery

**GitHub Issue:** #74

---

## File Structure

### New files
| File | Responsibility |
|------|---------------|
| `app/Contracts/DeliveryAdapterInterface.php` | Shared interface for all delivery adapters |
| `app/Services/Audit/S3DeliveryAdapter.php` | S3-compatible upload via HTTP (pure PHP) |
| `app/Services/Audit/EmailDeliveryAdapter.php` | SMTP email via `symfony/mailer` |
| `app/Services/Audit/WebhookDeliveryAdapter.php` | HTTP POST for Teams/Slack webhooks |
| `app/Services/Audit/NtfyDeliveryAdapter.php` | ntfy.sh push notification |
| `app/Services/Audit/StackDeliveryAdapter.php` | Fan-out to multiple child channels |
| `tests/Unit/Services/Audit/S3DeliveryAdapterTest.php` | S3 adapter tests |
| `tests/Unit/Services/Audit/EmailDeliveryAdapterTest.php` | Email adapter tests |
| `tests/Unit/Services/Audit/WebhookDeliveryAdapterTest.php` | Webhook adapter tests |
| `tests/Unit/Services/Audit/NtfyDeliveryAdapterTest.php` | ntfy adapter tests |
| `tests/Unit/Services/Audit/StackDeliveryAdapterTest.php` | Stack adapter tests |

### Modified files
| File | Changes |
|------|---------|
| `app/Commands/Cloning/RunCommand.php` | Verbosity refactor: dots at normal (70-char width), text at `-v`, live log at `-vv`; errors to stderr; audit config reads `default` key |
| `app/Services/Cloning/RunLogWriter.php` | Add optional live-output callback for `-vv` streaming |
| `app/Services/Audit/AuditDeliveryService.php` | Resolve `default` channel, support `stack` type, use `DeliveryAdapterInterface` |
| `app/Services/Audit/LocalDeliveryAdapter.php` | Implement `DeliveryAdapterInterface` |
| `app/Services/Audit/StdoutDeliveryAdapter.php` | Implement `DeliveryAdapterInterface` |
| `app/Services/Audit/StderrDeliveryAdapter.php` | Implement `DeliveryAdapterInterface` |
| `app/Enums/AuditChannelType.php` | Add `Stack` case |
| `app/Commands/InitCommand.php` | New default config: `audit.default = 'local'`, simplified local path `'./'`, add `stack` example |
| `app/Services/Config/ConfigService.php` | Add `getAuditDefault()` / `setAuditDefault()`, remove `getAuditDeliverTo()` / `setAuditDeliverTo()` |
| `app/Commands/Audit/AddCommand.php` | Support `stack` type, remove deliver_to prompts, add `--set-default` flag |
| `app/Commands/Audit/UpdateCommand.php` | Remove deliver_to membership logic |
| `app/Commands/Audit/DeleteCommand.php` | Warn if channel is `audit.default`, remove deliver_to cleanup |
| `app/Commands/Audit/ListCommand.php` | Show `default` indicator instead of deliver_to checkmarks |
| `app/Providers/AppServiceProvider.php` | Remove logging path config from `boot()`, register new adapters |
| `config/logging.php` | **DELETE** |
| `docs/commands/cloning-run.md` | Update verbosity table and output examples |
| `docs/commands/audit-channel.md` | Update config format, document `stack` type and `default` key |
| `tests/Unit/Services/Audit/AuditDeliveryServiceTest.php` | Update for new config format |
| `tests/Feature/Commands/Cloning/RunCommandTest.php` | Update audit config in test fixtures |
| `tests/Unit/Enums/AuditChannelTypeTest.php` | Add `Stack` case |

### Deleted files
| File | Reason |
|------|--------|
| `config/logging.php` | Monolog config no longer needed — all output goes through Console |

---

## Task 1: Remove `config/logging.php` and Monolog wiring

**Files:**
- Delete: `config/logging.php`
- Modify: `app/Providers/AppServiceProvider.php:28-33`

- [ ] **Step 1: Delete the logging config**

```bash
rm config/logging.php
```

- [ ] **Step 2: Remove the boot() logging path config from AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, replace lines 27–34:

```php
public function boot(): void
{
    config([
        'logging.channels.single.path' => Phar::running() !== '' && Phar::running() !== '0'
                ? dirname(Phar::running(false)).'/clonio.log'
                : storage_path('logs/clonio.log'),
    ]);
}
```

With:

```php
public function boot(): void
{
    //
}
```

Remove the `use Phar;` import if no longer used elsewhere in the file (it is not).

- [ ] **Step 3: Run tests to verify nothing breaks**

```bash
composer test:unit
```

Expected: All existing tests pass. The logging config was never tested directly.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor: remove Monolog logging config — output goes through Console"
```

---

## Task 2: Add live-output callback to `RunLogWriter`

**Files:**
- Modify: `app/Services/Cloning/RunLogWriter.php`
- Test: `tests/Unit/Services/Cloning/RunLogWriterTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Services/Cloning/RunLogWriterTest.php`:

```php
it('calls the live output callback on each log event', function (): void {
    $writer = new RunLogWriter;
    $captured = [];
    $writer->setLiveOutput(function (string $level, string $event, array $extra) use (&$captured): void {
        $captured[] = ['level' => $level, 'event' => $event, 'extra' => $extra];
    });

    $writer->log('info', 'table_transferred', ['table' => 'users']);
    $writer->log('error', 'table_failed', ['table' => 'orders']);

    expect($captured)->toHaveCount(2)
        ->and($captured[0]['event'])->toBe('table_transferred')
        ->and($captured[1]['level'])->toBe('error');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/RunLogWriterTest.php --filter="live output"
```

Expected: FAIL — method `setLiveOutput` does not exist.

- [ ] **Step 3: Implement `setLiveOutput` on RunLogWriter**

Replace `app/Services/Cloning/RunLogWriter.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use DateTimeImmutable;
use DateTimeZone;

final class RunLogWriter
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private readonly DateTimeImmutable $startedAt;

    /** @var (callable(string, string, array<string, mixed>): void)|null */
    private $liveOutput = null;

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** @param callable(string, string, array<string, mixed>): void $callback */
    public function setLiveOutput(callable $callback): void
    {
        $this->liveOutput = $callback;
    }

    /** @param array<string, mixed> $extra */
    public function log(string $level, string $event, array $extra = []): void
    {
        $ms = sprintf('%03d', (int) (microtime(true) * 1000) % 1000);
        $ts = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.').$ms.'Z';

        $this->events[] = array_merge([
            'ts' => $ts,
            'level' => $level,
            'event' => $event,
        ], $extra);

        if ($this->liveOutput !== null) {
            ($this->liveOutput)($level, $event, $extra);
        }
    }

    public function flush(): string
    {
        return implode("\n", array_map(
            static fn (array $e): string => (string) json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->events
        ))."\n";
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/RunLogWriterTest.php -v
```

Expected: All tests pass, including the new one.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cloning/RunLogWriter.php tests/Unit/Services/Cloning/RunLogWriterTest.php
git commit -m "feat: add live-output callback to RunLogWriter for -vv streaming"
```

---

## Task 3: Refactor RunCommand verbosity output

**Files:**
- Modify: `app/Commands/Cloning/RunCommand.php:78-560`
- Test: `tests/Feature/Commands/Cloning/RunCommandTest.php`

The output behavior changes to:

| Level | Flag | What's shown |
|-------|------|-------------|
| quiet | `-q` / `--ci` | Nothing (only exit code) |
| normal | (default) | Dots (`.FE?S`) 70 chars wide, summary at end |
| verbose | `-v` | One line per table: `  ✓  users  (1,234 rows)` |
| very verbose | `-vv` | Live RunLogWriter events streamed to stderr |

- [ ] **Step 1: Refactor the onProgress callback and verbosity handling**

In `app/Commands/Cloning/RunCommand.php`, replace the `$verbose` variable and all its usage. The key changes:

1. Replace `$verbose = $this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;` with verbosity level detection:

```php
$verbosity = $this->getOutput()->getVerbosity();
$isVerbose = $verbosity >= OutputInterface::VERBOSITY_VERBOSE;
$isVeryVerbose = $verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE;
```

2. For `-vv` mode, wire up the RunLogWriter live output to stderr:

```php
if ($isVeryVerbose) {
    $runLog->setLiveOutput(function (string $level, string $event, array $extra): void {
        $formatted = sprintf('[%s] %s', strtoupper($level), $event);
        if ($extra !== []) {
            $formatted .= ' '.json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        fwrite(STDERR, $formatted."\n");
    });
}
```

3. For dots mode (normal verbosity, not quiet), track column position and wrap at 70:

```php
$dotColumn = 0;
$maxDotColumns = 70;
```

4. The `onProgress` callback becomes:

```php
onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped) use ($isVerbose, $ci, &$notFoundTables, &$schemaFailureTables, &$dotColumn, $maxDotColumns): void {
    if ($ci) {
        // Track not-found / schema failures silently
        if ($status === TableRunStatus::NotFound) {
            $notFoundTables[] = $tableName;
        } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
            $schemaFailureTables[] = $tableName;
        }
        return;
    }

    if ($isVerbose) {
        // -v: one line per table
        if ($status === TableRunStatus::Transferred) {
            $this->line(sprintf('  <info>✓</info>  %s  (%s rows%s)', $tableName, number_format($rows), $skipped > 0 ? ", $skipped skipped" : ''));
        } elseif ($status === TableRunStatus::NotFound) {
            $this->line(sprintf('  <comment>?</comment>  %s  — not found in source, skipped', $tableName));
            $notFoundTables[] = $tableName;
        } elseif ($status === TableRunStatus::Failed) {
            $this->line(sprintf('  <error>✗</error>  %s  — failed', $tableName));
        } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
            $this->line(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
            $schemaFailureTables[] = $tableName;
        }
        return;
    }

    // Normal: dot indicators
    $indicator = match ($status) {
        TableRunStatus::Transferred => $skipped > 0 ? 'F' : '.',
        TableRunStatus::Failed => 'E',
        TableRunStatus::NotFound => '?',
        TableRunStatus::SkippedBySchemaFailure => 'S',
        default => null,
    };

    if ($status === TableRunStatus::NotFound) {
        $notFoundTables[] = $tableName;
    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
        $schemaFailureTables[] = $tableName;
    }

    if ($indicator !== null) {
        $this->output->write($indicator);
        $dotColumn++;
        if ($dotColumn >= $maxDotColumns) {
            $this->output->writeln('');
            $dotColumn = 0;
        }
    }
},
```

5. Phase status messages (YAML validation, connecting, etc.) only show at `-v`:

```php
if ($isVerbose) {
    $this->line('  <info>✓</info>  Validating YAML ...');
}
```

This is the same as current behavior — the existing `$verbose` checks map to `$isVerbose`.

6. The summary newline at Phase 8: only needed when dots were printed (normal mode, not ci):

```php
if (! $isVerbose && ! $ci && $dotColumn > 0) {
    $this->line('');
}
```

- [ ] **Step 2: Run existing tests to verify no regressions**

```bash
./vendor/bin/pest tests/Feature/Commands/Cloning/RunCommandTest.php -v
```

Expected: All existing tests pass. The default output still produces dots.

- [ ] **Step 3: Commit**

```bash
git add app/Commands/Cloning/RunCommand.php
git commit -m "refactor: implement verbosity levels — dots (default), text (-v), live log (-vv)"
```

---

## Task 4: Add `Stack` to `AuditChannelType` enum

**Files:**
- Modify: `app/Enums/AuditChannelType.php`
- Test: `tests/Unit/Enums/AuditChannelTypeTest.php`

- [ ] **Step 1: Update the test expectations**

In `tests/Unit/Enums/AuditChannelTypeTest.php`, update the `values` test to include `'stack'` and update `labels` to include `'Stack (fan-out to multiple channels)'`. Add test for `Stack` in `defaultDeliversProcessLog` (should return `false`) and `hasSecrets` (should return `false`).

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Unit/Enums/AuditChannelTypeTest.php -v
```

Expected: FAIL — `'stack'` not in values.

- [ ] **Step 3: Add `Stack` case to the enum**

In `app/Enums/AuditChannelType.php`:

```php
case Stack = 'stack';
```

In `label()` match:

```php
self::Stack => 'Stack (fan-out to multiple channels)',
```

In `defaultDeliversProcessLog()` match, add `self::Stack` to the `default` arm (returns `false`).

In `hasSecrets()` match, add `self::Stack` to the `false` arm:

```php
self::Local, self::Stdout, self::Stderr, self::Stack => false,
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/pest tests/Unit/Enums/AuditChannelTypeTest.php -v
```

Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/AuditChannelType.php tests/Unit/Enums/AuditChannelTypeTest.php
git commit -m "feat: add Stack channel type to AuditChannelType enum"
```

---

## Task 5: Create `DeliveryAdapterInterface` and update existing adapters

**Files:**
- Create: `app/Contracts/DeliveryAdapterInterface.php`
- Modify: `app/Services/Audit/LocalDeliveryAdapter.php`
- Modify: `app/Services/Audit/StdoutDeliveryAdapter.php`
- Modify: `app/Services/Audit/StderrDeliveryAdapter.php`

- [ ] **Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace App\Contracts;

interface DeliveryAdapterInterface
{
    /**
     * Deliver artefacts to the channel.
     *
     * @param  array<string, string>  $artefacts  filename => content pairs
     * @param  array<string, mixed>  $channelConfig  channel-specific configuration
     * @param  array<string, string>  $templateVars  path template variables
     */
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void;
}
```

- [ ] **Step 2: Update `LocalDeliveryAdapter` to implement the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Storage;

class LocalDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $path = is_string($channelConfig['path'] ?? null) ? $channelConfig['path'] : '.';

        $resolvedPath = $path;
        foreach ($templateVars as $var => $value) {
            $resolvedPath = str_replace('{'.$var.'}', $value, $resolvedPath);
        }
        $resolvedPath = rtrim($resolvedPath, '/');

        foreach ($artefacts as $filename => $content) {
            $fullPath = $resolvedPath.'/'.$filename;
            Storage::disk('local')->put($fullPath, $content);
        }
    }
}
```

- [ ] **Step 3: Update `StdoutDeliveryAdapter`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;

class StdoutDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        foreach ($artefacts as $content) {
            fwrite(STDOUT, $content);
        }
    }
}
```

- [ ] **Step 4: Update `StderrDeliveryAdapter`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;

class StderrDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        foreach ($artefacts as $content) {
            fwrite(STDERR, $content);
        }
    }
}
```

- [ ] **Step 5: Update existing LocalDeliveryAdapter tests**

In `tests/Unit/Services/Audit/LocalDeliveryAdapterTest.php`, update calls from `deliver($artefacts, $path, $templateVars)` to `deliver($artefacts, ['path' => $path], $templateVars)`.

- [ ] **Step 6: Run tests**

```bash
./vendor/bin/pest tests/Unit/Services/Audit/LocalDeliveryAdapterTest.php -v
```

Expected: All pass.

- [ ] **Step 7: Commit**

```bash
git add app/Contracts/DeliveryAdapterInterface.php app/Services/Audit/LocalDeliveryAdapter.php app/Services/Audit/StdoutDeliveryAdapter.php app/Services/Audit/StderrDeliveryAdapter.php tests/Unit/Services/Audit/LocalDeliveryAdapterTest.php
git commit -m "refactor: introduce DeliveryAdapterInterface, update existing adapters"
```

---

## Task 6: Implement S3 delivery adapter

**Files:**
- Create: `app/Services/Audit/S3DeliveryAdapter.php`
- Test: `tests/Unit/Services/Audit/S3DeliveryAdapterTest.php`

The S3 adapter uses PHP's native `curl` functions (available in the SPC build) to make signed S3 PUT requests using AWS Signature V4. No external SDK needed.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Services\Audit\S3DeliveryAdapter;

it('builds correct S3 object keys from path prefix and template vars', function (): void {
    $adapter = new S3DeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'resolveKey');
    $key = $reflection->invoke($adapter, 'clonio/{year}/{month}/', 'audit.html', [
        'year' => '2026',
        'month' => '04',
    ]);
    expect($key)->toBe('clonio/2026/04/audit.html');
});

it('builds correct S3 object keys without trailing slash', function (): void {
    $adapter = new S3DeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'resolveKey');
    $key = $reflection->invoke($adapter, 'backups', 'process.jsonl', []);
    expect($key)->toBe('backups/process.jsonl');
});
```

- [ ] **Step 2: Implement the adapter**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class S3DeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $endpoint = $channelConfig['endpoint'] ?? '';
        $bucket = $channelConfig['bucket'] ?? '';
        $region = $channelConfig['region'] ?? 'us-east-1';
        $accessKey = $channelConfig['access_key'] ?? '';
        $secretKey = $this->decryptIfNeeded($channelConfig['secret_key'] ?? '');
        $pathPrefix = $channelConfig['path_prefix'] ?? '';

        foreach ($artefacts as $filename => $content) {
            $key = $this->resolveKey($pathPrefix, $filename, $templateVars);
            $this->putObject($endpoint, $bucket, $key, $content, $region, $accessKey, $secretKey);
        }
    }

    private function resolveKey(string $pathPrefix, string $filename, array $templateVars): string
    {
        $resolved = $pathPrefix;
        foreach ($templateVars as $var => $value) {
            $resolved = str_replace('{'.$var.'}', $value, $resolved);
        }
        $resolved = rtrim($resolved, '/');

        return $resolved !== '' ? $resolved.'/'.$filename : $filename;
    }

    private function putObject(string $endpoint, string $bucket, string $key, string $content, string $region, string $accessKey, string $secretKey): void
    {
        $url = rtrim($endpoint, '/').'/'.$bucket.'/'.$key;
        $date = gmdate('Ymd\THis\Z');
        $dateShort = gmdate('Ymd');
        $contentHash = hash('sha256', $content);

        $headers = [
            'host' => parse_url($endpoint, PHP_URL_HOST) ?: '',
            'x-amz-content-sha256' => $contentHash,
            'x-amz-date' => $date,
            'content-length' => (string) strlen($content),
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k.':'.$v."\n";
        }

        $canonicalRequest = "PUT\n/{$bucket}/{$key}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$contentHash}";
        $scope = "{$dateShort}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n".hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $dateShort, 'AWS4'.$secretKey, true),
                true),
            true),
        true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for S3 upload');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: '.$authorization,
                'x-amz-content-sha256: '.$contentHash,
                'x-amz-date: '.$date,
                'Content-Length: '.strlen($content),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('S3 PUT failed (HTTP %d): %s', $httpCode, is_string($response) ? $response : $error));
        }
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/pest tests/Unit/Services/Audit/S3DeliveryAdapterTest.php -v
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Audit/S3DeliveryAdapter.php tests/Unit/Services/Audit/S3DeliveryAdapterTest.php
git commit -m "feat: implement S3 delivery adapter with AWS Signature V4"
```

---

## Task 7: Implement Email delivery adapter

**Files:**
- Create: `app/Services/Audit/EmailDeliveryAdapter.php`
- Test: `tests/Unit/Services/Audit/EmailDeliveryAdapterTest.php`

Uses `symfony/mailer` (already a transitive dependency via Laravel).

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Services\Audit\EmailDeliveryAdapter;

it('builds email subject with source and target from template vars', function (): void {
    $adapter = new EmailDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildSubject');
    $subject = $reflection->invoke($adapter, [
        'source' => 'production',
        'target' => 'staging',
        'timestamp' => '2026-04-20T10-00-00Z',
    ]);
    expect($subject)->toBe('Clonio Audit Log — production → staging (2026-04-20T10-00-00Z)');
});
```

- [ ] **Step 2: Implement the adapter**

```php
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
        $host = $channelConfig['host'] ?? '';
        $port = (int) ($channelConfig['port'] ?? 587);
        $encryption = $channelConfig['encryption'] ?? 'tls';
        $username = $channelConfig['username'] ?? '';
        $password = $this->decryptIfNeeded($channelConfig['password'] ?? '');
        $fromAddress = $channelConfig['from_address'] ?? '';
        $fromName = $channelConfig['from_name'] ?? 'Clonio';
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
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/pest tests/Unit/Services/Audit/EmailDeliveryAdapterTest.php -v
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Audit/EmailDeliveryAdapter.php tests/Unit/Services/Audit/EmailDeliveryAdapterTest.php
git commit -m "feat: implement Email delivery adapter via symfony/mailer"
```

---

## Task 8: Implement Webhook delivery adapter (Teams + Slack)

**Files:**
- Create: `app/Services/Audit/WebhookDeliveryAdapter.php`
- Test: `tests/Unit/Services/Audit/WebhookDeliveryAdapterTest.php`

Both Teams and Slack use webhook URLs that accept JSON POST requests. Teams uses Adaptive Cards, Slack uses Block Kit.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Services\Audit\WebhookDeliveryAdapter;

it('builds Teams payload with correct structure', function (): void {
    $adapter = new WebhookDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildTeamsPayload');
    $payload = $reflection->invoke($adapter, 'production', 'staging', '2026-04-20T10-00-00Z', true, 5, 1234);

    expect($payload)->toBeArray()
        ->and($payload['type'])->toBe('message')
        ->and($payload['attachments'][0]['content']['body'][0]['text'])->toContain('production')
        ->and($payload['attachments'][0]['content']['body'][0]['text'])->toContain('staging');
});

it('builds Slack payload with correct structure', function (): void {
    $adapter = new WebhookDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildSlackPayload');
    $payload = $reflection->invoke($adapter, 'production', 'staging', '2026-04-20T10-00-00Z', true, 5, 1234);

    expect($payload)->toBeArray()
        ->and($payload['blocks'])->toBeArray()
        ->and($payload['blocks'][0]['text']['text'])->toContain('Clonio Audit');
});
```

- [ ] **Step 2: Implement the adapter**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use App\Enums\AuditChannelType;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class WebhookDeliveryAdapter implements DeliveryAdapterInterface
{
    public function __construct(
        private readonly AuditChannelType $channelType = AuditChannelType::Slack,
    ) {}

    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $webhookUrl = $this->decryptIfNeeded($channelConfig['webhook_url'] ?? '');

        if ($webhookUrl === '') {
            throw new RuntimeException('Webhook URL is empty');
        }

        $source = $templateVars['source'] ?? 'unknown';
        $target = $templateVars['target'] ?? 'unknown';
        $timestamp = $templateVars['timestamp'] ?? date('Y-m-d');
        $tableCount = count($artefacts);
        $success = true; // Webhook is called regardless; success info is in artefact names

        $payload = $this->channelType === AuditChannelType::MsTeams
            ? $this->buildTeamsPayload($source, $target, $timestamp, $success, $tableCount, 0)
            : $this->buildSlackPayload($source, $target, $timestamp, $success, $tableCount, 0);

        $this->post($webhookUrl, $payload);
    }

    /** @return array<string, mixed> */
    private function buildTeamsPayload(string $source, string $target, string $timestamp, bool $success, int $tables, int $rows): array
    {
        $status = $success ? 'Success' : 'Failed';

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => "Clonio: {$source} → {$target}", 'weight' => 'bolder', 'size' => 'medium'],
                        ['type' => 'TextBlock', 'text' => "Status: {$status} | Tables: {$tables} | Timestamp: {$timestamp}", 'wrap' => true],
                    ],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function buildSlackPayload(string $source, string $target, string $timestamp, bool $success, int $tables, int $rows): array
    {
        $emoji = $success ? ':white_check_mark:' : ':x:';
        $status = $success ? 'Success' : 'Failed';

        return [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "{$emoji} *Clonio Audit* — {$source} → {$target}\nStatus: {$status} | Tables: {$tables} | {$timestamp}",
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function post(string $url, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode webhook payload as JSON');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for webhook POST');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('Webhook POST failed (HTTP %d): %s', $httpCode, is_string($response) ? $response : $error));
        }
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
./vendor/bin/pest tests/Unit/Services/Audit/WebhookDeliveryAdapterTest.php -v
git add app/Services/Audit/WebhookDeliveryAdapter.php tests/Unit/Services/Audit/WebhookDeliveryAdapterTest.php
git commit -m "feat: implement Webhook delivery adapter for Teams and Slack"
```

---

## Task 9: Implement ntfy delivery adapter

**Files:**
- Create: `app/Services/Audit/NtfyDeliveryAdapter.php`
- Test: `tests/Unit/Services/Audit/NtfyDeliveryAdapterTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Services\Audit\NtfyDeliveryAdapter;

it('builds correct ntfy URL from config', function (): void {
    $adapter = new NtfyDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildUrl');
    $url = $reflection->invoke($adapter, ['url' => 'https://ntfy.sh', 'topic' => 'clonio-alerts']);
    expect($url)->toBe('https://ntfy.sh/clonio-alerts');
});

it('builds correct ntfy URL with trailing slash in base URL', function (): void {
    $adapter = new NtfyDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildUrl');
    $url = $reflection->invoke($adapter, ['url' => 'https://ntfy.example.com/', 'topic' => 'audit']);
    expect($url)->toBe('https://ntfy.example.com/audit');
});
```

- [ ] **Step 2: Implement the adapter**

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class NtfyDeliveryAdapter implements DeliveryAdapterInterface
{
    public function deliver(array $artefacts, array $channelConfig, array $templateVars): void
    {
        $url = $this->buildUrl($channelConfig);
        $priority = $channelConfig['priority'] ?? 'default';
        $tags = is_array($channelConfig['tags'] ?? null) ? $channelConfig['tags'] : [];
        $token = isset($channelConfig['token']) ? $this->decryptIfNeeded((string) $channelConfig['token']) : null;

        $source = $templateVars['source'] ?? 'unknown';
        $target = $templateVars['target'] ?? 'unknown';
        $timestamp = $templateVars['timestamp'] ?? date('Y-m-d');
        $fileCount = count($artefacts);

        $title = "Clonio: {$source} → {$target}";
        $message = "Audit completed at {$timestamp}. {$fileCount} artefact(s) generated.";

        $headers = [
            'Title: '.$title,
            'Priority: '.$priority,
        ];

        if ($tags !== []) {
            $headers[] = 'Tags: '.implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tags));
        }

        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for ntfy POST');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $message,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('ntfy POST failed (HTTP %d): %s', $httpCode, is_string($response) ? $response : $error));
        }
    }

    /** @param array<string, mixed> $channelConfig */
    private function buildUrl(array $channelConfig): string
    {
        $base = rtrim((string) ($channelConfig['url'] ?? 'https://ntfy.sh'), '/');
        $topic = (string) ($channelConfig['topic'] ?? '');

        return $base.'/'.$topic;
    }

    private function decryptIfNeeded(string $value): string
    {
        if (str_starts_with($value, 'encrypted:')) {
            return Crypt::decryptString(substr($value, 10));
        }

        return $value;
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
./vendor/bin/pest tests/Unit/Services/Audit/NtfyDeliveryAdapterTest.php -v
git add app/Services/Audit/NtfyDeliveryAdapter.php tests/Unit/Services/Audit/NtfyDeliveryAdapterTest.php
git commit -m "feat: implement ntfy delivery adapter"
```

---

## Task 10: Restructure audit config — `audit.default` + `stack` type

**Files:**
- Modify: `app/Services/Config/ConfigService.php`
- Modify: `app/Services/Audit/AuditDeliveryService.php`
- Create: `app/Services/Audit/StackDeliveryAdapter.php` (not a real adapter — logic in AuditDeliveryService)
- Test: `tests/Unit/Services/Audit/AuditDeliveryServiceTest.php`

The new config structure:

```json
{
  "audit": {
    "default": "local",
    "channels": {
      "local": { "type": "local", "path": "./" },
      "production": {
        "type": "stack",
        "channels": ["local", "slack-notify"]
      }
    }
  }
}
```

- [ ] **Step 1: Add `getAuditDefault()` and `setAuditDefault()` to ConfigService**

In `app/Services/Config/ConfigService.php`, add:

```php
public function getAuditDefault(): ?string
{
    $data = $this->load();
    $auditSection = $data['audit'] ?? null;
    $default = is_array($auditSection) ? ($auditSection['default'] ?? null) : null;

    return is_string($default) ? $default : null;
}

public function setAuditDefault(string $channelName): void
{
    $data = $this->load();
    if (! isset($data['audit']) || ! is_array($data['audit'])) {
        $data['audit'] = ['channels' => [], 'default' => $channelName];
    } else {
        $data['audit']['default'] = $channelName;
    }
    $this->save($data);
}
```

Keep the existing `getAuditDeliverTo()` / `setAuditDeliverTo()` for backwards compatibility during migration. They can be deprecated/removed in a follow-up.

- [ ] **Step 2: Rewrite `AuditDeliveryService` for the new config format**

The service now:
1. Reads `audit.default` to find the active channel
2. Resolves the channel config from `audit.channels`
3. If the channel type is `stack`, iterates over its `channels` list and delivers to each
4. Uses `DeliveryAdapterInterface` for all delivery

```php
<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\DeliveryAdapterInterface;
use App\Enums\AuditChannelType;
use App\Services\Cloning\RunLogWriter;
use Illuminate\Support\Sleep;
use Throwable;

class AuditDeliveryService
{
    /** @var array<string, DeliveryAdapterInterface> */
    private readonly array $adapters;

    public function __construct(
        private readonly RunLogWriter $runLog,
        LocalDeliveryAdapter $localAdapter,
        StdoutDeliveryAdapter $stdoutAdapter,
        StderrDeliveryAdapter $stderrAdapter,
        S3DeliveryAdapter $s3Adapter,
        EmailDeliveryAdapter $emailAdapter,
        WebhookDeliveryAdapter $teamsAdapter,
        WebhookDeliveryAdapter $slackAdapter,
        NtfyDeliveryAdapter $ntfyAdapter,
    ) {
        $this->adapters = [
            AuditChannelType::Local->value => $localAdapter,
            AuditChannelType::Stdout->value => $stdoutAdapter,
            AuditChannelType::Stderr->value => $stderrAdapter,
            AuditChannelType::S3->value => $s3Adapter,
            AuditChannelType::Email->value => $emailAdapter,
            AuditChannelType::MsTeams->value => $teamsAdapter,
            AuditChannelType::Slack->value => $slackAdapter,
            AuditChannelType::Ntfy->value => $ntfyAdapter,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $auditConfig
     * @param  array<string, string>  $auditArtefacts
     * @param  string  $processLogContent
     * @param  list<string>  $channelOverride  if non-empty, deliver to these channels instead of default
     * @param  array<string, string>  $templateVars
     */
    public function deliver(
        ?array $auditConfig,
        array $auditArtefacts,
        string $processLogContent,
        array $channelOverride,
        array $templateVars,
    ): void {
        if ($auditConfig === null) {
            return;
        }

        /** @var array<string, mixed> $channels */
        $channels = is_array($auditConfig['channels'] ?? null) ? $auditConfig['channels'] : [];

        // Determine which channel(s) to deliver to
        $targetChannelNames = $channelOverride !== []
            ? $channelOverride
            : (is_string($auditConfig['default'] ?? null) ? [$auditConfig['default']] : []);

        // Also support legacy deliver_to format for backwards compatibility
        if ($targetChannelNames === [] && isset($auditConfig['audit_log']['deliver_to'])) {
            $deliverTo = $auditConfig['audit_log']['deliver_to'];
            $targetChannelNames = is_array($deliverTo) ? array_filter($deliverTo, is_string(...)) : [];
        }

        foreach ($targetChannelNames as $channelName) {
            $this->deliverToChannel($channelName, $channels, $auditArtefacts, $processLogContent, $templateVars);
        }
    }

    /**
     * @param  array<string, mixed>  $allChannels
     * @param  array<string, string>  $auditArtefacts
     * @param  array<string, string>  $templateVars
     */
    private function deliverToChannel(
        string $channelName,
        array $allChannels,
        array $auditArtefacts,
        string $processLogContent,
        array $templateVars,
    ): void {
        $channelConfig = $allChannels[$channelName] ?? null;
        if (! is_array($channelConfig)) {
            $this->runLog->log('warning', 'audit_channel_not_found', ['channel' => $channelName]);
            return;
        }

        $typeValue = $channelConfig['type'] ?? null;
        if (! is_string($typeValue)) {
            return;
        }

        $channelType = AuditChannelType::tryFrom($typeValue);
        if ($channelType === null) {
            $this->runLog->log('warning', 'audit_channel_unsupported', ['channel' => $channelName, 'type' => $typeValue]);
            return;
        }

        // Stack: fan out to child channels
        if ($channelType === AuditChannelType::Stack) {
            $childNames = is_array($channelConfig['channels'] ?? null) ? $channelConfig['channels'] : [];
            foreach ($childNames as $childName) {
                if (is_string($childName)) {
                    $this->deliverToChannel($childName, $allChannels, $auditArtefacts, $processLogContent, $templateVars);
                }
            }
            return;
        }

        // Resolve what this channel should deliver
        $deliversAudit = isset($channelConfig['delivers_audit'])
            ? (bool) $channelConfig['delivers_audit']
            : true;

        $deliversProcessLog = isset($channelConfig['delivers_process_log'])
            ? (bool) $channelConfig['delivers_process_log']
            : $channelType->defaultDeliversProcessLog();

        $processLogFilename = ($templateVars['source'] ?? 'source')
            .'_'.($templateVars['target'] ?? 'target')
            .'_'.($templateVars['timestamp'] ?? date('Y-m-d'))
            .'_process.jsonl';

        $adapter = $this->adapters[$channelType->value] ?? null;
        if ($adapter === null) {
            $this->runLog->log('warning', 'audit_channel_unsupported', ['channel' => $channelName, 'type' => $typeValue]);
            return;
        }

        if ($deliversAudit) {
            $this->deliverWithRetry(fn () => $adapter->deliver($auditArtefacts, $channelConfig, $templateVars));
        }

        if ($deliversProcessLog) {
            $this->deliverWithRetry(fn () => $adapter->deliver([$processLogFilename => $processLogContent], $channelConfig, $templateVars));
        }
    }

    private function deliverWithRetry(callable $fn, int $maxAttempts = 3, int $initialDelayMs = 500, int $backoff = 2, int $maxDelayMs = 10000): void
    {
        $attempt = 0;
        $delay = $initialDelayMs;

        while ($attempt < $maxAttempts) {
            try {
                $fn();
                return;
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    $this->runLog->log('error', 'audit_delivery_failed', ['error' => $e->getMessage()]);
                    return;
                }
                Sleep::usleep($delay * 1000);
                $delay = min($delay * $backoff, $maxDelayMs);
            }
        }
    }
}
```

- [ ] **Step 3: Update `AuditDeliveryServiceTest` for new config format**

Update all test fixtures to use `'default' => 'channel-name'` format instead of `'audit_log' => ['deliver_to' => [...]]`. Add test for stack fan-out. Key test cases:

- `null` audit config → silently skips
- Config with `default: 'local'` → delivers to local adapter
- Config with stack channel → fans out to child channels
- `channelOverride` overrides `default`
- Unknown channel name → logs warning, continues

- [ ] **Step 4: Run all tests**

```bash
./vendor/bin/pest tests/Unit/Services/Audit/ -v
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Config/ConfigService.php app/Services/Audit/AuditDeliveryService.php tests/Unit/Services/Audit/AuditDeliveryServiceTest.php
git commit -m "refactor: restructure audit config to default + stack pattern"
```

---

## Task 11: Update `AppServiceProvider` bindings

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Update AuditDeliveryService binding**

Replace the existing binding with the new constructor signature:

```php
$this->app->bind(AuditDeliveryService::class, fn (): AuditDeliveryService => new AuditDeliveryService(
    runLog: $this->app->make(RunLogWriter::class),
    localAdapter: new LocalDeliveryAdapter,
    stdoutAdapter: new StdoutDeliveryAdapter,
    stderrAdapter: new StderrDeliveryAdapter,
    s3Adapter: new S3DeliveryAdapter,
    emailAdapter: new EmailDeliveryAdapter,
    teamsAdapter: new WebhookDeliveryAdapter(AuditChannelType::MsTeams),
    slackAdapter: new WebhookDeliveryAdapter(AuditChannelType::Slack),
    ntfyAdapter: new NtfyDeliveryAdapter,
));
```

Add the necessary `use` imports for the new adapter classes.

- [ ] **Step 2: Run tests**

```bash
composer test:unit
```

- [ ] **Step 3: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "refactor: register all delivery adapters in AppServiceProvider"
```

---

## Task 12: Update RunCommand Phase 7 for new audit config

**Files:**
- Modify: `app/Commands/Cloning/RunCommand.php` (Phase 7, lines 427–503)

- [ ] **Step 1: Simplify Phase 7 to use DI'd AuditDeliveryService**

Instead of manually instantiating adapters, inject `AuditDeliveryService` via `resolve()`:

```php
// ─── Phase 7: Audit ────────────────────────────────────────────────────
$cloningConfig = $configService->load();
$rawAuditConfig = $cloningConfig['audit'] ?? null;
/** @var array<string, mixed>|null $auditConfig */
$auditConfig = is_array($rawAuditConfig) ? $rawAuditConfig : null;

if ($auditConfig !== null || $auditChannels !== []) {
    if ($isVerbose) {
        $this->line('  <info>✓</info>  Generating audit log ...');
    }

    $signer = new AuditLogSigner;
    $builder = new AuditLogBuilder($signer);
    $renderer = new AuditLogRenderer;
    $deliveryService = resolve(AuditDeliveryService::class);

    // ... build artefacts (unchanged) ...

    $deliveryService->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: $auditArtefacts,
        processLogContent: $processLogContent,
        channelOverride: $auditChannels,
        templateVars: $templateVars,
    );

    if ($isVerbose) {
        $defaultChannel = $auditConfig['default'] ?? null;
        if (is_string($defaultChannel)) {
            $this->line(sprintf('  <info>✓</info>  Delivering via %s ...', $defaultChannel));
        }
    }
}
```

Remove the manual adapter imports that are no longer needed (keep `AuditLogBuilder`, `AuditLogRenderer`, `AuditLogSigner`).

- [ ] **Step 2: Update test fixtures**

In `tests/Feature/Commands/Cloning/RunCommandTest.php`, update any test that sets up audit config to use the new format:

```php
// Old:
'audit' => [
    'channels' => ['local' => ['type' => 'local', 'path' => './clonio-logs']],
    'audit_log' => ['deliver_to' => ['local']],
    'run_log' => ['deliver_to' => ['local']],
]

// New:
'audit' => [
    'default' => 'local',
    'channels' => ['local' => ['type' => 'local', 'path' => './clonio-logs']],
]
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/pest tests/Feature/Commands/Cloning/RunCommandTest.php -v
```

- [ ] **Step 4: Commit**

```bash
git add app/Commands/Cloning/RunCommand.php tests/Feature/Commands/Cloning/RunCommandTest.php
git commit -m "refactor: simplify RunCommand Phase 7 to use DI'd AuditDeliveryService"
```

---

## Task 13: Update InitCommand defaults

**Files:**
- Modify: `app/Commands/InitCommand.php:95-133`

- [ ] **Step 1: Update `ensureDefaultAuditChannels`**

```php
private function ensureDefaultAuditChannels(ConfigService $config): void
{
    $defaults = [
        'local' => [
            'type' => 'local',
            'path' => './',
        ],
        'stdout' => ['type' => 'stdout'],
        'stderr' => ['type' => 'stderr'],
    ];

    $created = [];

    foreach ($defaults as $name => $channelConfig) {
        if (! $config->hasAuditChannel($name)) {
            $config->setAuditChannel($name, $channelConfig);
            $created[] = $name;
        }
    }

    // Ensure default channel is set
    if ($config->getAuditDefault() === null) {
        $config->setAuditDefault('local');
    }

    if ($created !== []) {
        $this->info(sprintf(
            '  ✓  Created clonio.json with default audit channels: %s',
            implode(', ', $created),
        ));
    } else {
        $this->info('  ✓  Audit channels in clonio.json — ready.');
    }

    $this->line('');
}
```

Key changes: path simplified from `'./clonio-logs/{year}/{month}'` to `'./'`, replace `deliver_to` logic with `setAuditDefault('local')`.

- [ ] **Step 2: Run tests**

```bash
./vendor/bin/pest tests/Feature/Commands/InitCommandTest.php -v 2>/dev/null || ./vendor/bin/pest tests/ --filter="init" -v
```

- [ ] **Step 3: Commit**

```bash
git add app/Commands/InitCommand.php
git commit -m "refactor: simplify InitCommand defaults — audit.default + local path ./"
```

---

## Task 14: Update Audit CRUD commands

**Files:**
- Modify: `app/Commands/Audit/AddCommand.php`
- Modify: `app/Commands/Audit/UpdateCommand.php`
- Modify: `app/Commands/Audit/DeleteCommand.php`
- Modify: `app/Commands/Audit/ListCommand.php`

- [ ] **Step 1: Update AddCommand**

Key changes:
- Add `stack` type support: prompt for child channel names
- Replace `--deliver-audit-log` / `--deliver-run-log` flags with `--set-default` flag
- Remove `deliver_to` persistence logic
- Add `--set-default` option to signature and persist call

In `promptTypeFields`, add the `Stack` case:

```php
AuditChannelType::Stack => $this->promptStackFields($config),
```

New method:

```php
private function promptStackFields(ConfigService $config): array
{
    $existing = array_keys($config->getAuditChannels());
    if ($existing === []) {
        throw new RuntimeException('No channels exist yet. Add individual channels before creating a stack.');
    }

    $selected = [];
    $this->line('  Select channels to include in the stack (comma-separated names):');
    $this->line('  Available: '.implode(', ', $existing));
    $asked = $this->ask('Channels');
    if (is_string($asked)) {
        $selected = array_values(array_filter(array_map(trim(...), explode(',', $asked))));
    }

    return ['channels' => $selected];
}
```

Remove the deliver_to Steps 4–5 and related options from the signature. Add `{--set-default : Set this channel as audit.default}`. If `--set-default` is passed, call `$config->setAuditDefault($name)`.

- [ ] **Step 2: Update ListCommand**

Replace the `Audit Log` / `Run Log` columns with a single `Default` column:

```php
$default = $config->getAuditDefault();
// ...
$isDefault = $name === $default ? '★' : '';
$rows[] = [$name, $typeLabel, $isDefault, $details];
// ...
$this->table(['Name', 'Type', 'Default', 'Details'], $rows);
```

For stack channels, show child channels in Details:

```php
if ($type === AuditChannelType::Stack) {
    $children = is_array($channel['channels'] ?? null) ? $channel['channels'] : [];
    return implode(', ', array_filter($children, is_string(...)));
}
```

- [ ] **Step 3: Update DeleteCommand**

Replace deliver_to warning with default warning:

```php
$default = $config->getAuditDefault();
if ($name === $default) {
    $this->warn(sprintf("Channel '%s' is the current default. Deleting it will leave audit.default unset.", $name));
}
```

Remove `getAuditDeliverTo` references.

- [ ] **Step 4: Update UpdateCommand**

Remove the `deliver_to` membership section (Steps "Deliver-to membership" through `updateDeliverTo`). The update command only changes channel config, not routing.

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/pest tests/ --filter="audit" -v
```

- [ ] **Step 6: Commit**

```bash
git add app/Commands/Audit/
git commit -m "refactor: update audit CRUD commands for default + stack pattern"
```

---

## Task 15: Update documentation

**Files:**
- Modify: `docs/commands/cloning-run.md`
- Modify: `docs/commands/audit-channel.md`

- [ ] **Step 1: Update `cloning-run.md`**

Update the verbosity table:

```markdown
### Output Verbosity

| Level | Flag | Output |
|-------|------|--------|
| quiet | `-q` / `--ci` | No output, exit code only |
| normal | (default) | Dot indicators (`.FE?S`), 70 chars per line, summary |
| verbose | `-v` | One line per table with status and row count |
| very verbose | `-vv` | Live streaming of run log events to stderr |

**Dot indicators:**
- `.` — table transferred successfully
- `F` — table transferred with skipped rows
- `E` — table transfer failed
- `?` — table not found in source
- `S` — skipped due to schema replication failure
```

Update the audit config example to use the new format:

```json
"audit": {
    "default": "local",
    "channels": {
        "local": { "type": "local", "path": "./" }
    }
}
```

- [ ] **Step 2: Update `audit-channel.md`**

Add documentation for:
- The `stack` channel type and its `channels` property
- The `audit.default` key replacing `audit_log.deliver_to` / `run_log.deliver_to`
- Examples for all channel types (S3, Email, Teams, Slack, ntfy, stack)

- [ ] **Step 3: Commit**

```bash
git add docs/
git commit -m "docs: update verbosity levels and audit config documentation"
```

---

## Task 16: Run full test suite and fix issues

- [ ] **Step 1: Run PHPStan**

```bash
composer test:types
```

Fix any type errors introduced by the refactor.

- [ ] **Step 2: Run full test suite**

```bash
composer test
```

Fix any failures.

- [ ] **Step 3: Run lint**

```bash
composer lint
```

- [ ] **Step 4: Final commit if any fixes**

```bash
git add -A
git commit -m "fix: resolve PHPStan and test issues from logging/audit refactor"
```
