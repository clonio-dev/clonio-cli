<?php

declare(strict_types=1);

namespace App\Commands\Audit;

use App\Enums\AuditChannelType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit:update
        {name? : Name of the channel to update}';

    /**
     * @var string
     */
    protected $description = 'Update an existing audit delivery channel';

    public function handle(ConfigService $config): int
    {
        $channels = $config->getAuditChannels();

        if ($channels === []) {
            $this->error('No audit channels configured. Run `audit:add` to add one.');

            return ExitCode::ConfigError->value;
        }

        // ─── Resolve name ──────────────────────────────────────────────────────
        $nameArg = $this->argument('name');
        $name = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($name === null) {
            $names = array_keys($channels);
            if (count($names) === 1) {
                $name = $names[0];
            } else {
                $selected = $this->choice('Which channel do you want to update?', $names);
                $name = is_string($selected) ? $selected : $names[0];
            }
        }

        $current = $config->getAuditChannel($name);
        if ($current === null) {
            $this->error(sprintf("Channel '%s' not found. Run `audit:list` to see available channels.", $name));

            return ExitCode::ValidationError->value;
        }

        /** @var array<string, mixed> $current */
        $typeValue = is_string($current['type'] ?? null) ? $current['type'] : '';
        $type = AuditChannelType::tryFrom($typeValue);

        if ($type === null) {
            $this->error(sprintf("Channel '%s' has unknown type '%s'.", $name, $typeValue));

            return ExitCode::ConfigError->value;
        }

        $this->line(sprintf('  Type: <info>%s</info> (cannot be changed — delete and re-add to change type)', $type->label()));

        // ─── Re-prompt fields ─────────────────────────────────────────────────
        $updated = $this->promptUpdatedFields($type, $current);
        $updated['type'] = $type->value;

        // ─── Deliver-to membership ────────────────────────────────────────────
        $auditDeliverTo = $config->getAuditDeliverTo('audit_log');
        $runDeliverTo = $config->getAuditDeliverTo('run_log');
        $inAuditLog = in_array($name, $auditDeliverTo, true);
        $inRunLog = in_array($name, $runDeliverTo, true);

        $newDeliverAuditLog = $this->confirm('Deliver audit log via this channel?', $inAuditLog);
        $newDeliverRunLog = $this->confirm('Deliver run log via this channel?', $inRunLog);

        // ─── Diff ─────────────────────────────────────────────────────────────
        $this->showDiff($current, $updated, $inAuditLog, $inRunLog, $newDeliverAuditLog, $newDeliverRunLog);

        if (! $this->confirm('Save changes?', true)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        // ─── Persist ──────────────────────────────────────────────────────────
        try {
            $config->setAuditChannel($name, $updated);
            $this->updateDeliverTo($config, $name, 'audit_log', $inAuditLog, $newDeliverAuditLog);
            $this->updateDeliverTo($config, $name, 'run_log', $inRunLog, $newDeliverRunLog);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf("Channel '%s' updated successfully.", $name));

        return ExitCode::Success->value;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function promptUpdatedFields(AuditChannelType $type, array $current): array
    {
        return match ($type) {
            AuditChannelType::Local => $this->updateLocalFields($current),
            AuditChannelType::S3 => $this->updateS3Fields($current),
            AuditChannelType::Email => $this->updateEmailFields($current),
            AuditChannelType::MsTeams,
            AuditChannelType::Slack => $this->updateWebhookFields($current),
            AuditChannelType::Ntfy => $this->updateNtfyFields($current),
            AuditChannelType::Stdout, AuditChannelType::Stderr => $current,
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateLocalFields(array $current): array
    {
        $auditLogSection = $current['audit_log'] ?? null;
        $auditLogPath2 = is_array($auditLogSection) ? ($auditLogSection['path'] ?? null) : null;
        $currentAuditPath = is_string($auditLogPath2) ? $auditLogPath2 : './audit-logs/{year}/{month}';

        $runLogSection = $current['run_log'] ?? null;
        $runLogPath2 = is_array($runLogSection) ? ($runLogSection['path'] ?? null) : null;
        $currentRunPath = is_string($runLogPath2) ? $runLogPath2 : './run-logs/{year}/{month}';

        $auditLogPath = $this->askString('Audit log path', $currentAuditPath);
        $runLogPath = $this->askString('Run log path', $currentRunPath);

        return [
            'audit_log' => ['path' => $auditLogPath],
            'run_log' => ['path' => $runLogPath],
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateS3Fields(array $current): array
    {
        $endpoint = $this->askString('Endpoint', is_string($current['endpoint'] ?? null) ? $current['endpoint'] : '');
        $bucket = $this->askString('Bucket', is_string($current['bucket'] ?? null) ? $current['bucket'] : '');
        $region = $this->askString('Region', is_string($current['region'] ?? null) ? $current['region'] : '');
        $accessKey = $this->askString('Access key', is_string($current['access_key'] ?? null) ? $current['access_key'] : '');
        $secretKey = $this->updateSecret('Secret key', $current['secret_key'] ?? '');
        $pathPrefix = $this->askString('Path prefix', is_string($current['path_prefix'] ?? null) ? $current['path_prefix'] : 'clonio/{year}/{month}/{source}/');

        $updated = [
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'region' => $region,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'path_prefix' => $pathPrefix,
        ];

        if (isset($current['retry']) && is_array($current['retry'])) {
            $updated['retry'] = $current['retry'];
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateEmailFields(array $current): array
    {
        $host = $this->askString('SMTP host', is_string($current['host'] ?? null) ? $current['host'] : '');
        $portVal = $current['port'] ?? null;
        $port = (int) $this->askString('SMTP port', is_int($portVal) ? (string) $portVal : '587');
        $encOptions = ['tls', 'ssl', 'none'];
        $currentEnc = is_string($current['encryption'] ?? null) ? $current['encryption'] : 'tls';
        $encIdx = (int) array_search($currentEnc, $encOptions, true);
        $encryption = $this->choice('Encryption', $encOptions, $encIdx);
        $username = $this->askString('Username', is_string($current['username'] ?? null) ? $current['username'] : '');
        $password = $this->updateSecret('Password', $current['password'] ?? '');
        $fromAddr = $this->askString('From address', is_string($current['from_address'] ?? null) ? $current['from_address'] : '');
        $fromName = $this->askString('From name', is_string($current['from_name'] ?? null) ? $current['from_name'] : '');
        $toRaw2 = $current['to'] ?? null;
        $currentTo = is_array($toRaw2) ? implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $toRaw2)) : '';
        $toRaw = $this->askString('Recipients (comma-separated)', $currentTo);
        $to = array_values(array_filter(array_map(trim(...), explode(',', $toRaw))));

        $updated = [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'from_address' => $fromAddr,
            'from_name' => $fromName,
            'to' => $to,
        ];

        if (isset($current['retry']) && is_array($current['retry'])) {
            $updated['retry'] = $current['retry'];
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateWebhookFields(array $current): array
    {
        $webhookUrl = $this->updateSecret('Webhook URL', $current['webhook_url'] ?? '');
        $updated = ['webhook_url' => $webhookUrl];

        if (isset($current['retry']) && is_array($current['retry'])) {
            $updated['retry'] = $current['retry'];
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateNtfyFields(array $current): array
    {
        $url = $this->askString('Server URL', is_string($current['url'] ?? null) ? $current['url'] : 'https://ntfy.sh');
        $topic = $this->askString('Topic', is_string($current['topic'] ?? null) ? $current['topic'] : '');
        $priorities = ['min', 'low', 'default', 'high', 'max'];
        $currentPriority = is_string($current['priority'] ?? null) ? $current['priority'] : 'default';
        $priorityIdx = (int) array_search($currentPriority, $priorities, true);
        $priority = $this->choice('Priority', $priorities, $priorityIdx);
        $tagsRaw2 = $current['tags'] ?? null;
        $currentTags = is_array($tagsRaw2) ? implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tagsRaw2)) : '';
        $tagsRaw = $this->askString('Tags (comma-separated, optional)', $currentTags);
        $tags = $tagsRaw !== '' ? array_values(array_filter(array_map(trim(...), explode(',', $tagsRaw)))) : [];
        $token = $this->updateSecret('Bearer token (optional — press Enter to keep/skip)', $current['token'] ?? '');

        $updated = [
            'url' => $url,
            'topic' => $topic,
            'priority' => $priority,
        ];

        if ($tags !== []) {
            $updated['tags'] = $tags;
        }

        if ($token !== '') {
            $updated['token'] = $token;
        }

        if (isset($current['retry']) && is_array($current['retry'])) {
            $updated['retry'] = $current['retry'];
        }

        return $updated;
    }

    private function askString(string $question, string $default): string
    {
        $answer = $this->ask($question, $default !== '' ? $default : null);

        return is_string($answer) ? $answer : $default;
    }

    private function updateSecret(string $label, mixed $current): string
    {
        $this->line(sprintf('  %s: <comment>••••••••</comment> (press Enter to keep)', $label));
        $asked = $this->secret($label.' (press Enter to keep)');
        if (! is_string($asked) || $asked === '') {
            return is_string($current) ? $current : '';
        }

        return 'encrypted:'.Crypt::encryptString($asked);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function showDiff(array $old, array $new, bool $oldAudit, bool $oldRun, bool $newAudit, bool $newRun): void
    {
        $rows = [];

        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));
        foreach ($allKeys as $key) {
            if ($key === 'type') {
                continue;
            }

            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;

            $oldStr = is_array($oldVal) ? (json_encode($oldVal) ?: '') : (is_scalar($oldVal) ? (string) $oldVal : '');
            $newStr = is_array($newVal) ? (json_encode($newVal) ?: '') : (is_scalar($newVal) ? (string) $newVal : '');
            if (str_starts_with($oldStr, 'encrypted:')) {
                $oldStr = '••••••••';
            }

            if (str_starts_with($newStr, 'encrypted:')) {
                $newStr = '••••••••';
            }

            if ($oldStr !== $newStr) {
                $rows[] = [$key, $oldStr, $newStr];
            }
        }

        if ($oldAudit !== $newAudit) {
            $rows[] = ['deliver_audit_log', $oldAudit ? 'yes' : 'no', $newAudit ? 'yes' : 'no'];
        }

        if ($oldRun !== $newRun) {
            $rows[] = ['deliver_run_log', $oldRun ? 'yes' : 'no', $newRun ? 'yes' : 'no'];
        }

        if ($rows === []) {
            $this->info('No fields changed.');

            return;
        }

        $this->table(['Field', 'Old', 'New'], $rows);
    }

    private function updateDeliverTo(ConfigService $config, string $name, string $logType, bool $was, bool $now): void
    {
        if ($was === $now) {
            return;
        }

        $current = $config->getAuditDeliverTo($logType);
        if ($now && ! in_array($name, $current, true)) {
            $config->setAuditDeliverTo($logType, array_merge($current, [$name]));
        } elseif (! $now && in_array($name, $current, true)) {
            $config->setAuditDeliverTo($logType, array_values(array_filter($current, static fn ($ch): bool => $ch !== $name)));
        }
    }
}
