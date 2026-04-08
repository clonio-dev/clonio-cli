<?php

declare(strict_types=1);

namespace App\Commands\Audit;

use App\Enums\AuditChannelType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class AddCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit:add
        {name?                      : Unique name for this channel}
        {--type=                    : Channel type — local|s3|email|ms_teams|slack|ntfy}
        {--local-audit-log-path=    : (local) Audit log path template}
        {--local-run-log-path=      : (local) Run log path template}
        {--s3-endpoint=             : (s3) S3-compatible endpoint URL}
        {--s3-bucket=               : (s3) Bucket name}
        {--s3-access-key=           : (s3) Access key ID}
        {--s3-secret-key=           : (s3) Secret access key — stored encrypted}
        {--s3-region=               : (s3) Region string}
        {--s3-path-prefix=          : (s3) Key prefix template}
        {--mail-host=               : (email) SMTP host}
        {--mail-port=               : (email) SMTP port}
        {--mail-encryption=         : (email) SMTP encryption — tls|ssl|none}
        {--mail-username=           : (email) SMTP username}
        {--mail-password=           : (email) SMTP password — stored encrypted}
        {--mail-from-address=       : (email) Sender address}
        {--mail-from-name=          : (email) Sender display name}
        {--mail-to=                 : (email) Comma-separated recipient addresses}
        {--webhook-url=             : (ms_teams|slack) Incoming webhook URL — stored encrypted}
        {--ntfy-url=                : (ntfy) ntfy server base URL}
        {--ntfy-topic=              : (ntfy) ntfy topic}
        {--ntfy-token=              : (ntfy) ntfy bearer token — stored encrypted}
        {--ntfy-priority=           : (ntfy) Notification priority — min|low|default|high|max}
        {--ntfy-tags=               : (ntfy) Comma-separated tag strings}
        {--deliver-audit-log        : Add channel to audit_log.deliver_to}
        {--no-deliver-audit-log     : Do not add channel to audit_log.deliver_to}
        {--deliver-run-log          : Add channel to run_log.deliver_to}
        {--no-deliver-run-log       : Do not add channel to run_log.deliver_to}';

    /**
     * @var string
     */
    protected $description = 'Add a new audit delivery channel to clonio.json';

    private const string TEMPLATE_HINT = 'Supports: {year} {month} {day} {source} {target} {timestamp}';

    public function handle(ConfigService $config): int
    {
        if (! $config->exists()) {
            $this->error('clonio.json not found. Run `clonio init` first.');

            return ExitCode::ConfigError->value;
        }

        // ─── Step 1: Name ──────────────────────────────────────────────────────
        $nameArg = $this->argument('name');
        $name = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($name === null) {
            $asked = $this->ask('Channel name');
            $name = is_string($asked) ? $asked : '';
        }

        if (! preg_match('/^[a-z0-9_-]+$/', $name) || strlen($name) > 64) {
            $this->error('Invalid channel name. Use only lowercase letters, numbers, dashes, and underscores (max 64 chars).');

            return ExitCode::ValidationError->value;
        }

        if ($config->hasAuditChannel($name)) {
            $this->error(sprintf("A channel named '%s' already exists. Use `audit:update %s` to modify it.", $name, $name));

            return ExitCode::ValidationError->value;
        }

        // ─── Step 2: Type ──────────────────────────────────────────────────────
        $typeOption = $this->option('type');
        $typeValue = is_string($typeOption) && $typeOption !== '' ? $typeOption : null;

        if ($typeValue === null) {
            $selected = $this->choice('Channel type', AuditChannelType::labels());
            $typeValue = is_string($selected) ? AuditChannelType::fromLabel($selected)->value : AuditChannelType::Local->value;
        }

        $type = AuditChannelType::tryFrom($typeValue);
        if ($type === null) {
            $this->error(sprintf("Unknown channel type: '%s'. Valid types: %s", $typeValue, implode(', ', AuditChannelType::values())));

            return ExitCode::ValidationError->value;
        }

        // ─── Step 3: Type-specific fields ─────────────────────────────────────
        try {
            $channelConfig = $this->promptTypeFields($type);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return ExitCode::ConfigError->value;
        }

        $channelConfig['type'] = $type->value;

        // ─── Step 4: Deliver audit log? ────────────────────────────────────────
        $deliverAuditLog = $this->option('deliver-audit-log') ? true : ($this->option('no-deliver-audit-log') ? false : null);
        if ($deliverAuditLog === null) {
            $deliverAuditLog = $this->confirm('Deliver audit log via this channel?', true);
        }

        // ─── Step 5: Deliver run log? ──────────────────────────────────────────
        $deliverRunLog = $this->option('deliver-run-log') ? true : ($this->option('no-deliver-run-log') ? false : null);
        if ($deliverRunLog === null) {
            $deliverRunLog = $this->confirm('Deliver run log via this channel?', $type->defaultDeliversRunLog());
        }

        // ─── Step 6: Summary ───────────────────────────────────────────────────
        $this->renderSummary($name, $type, $channelConfig, $deliverAuditLog, $deliverRunLog);

        // ─── Step 7: Confirm ───────────────────────────────────────────────────
        if (! $this->confirm('Save this channel?', true)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        // ─── Persist ───────────────────────────────────────────────────────────
        try {
            $config->setAuditChannel($name, $channelConfig);

            if ($deliverAuditLog) {
                $current = $config->getAuditDeliverTo('audit_log');
                if (! in_array($name, $current, true)) {
                    $config->setAuditDeliverTo('audit_log', array_merge($current, [$name]));
                }
            }

            if ($deliverRunLog) {
                $current = $config->getAuditDeliverTo('run_log');
                if (! in_array($name, $current, true)) {
                    $config->setAuditDeliverTo('run_log', array_merge($current, [$name]));
                }
            }
        } catch (RuntimeException $runtimeException) {
            $this->error('Failed to save channel: '.$runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf("Channel '%s' added successfully.", $name));

        return ExitCode::Success->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function promptTypeFields(AuditChannelType $type): array
    {
        return match ($type) {
            AuditChannelType::Local => $this->promptLocalFields(),
            AuditChannelType::S3 => $this->promptS3Fields(),
            AuditChannelType::Email => $this->promptEmailFields(),
            AuditChannelType::MsTeams => $this->promptWebhookFields('Teams', 'Create an incoming webhook in Teams under Apps → Incoming Webhook.'),
            AuditChannelType::Slack => $this->promptWebhookFields('Slack', 'Create an incoming webhook at api.slack.com → Your Apps → Incoming Webhooks.'),
            AuditChannelType::Ntfy => $this->promptNtfyFields(),
            AuditChannelType::Stdout, AuditChannelType::Stderr => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function promptLocalFields(): array
    {
        $auditLogPath = $this->optionOrAsk('local-audit-log-path', 'Audit log path ('.self::TEMPLATE_HINT.')', './audit-logs/{year}/{month}');
        $runLogPath = $this->optionOrAsk('local-run-log-path', 'Run log path ('.self::TEMPLATE_HINT.')', './run-logs/{year}/{month}');

        return [
            'audit_log' => ['path' => $auditLogPath],
            'run_log' => ['path' => $runLogPath],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promptS3Fields(): array
    {
        $endpoint = $this->optionOrAsk('s3-endpoint', 'Endpoint URL', '');
        $bucket = $this->optionOrAsk('s3-bucket', 'Bucket name', '');
        $region = $this->optionOrAsk('s3-region', 'Region', '');
        $accessKey = $this->optionOrAsk('s3-access-key', 'Access key', '');
        $secretKey = $this->secretOrOption('s3-secret-key', 'Secret key');
        $pathPrefix = $this->optionOrAsk('s3-path-prefix', 'Path prefix ('.self::TEMPLATE_HINT.')', 'clonio/{year}/{month}/{source}/');

        $config = [
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'region' => $region,
            'access_key' => $accessKey,
            'secret_key' => 'encrypted:'.Crypt::encryptString($secretKey),
            'path_prefix' => $pathPrefix,
        ];

        if ($this->confirm('Override retry settings?', false)) {
            $config['retry'] = $this->promptRetryFields(5, 1000, 2, 30000);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function promptEmailFields(): array
    {
        $host = $this->optionOrAsk('mail-host', 'SMTP host', '');
        $portRaw = $this->optionOrAsk('mail-port', 'SMTP port', '587');
        $port = (int) $portRaw;
        $encOptions = ['tls', 'ssl', 'none'];
        $encOpt = $this->option('mail-encryption');
        $encryption = is_string($encOpt) && in_array($encOpt, $encOptions, true) ? $encOpt
            : $this->choice('Encryption', $encOptions, 0);
        $username = $this->optionOrAsk('mail-username', 'SMTP username', '');
        $password = $this->secretOrOption('mail-password', 'SMTP password');
        $fromAddress = $this->optionOrAsk('mail-from-address', 'From address', '');
        $fromName = $this->optionOrAsk('mail-from-name', 'From name', '');
        $toRaw = $this->optionOrAsk('mail-to', 'Recipients (comma-separated emails)', '');
        $to = array_values(array_filter(array_map(trim(...), explode(',', $toRaw))));

        $config = [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => 'encrypted:'.Crypt::encryptString($password),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'to' => $to,
        ];

        if ($this->confirm('Override retry settings?', false)) {
            $config['retry'] = $this->promptRetryFields(3, 500, 2, 10000);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function promptWebhookFields(string $providerLabel, string $hint): array
    {
        $this->line(sprintf('  <comment>%s</comment>', $hint));
        $webhookUrlOpt = $this->option('webhook-url');
        if (is_string($webhookUrlOpt) && $webhookUrlOpt !== '') {
            $this->warn('Passing webhook URL via CLI flag may expose it in shell history.');
            $webhookUrl = $webhookUrlOpt;
        } else {
            $asked = $this->secret($providerLabel.' webhook URL');
            $webhookUrl = is_string($asked) ? $asked : '';
        }

        $config = [
            'webhook_url' => 'encrypted:'.Crypt::encryptString($webhookUrl),
        ];

        if ($this->confirm('Override retry settings?', false)) {
            $config['retry'] = $this->promptRetryFields(3, 500, 2, 10000);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function promptNtfyFields(): array
    {
        $this->line('  <comment>Topics are public by default on ntfy.sh. Use a hard-to-guess topic or self-hosted server for sensitive environments.</comment>');
        $url = $this->optionOrAsk('ntfy-url', 'ntfy server URL', 'https://ntfy.sh');
        $topic = $this->optionOrAsk('ntfy-topic', 'Topic', '');
        $priorityOpts = ['min', 'low', 'default', 'high', 'max'];
        $priorityOpt = $this->option('ntfy-priority');
        $priority = is_string($priorityOpt) && in_array($priorityOpt, $priorityOpts, true) ? $priorityOpt
            : $this->choice('Priority', $priorityOpts, 2);
        $tagsRaw = $this->optionOrAsk('ntfy-tags', 'Tags (comma-separated, optional — press Enter to skip)', '');
        $tags = $tagsRaw !== '' ? array_values(array_filter(array_map(trim(...), explode(',', $tagsRaw)))) : [];

        $tokenOpt = $this->option('ntfy-token');
        $token = null;
        if (is_string($tokenOpt) && $tokenOpt !== '') {
            $this->warn('Passing token via CLI flag may expose it in shell history.');
            $token = $tokenOpt;
        } else {
            $asked = $this->secret('Bearer token (optional — press Enter to skip)');
            $token = is_string($asked) && $asked !== '' ? $asked : null;
        }

        $config = [
            'url' => $url,
            'topic' => $topic,
            'priority' => $priority,
        ];

        if ($tags !== []) {
            $config['tags'] = $tags;
        }

        if ($token !== null) {
            $config['token'] = 'encrypted:'.Crypt::encryptString($token);
        }

        if ($this->confirm('Override retry settings?', false)) {
            $config['retry'] = $this->promptRetryFields(3, 500, 2, 10000);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function promptRetryFields(int $maxAttempts, int $initialDelayMs, int $multiplier, int $maxDelayMs): array
    {
        $maxAttempts = (int) $this->askString('Max attempts', (string) $maxAttempts);
        $initialDelay = (int) $this->askString('Initial delay (ms)', (string) $initialDelayMs);
        $backoffMult = (float) $this->askString('Backoff multiplier', (string) $multiplier);
        $maxDelay = (int) $this->askString('Max delay (ms)', (string) $maxDelayMs);

        return [
            'max_attempts' => $maxAttempts,
            'initial_delay_ms' => $initialDelay,
            'backoff_multiplier' => $backoffMult,
            'max_delay_ms' => $maxDelay,
        ];
    }

    private function optionOrAsk(string $option, string $question, string $default): string
    {
        $opt = $this->option($option);
        if (is_string($opt) && $opt !== '') {
            return $opt;
        }

        $asked = $this->ask($question, $default !== '' ? $default : null);

        return is_string($asked) ? $asked : $default;
    }

    private function askString(string $question, string $default): string
    {
        $answer = $this->ask($question, $default !== '' ? $default : null);

        return is_string($answer) ? $answer : $default;
    }

    private function secretOrOption(string $option, string $label): string
    {
        $opt = $this->option($option);
        if (is_string($opt) && $opt !== '') {
            $this->warn(sprintf('Passing --%s via CLI flag may expose it in shell history.', $option));

            return $opt;
        }

        $asked = $this->secret($label);

        return is_string($asked) ? $asked : '';
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     */
    private function renderSummary(string $name, AuditChannelType $type, array $channelConfig, bool $deliverAuditLog, bool $deliverRunLog): void
    {
        $rows = [
            ['Name', $name],
            ['Type', $type->label()],
        ];

        foreach ($channelConfig as $key => $value) {
            if ($key === 'type') {
                continue;
            }

            if (is_string($value) && str_starts_with($value, 'encrypted:')) {
                $rows[] = [str_replace('_', ' ', $key), '••••••••'];
            } elseif (is_array($value)) {
                $rows[] = [str_replace('_', ' ', $key), implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value))];
            } else {
                $rows[] = [str_replace('_', ' ', $key), is_scalar($value) ? (string) $value : ''];
            }
        }

        $rows[] = ['Deliver audit log', $deliverAuditLog ? 'Yes' : 'No'];
        $rows[] = ['Deliver run log', $deliverRunLog ? 'Yes' : 'No'];

        $this->table(['Field', 'Value'], $rows);
    }
}
