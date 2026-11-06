<?php

declare(strict_types=1);

namespace App\Commands\Audit;

use App\Commands\Concerns\PromptsForSecret;
use App\Enums\AuditChannelType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use App\Services\Secrets\Secret;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class AddCommand extends Command
{
    use PromptsForSecret;

    /**
     * @var string
     */
    protected $signature = 'audit:add
        {name?                      : Unique name for this channel}
        {--type=                    : Channel type — local|s3|email|ms_teams|slack|ntfy}
        {--local-path=              : (local) Path template for audit and run logs}
        {--s3-endpoint=             : (s3) S3-compatible endpoint URL}
        {--s3-bucket=               : (s3) Bucket name}
        {--s3-access-key=           : (s3) Access key ID}
        {--s3-secret-key=           : (s3) Secret access key, or env:NAME — stored encrypted}
        {--s3-region=               : (s3) Region string}
        {--s3-path-prefix=          : (s3) Key prefix template}
        {--mail-host=               : (email) SMTP host}
        {--mail-port=               : (email) SMTP port}
        {--mail-encryption=         : (email) SMTP encryption — tls|ssl|none}
        {--mail-username=           : (email) SMTP username}
        {--mail-password=           : (email) SMTP password, or env:NAME — stored encrypted}
        {--mail-from-address=       : (email) Sender address}
        {--mail-from-name=          : (email) Sender display name}
        {--mail-to=                 : (email) Comma-separated recipient addresses}
        {--webhook-url=             : (ms_teams|slack) Incoming webhook URL, or env:NAME — stored encrypted}
        {--ntfy-url=                : (ntfy) ntfy server base URL}
        {--ntfy-topic=              : (ntfy) ntfy topic}
        {--ntfy-token=              : (ntfy) ntfy bearer token, or env:NAME — stored encrypted}
        {--ntfy-priority=           : (ntfy) Notification priority — min|low|default|high|max}
        {--ntfy-tags=               : (ntfy) Comma-separated tag strings}
        {--enable                   : Add this channel to audit.use (active delivery list)}';

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
            $name = is_string($asked) ? $asked : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty
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
            $channelConfig = $this->promptTypeFields($name, $type, $config);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return ExitCode::ConfigError->value;
        }

        $channelConfig['type'] = $type->value;

        // ─── Step 4: Summary ───────────────────────────────────────────────────
        $this->renderSummary($name, $type, $channelConfig);

        // ─── Step 5: Confirm ───────────────────────────────────────────────────
        if (! $this->confirm('Save this channel?', true)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        // ─── Persist ───────────────────────────────────────────────────────────
        try {
            $config->setAuditChannel($name, $channelConfig);

            if ((bool) $this->option('enable')) { // @pest-mutate-ignore: RemoveBooleanCast
                $config->addAuditUse($name);
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
    private function promptTypeFields(string $name, AuditChannelType $type, ConfigService $config): array
    {
        return match ($type) {
            AuditChannelType::Local => $this->promptLocalFields(),
            AuditChannelType::S3 => $this->promptS3Fields($name),
            AuditChannelType::Email => $this->promptEmailFields($name),
            AuditChannelType::MsTeams => $this->promptWebhookFields($name, 'Teams', 'Create an incoming webhook in Teams under Apps → Incoming Webhook.'),
            AuditChannelType::Slack => $this->promptWebhookFields($name, 'Slack', 'Create an incoming webhook at api.slack.com → Your Apps → Incoming Webhooks.'),
            AuditChannelType::Ntfy => $this->promptNtfyFields($name),
            AuditChannelType::Stack => $this->promptStackFields($config),
            AuditChannelType::Stdout, AuditChannelType::Stderr => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function promptStackFields(ConfigService $config): array
    {
        $existing = array_keys($config->getAuditChannels());

        throw_if($existing === [], RuntimeException::class, 'No channels exist yet. Add individual channels before creating a stack.');

        $this->line('  Available channels: '.implode(', ', $existing));
        $asked = $this->ask('Channels to include (comma-separated)');
        $selected = is_string($asked) ? array_values(array_filter(array_map(trim(...), explode(',', $asked)))) : [];

        return ['channels' => $selected];
    }

    /**
     * @return array<string, mixed>
     */
    private function promptLocalFields(): array
    {
        $path = $this->optionOrAsk('local-path', 'Log path ('.self::TEMPLATE_HINT.')', './clonio-logs/{year}/{month}');

        return [
            'path' => $path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promptS3Fields(string $name): array
    {
        $endpoint = $this->optionOrAsk('s3-endpoint', 'Endpoint URL', '');
        $bucket = $this->optionOrAsk('s3-bucket', 'Bucket name', '');
        $region = $this->optionOrAsk('s3-region', 'Region', '');
        $accessKey = $this->optionOrAsk('s3-access-key', 'Access key', '');
        // Never null without a skip choice; the cast only narrows the type.
        $secretKey = (string) $this->secretOrOption('s3-secret-key', 'S3 secret key', $this->secretEnvName('audit', $name, 'secret_key')); // @pest-mutate-ignore: RemoveStringCast
        $pathPrefix = $this->optionOrAsk('s3-path-prefix', 'Path prefix ('.self::TEMPLATE_HINT.')', 'clonio/{year}/{month}/{source}/');

        $config = [
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'region' => $region,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
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
    private function promptEmailFields(string $name): array
    {
        $host = $this->optionOrAsk('mail-host', 'SMTP host', '');
        $portRaw = $this->optionOrAsk('mail-port', 'SMTP port', '587');
        $port = (int) $portRaw;
        $encOptions = ['tls', 'ssl', 'none'];
        $encOpt = $this->option('mail-encryption');
        $encryption = is_string($encOpt) && in_array($encOpt, $encOptions, true) ? $encOpt
            : $this->choice('Encryption', $encOptions, 0);
        $username = $this->optionOrAsk('mail-username', 'SMTP username', '');
        // Never null without a skip choice; the cast only narrows the type.
        $password = (string) $this->secretOrOption('mail-password', 'SMTP password', $this->secretEnvName('audit', $name, 'password')); // @pest-mutate-ignore: RemoveStringCast
        $fromAddress = $this->optionOrAsk('mail-from-address', 'From address', '');
        $fromName = $this->optionOrAsk('mail-from-name', 'From name', '');
        $toRaw = $this->optionOrAsk('mail-to', 'Recipients (comma-separated emails)', '');
        $to = array_values(array_filter(array_map(trim(...), explode(',', $toRaw))));

        $config = [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
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
    private function promptWebhookFields(string $name, string $providerLabel, string $hint): array
    {
        $this->line(sprintf('  <comment>%s</comment>', $hint));
        // Never null without a skip choice; the cast only narrows the type.
        $webhookUrl = (string) $this->secretOrOption('webhook-url', $providerLabel.' webhook URL', $this->secretEnvName('audit', $name, 'webhook_url')); // @pest-mutate-ignore: RemoveStringCast

        $config = [
            'webhook_url' => $webhookUrl,
        ];

        if ($this->confirm('Override retry settings?', false)) {
            $config['retry'] = $this->promptRetryFields(3, 500, 2, 10000);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function promptNtfyFields(string $name): array
    {
        $this->line('  <comment>Topics are public by default on ntfy.sh. Use a hard-to-guess topic or self-hosted server for sensitive environments.</comment>');
        $url = $this->optionOrAsk('ntfy-url', 'ntfy server URL', 'https://ntfy.sh');
        $topic = $this->optionOrAsk('ntfy-topic', 'Topic', '');
        $priorityOpts = ['min', 'low', 'default', 'high', 'max'];
        $priorityOpt = $this->option('ntfy-priority');
        $priority = is_string($priorityOpt) && in_array($priorityOpt, $priorityOpts, true) ? $priorityOpt
            : $this->choice('Priority', $priorityOpts, 2);
        $tagsRaw = $this->optionOrAsk('ntfy-tags', 'Tags (comma-separated, optional — press Enter to skip)', '');
        $tags = $tagsRaw !== '' ? array_values(array_filter(array_map(trim(...), explode(',', $tagsRaw)))) : []; // @pest-mutate-ignore: EmptyStringToNotEmpty

        $token = $this->secretOrOption('ntfy-token', 'ntfy bearer token', $this->secretEnvName('audit', $name, 'token'), 'No bearer token');

        $config = [
            'url' => $url,
            'topic' => $topic,
            'priority' => $priority,
        ];

        if ($tags !== []) {
            $config['tags'] = $tags;
        }

        if ($token !== null) {
            $config['token'] = $token;
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
        $answer = $this->ask($question, $default !== '' ? $default : null); // @pest-mutate-ignore: EmptyStringToNotEmpty

        return is_string($answer) ? $answer : $default;
    }

    /**
     * Warns that a secret passed as a flag may end up in shell history, unless
     * the flag is an `env:NAME` reference, which holds no secret.
     */
    private function warnAboutShellHistory(string $value, string $warning): void
    {
        if (! str_starts_with($value, Secret::ENV_PREFIX)) {
            $this->warn($warning);
        }
    }

    /**
     * Returns the value to store for a secret: from the --option flag when
     * given, otherwise from the env-first prompt. Null means "no secret" and
     * is only possible when $skipChoice is given.
     */
    private function secretOrOption(string $option, string $label, string $suggestedEnvName, ?string $skipChoice = null): ?string
    {
        $opt = $this->option($option);
        if (is_string($opt) && $opt !== '') {
            $this->warnAboutShellHistory($opt, sprintf('Passing --%s via CLI flag may expose it in shell history. Use --%s=env:NAME instead.', $option, $option));

            return Secret::protect($opt);
        }

        return $this->promptForSecret($label, $suggestedEnvName, $skipChoice);
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     */
    private function renderSummary(string $name, AuditChannelType $type, array $channelConfig): void
    {
        $rows = [
            ['Name', $name],
            ['Type', $type->label()],
        ];

        foreach ($channelConfig as $key => $value) {
            if ($key === 'type') {
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            if (is_string($value) && Secret::isEncrypted($value)) {
                $rows[] = [str_replace('_', ' ', $key), '••••••••'];
            } elseif (is_array($value)) {
                $rows[] = [str_replace('_', ' ', $key), implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value))]; // @pest-mutate-ignore: UnwrapArrayMap,EmptyStringToNotEmpty,UnwrapStrReplace
            } else {
                $rows[] = [str_replace('_', ' ', $key), is_scalar($value) ? (string) $value : '']; // @pest-mutate-ignore: RemoveStringCast,EmptyStringToNotEmpty
            }
        }

        $this->table(['Field', 'Value'], $rows);
    }
}
