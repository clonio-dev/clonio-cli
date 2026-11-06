<?php

declare(strict_types=1);

namespace App\Commands\Audit;

use App\Commands\Concerns\PromptsForSecret;
use App\Enums\AuditChannelType;
use App\Enums\ExitCode;
use App\Exceptions\SecretEncryptionException;
use App\Services\Config\ConfigService;
use App\Services\Secrets\Secret;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    use PromptsForSecret;

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
        try {
            $updated = $this->promptUpdatedFields($name, $type, $current);
        } catch (InvalidArgumentException|SecretEncryptionException $exception) {
            $this->error($exception->getMessage());

            return ExitCode::ConfigError->value;
        }

        $updated['type'] = $type->value;

        // Delivery flags are not prompted; keep hand-edited values from clonio.json.
        foreach (['delivers_audit', 'delivers_process_log'] as $key) {
            if (array_key_exists($key, $current)) {
                $updated[$key] = $current[$key];
            }
        }

        // ─── Diff ─────────────────────────────────────────────────────────────
        $this->showDiff($current, $updated);

        if (! $this->confirm('Save changes?', true)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        // ─── Persist ──────────────────────────────────────────────────────────
        try {
            $config->setAuditChannel($name, $updated);
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
    private function promptUpdatedFields(string $name, AuditChannelType $type, array $current): array
    {
        return match ($type) {
            AuditChannelType::Local => $this->updateLocalFields($current),
            AuditChannelType::S3 => $this->updateS3Fields($name, $current),
            AuditChannelType::Email => $this->updateEmailFields($name, $current),
            AuditChannelType::MsTeams,
            AuditChannelType::Slack => $this->updateWebhookFields($name, $current),
            AuditChannelType::Ntfy => $this->updateNtfyFields($name, $current),
            AuditChannelType::Stack => $this->updateStackFields($current),
            AuditChannelType::Stdout, AuditChannelType::Stderr => $current,
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateLocalFields(array $current): array
    {
        $currentPath = is_string($current['path'] ?? null) ? $current['path'] : './clonio-logs/{year}/{month}';

        $path = $this->askString('Log path', $currentPath);

        return [
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateS3Fields(string $name, array $current): array
    {
        $endpoint = $this->askString('Endpoint', is_string($current['endpoint'] ?? null) ? $current['endpoint'] : '');
        $bucket = $this->askString('Bucket', is_string($current['bucket'] ?? null) ? $current['bucket'] : '');
        $region = $this->askString('Region', is_string($current['region'] ?? null) ? $current['region'] : '');
        $accessKey = $this->askString('Access key', is_string($current['access_key'] ?? null) ? $current['access_key'] : '');
        $secretKey = $this->updateSecret('S3 secret key', $current['secret_key'] ?? '', $this->secretEnvName('audit', $name, 'secret_key'));
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
    private function updateEmailFields(string $name, array $current): array
    {
        $host = $this->askString('SMTP host', is_string($current['host'] ?? null) ? $current['host'] : '');
        $portVal = $current['port'] ?? null;
        $port = (int) $this->askString('SMTP port', is_int($portVal) ? (string) $portVal : '587');
        $encOptions = ['tls', 'ssl', 'none'];
        $currentEnc = is_string($current['encryption'] ?? null) ? $current['encryption'] : 'tls';
        $encIdx = (int) array_search($currentEnc, $encOptions, true);
        $encryption = $this->choice('Encryption', $encOptions, $encIdx);
        $username = $this->askString('Username', is_string($current['username'] ?? null) ? $current['username'] : '');
        $password = $this->updateSecret('SMTP password', $current['password'] ?? '', $this->secretEnvName('audit', $name, 'password'));
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
    private function updateWebhookFields(string $name, array $current): array
    {
        $webhookUrl = $this->updateSecret('webhook URL', $current['webhook_url'] ?? '', $this->secretEnvName('audit', $name, 'webhook_url'));
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
    private function updateNtfyFields(string $name, array $current): array
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
        $tags = $tagsRaw !== '' ? array_values(array_filter(array_map(trim(...), explode(',', $tagsRaw)))) : []; // @pest-mutate-ignore: EmptyStringToNotEmpty
        $token = $this->updateSecret('ntfy bearer token', $current['token'] ?? '', $this->secretEnvName('audit', $name, 'token'));

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

    /**
     * Asks how Clonio gets the secret; keeping the current value is the default.
     * An `env:` reference is shown as is, anything else is masked.
     */
    private function updateSecret(string $label, mixed $current, string $suggestedEnvName): string
    {
        $current = is_string($current) ? $current : '';
        $shown = match (true) {
            $current === '' => 'not set',
            str_starts_with($current, Secret::ENV_PREFIX) => $current,
            default => '••••••••',
        };

        $this->line(sprintf('  Current %s: <comment>%s</comment>', $label, $shown));
        $skipChoice = $current === '' ? 'Leave it empty' : 'Keep the current '.$label;

        return $this->promptForSecret($label, $suggestedEnvName, $skipChoice) ?? $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function updateStackFields(array $current): array
    {
        $currentChannels = is_array($current['channels'] ?? null) ? implode(', ', array_filter($current['channels'], is_string(...))) : '';
        $asked = $this->askString('Channels (comma-separated)', $currentChannels);
        $selected = array_values(array_filter(array_map(trim(...), explode(',', $asked))));

        return ['channels' => $selected];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function showDiff(array $old, array $new): void
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
            if (Secret::isEncrypted($oldStr)) {
                $oldStr = '••••••••';
            }

            if (Secret::isEncrypted($newStr)) {
                $newStr = '••••••••';
            }

            if ($oldStr !== $newStr) {
                $rows[] = [$key, $oldStr, $newStr];
            }
        }

        if ($rows === []) {
            $this->info('No fields changed.');

            return;
        }

        $this->table(['Field', 'Old', 'New'], $rows);
    }
}
