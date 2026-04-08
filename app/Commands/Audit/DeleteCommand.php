<?php

declare(strict_types=1);

namespace App\Commands\Audit;

use App\Enums\AuditChannelType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DeleteCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit:delete
        {name?   : Name of the channel to delete}
        {--force : Skip confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Delete an audit delivery channel from clonio.json';

    public function handle(ConfigService $config): int
    {
        $channels = $config->getAuditChannels();

        if ($channels === []) {
            $this->error('No audit channels configured.');

            return ExitCode::ConfigError->value;
        }

        $nameArg = $this->argument('name');
        $name = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($name === null) {
            $names = array_keys($channels);
            if (count($names) === 1) {
                $name = $names[0];
            } else {
                $selected = $this->choice('Select a channel to delete', $names);
                $name = is_string($selected) ? $selected : $names[0];
            }
        }

        $channel = $config->getAuditChannel($name);
        if ($channel === null) {
            $this->error(sprintf("Channel '%s' not found. Run `audit:list` to see available channels.", $name));

            return ExitCode::ValidationError->value;
        }

        /** @var array<string, mixed> $channel */

        // Summary
        $typeValue = is_string($channel['type'] ?? null) ? $channel['type'] : '';
        $type = AuditChannelType::tryFrom($typeValue);
        $rows = [
            ['Name', $name],
            ['Type', $type?->label() ?? $typeValue],
        ];
        $this->table(['Field', 'Value'], $rows);

        // Warn if active in deliver_to lists
        $auditDeliverTo = $config->getAuditDeliverTo('audit_log');
        $runDeliverTo = $config->getAuditDeliverTo('run_log');
        $activeCount = (in_array($name, $auditDeliverTo, true) ? 1 : 0)
                        + (in_array($name, $runDeliverTo, true) ? 1 : 0);

        if ($activeCount > 0) {
            $this->warn(sprintf(
                "Channel '%s' is active in %d deliver_to list%s. Deleting it will remove it from all deliver_to entries.",
                $name,
                $activeCount,
                $activeCount === 1 ? '' : 's',
            ));
        }

        if (! $this->option('force') && ! $this->confirm(sprintf("Delete channel '%s'? This cannot be undone.", $name), false)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        try {
            $config->deleteAuditChannel($name);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf("Channel '%s' deleted.", $name));

        return ExitCode::Success->value;
    }
}
