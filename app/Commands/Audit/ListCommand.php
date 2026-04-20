<?php

declare(strict_types=1);

namespace App\Commands\Audit;

use App\Enums\AuditChannelType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit:list';

    /**
     * @var string
     */
    protected $description = 'List all configured audit delivery channels';

    public function handle(ConfigService $config): int
    {
        $channels = $config->getAuditChannels();
        $default = $config->getAuditDefault();

        if ($channels === []) {
            $this->line('No audit channels configured. Run `audit:add` to add one.');

            return ExitCode::Success->value;
        }

        $rows = [];

        foreach ($channels as $name => $channel) {
            $typeValue = is_string($channel['type'] ?? null) ? $channel['type'] : '';
            $type = AuditChannelType::tryFrom($typeValue);
            $typeLabel = $type?->label() ?? $typeValue;

            $isDefault = ($name === $default) ? '★' : '';

            $details = $this->getDetails($type, $channel);

            $rows[] = [$name, $typeLabel, $isDefault, $details];
        }

        $this->table(['Name', 'Type', 'Default', 'Details'], $rows);

        return ExitCode::Success->value;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function getDetails(?AuditChannelType $type, array $channel): string
    {
        if ($type === AuditChannelType::Local) {
            $path = is_string($channel['path'] ?? null) ? $channel['path'] : null;

            return is_string($path) ? $path : '—';
        }

        if ($type === AuditChannelType::S3) {
            $bucket = is_string($channel['bucket'] ?? null) ? $channel['bucket'] : null;
            $pathPrefix = is_string($channel['path_prefix'] ?? null) ? $channel['path_prefix'] : null;
            if ($bucket !== null && $pathPrefix !== null) {
                return sprintf('s3://%s/%s', $bucket, ltrim($pathPrefix, '/'));
            }

            return $bucket !== null ? 's3://'.$bucket : '—';
        }

        if ($type === AuditChannelType::Email) {
            $to = $channel['to'] ?? null;
            $first = is_array($to) ? ($to[0] ?? null) : null;

            return is_string($first) ? $first : '—';
        }

        if ($type === AuditChannelType::MsTeams || $type === AuditChannelType::Slack) {
            return '(webhook — encrypted)';
        }

        if ($type === AuditChannelType::Ntfy) {
            $url = is_string($channel['url'] ?? null) ? $channel['url'] : null;
            $topic = is_string($channel['topic'] ?? null) ? $channel['topic'] : null;

            return $url !== null && $topic !== null
                ? sprintf('%s / %s', rtrim($url, '/'), $topic)
                : '—';
        }

        if ($type === AuditChannelType::Stack) {
            $children = is_array($channel['channels'] ?? null) ? $channel['channels'] : [];

            return implode(', ', array_filter($children, is_string(...)));
        }

        return '—';
    }
}
