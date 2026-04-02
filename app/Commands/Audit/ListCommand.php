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
        $auditDeliverTo = $config->getAuditDeliverTo('audit_log');
        $runDeliverTo = $config->getAuditDeliverTo('run_log');

        if ($channels === []) {
            $this->line('No audit channels configured. Run `audit:add` to add one.');

            return ExitCode::Success->value;
        }

        $rows = [];

        foreach ($channels as $name => $channel) {
            $typeValue = is_string($channel['type'] ?? null) ? $channel['type'] : '';
            $type = AuditChannelType::tryFrom($typeValue);
            $typeLabel = $type?->label() ?? $typeValue;

            $inAuditLog = in_array($name, $auditDeliverTo, true) ? '✓' : '✗';
            $inRunLog = in_array($name, $runDeliverTo, true) ? '✓' : '✗';

            $details = $this->getDetails($type, $channel);

            $rows[] = [$name, $typeLabel, $inAuditLog, $inRunLog, $details];
        }

        $this->table(['Name', 'Type', 'Audit Log', 'Run Log', 'Details'], $rows);

        return ExitCode::Success->value;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function getDetails(?AuditChannelType $type, array $channel): string
    {
        if ($type === AuditChannelType::Local) {
            $auditLog = $channel['audit_log'] ?? null;
            $path = is_array($auditLog) ? ($auditLog['path'] ?? null) : null;

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

        return '—';
    }
}
