<?php

declare(strict_types=1);

namespace App\Services\Cloning;

/**
 * Known framework tables whose rows are transient, secret or personal data
 * (cache, sessions, queues, tokens, debug logs). cloning:dump suggests the
 * 'schema' row strategy for them: the table is created, no rows are copied.
 */
final class FrameworkTableCatalog
{
    /** @var array<string, string> Exact table name => group label */
    private const array TABLES = [
        // Laravel
        'cache' => 'Laravel cache',
        'cache_locks' => 'Laravel cache',
        'sessions' => 'Sessions',
        'jobs' => 'Laravel queue',
        'job_batches' => 'Laravel queue',
        'failed_jobs' => 'Laravel queue',
        'password_reset_tokens' => 'Password reset tokens',
        'password_resets' => 'Password reset tokens',
        'personal_access_tokens' => 'API tokens',
        'oauth_access_tokens' => 'OAuth tokens',
        'oauth_refresh_tokens' => 'OAuth tokens',
        'oauth_auth_codes' => 'OAuth tokens',
        'oauth_device_codes' => 'OAuth tokens',
        'notifications' => 'Notifications',
        'activity_log' => 'Activity log',
        'webhook_calls' => 'Webhook calls',
        // Symfony
        'messenger_messages' => 'Symfony Messenger',
        // Django
        'django_session' => 'Sessions',
        // Rails
        'solid_cache_entries' => 'Rails Solid Cache',
        'solid_cable_messages' => 'Rails Solid Cable',
    ];

    /** @var array<string, string> Table name prefix => group label */
    private const array PREFIXES = [
        'telescope_' => 'Laravel Telescope',
        'pulse_' => 'Laravel Pulse',
        'solid_queue_' => 'Rails Solid Queue',
    ];

    /**
     * Group label when the table is a known framework table, null otherwise.
     */
    public function match(string $tableName): ?string
    {
        $name = strtolower($tableName);

        if (array_key_exists($name, self::TABLES)) {
            return self::TABLES[$name];
        }

        foreach (self::PREFIXES as $prefix => $label) {
            if (str_starts_with($name, $prefix)) {
                return $label;
            }
        }

        return null;
    }
}
