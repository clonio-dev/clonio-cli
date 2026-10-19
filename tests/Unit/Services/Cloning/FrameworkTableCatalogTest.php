<?php

declare(strict_types=1);

use App\Services\Cloning\FrameworkTableCatalog;

it('matches known framework tables by exact name', function (string $table, string $group): void {
    expect((new FrameworkTableCatalog)->match($table))->toBe($group);
})->with([
    ['cache', 'Laravel cache'],
    ['sessions', 'Sessions'],
    ['failed_jobs', 'Laravel queue'],
    ['password_reset_tokens', 'Password reset tokens'],
    ['personal_access_tokens', 'API tokens'],
    ['oauth_refresh_tokens', 'OAuth tokens'],
    ['messenger_messages', 'Symfony Messenger'],
    ['django_session', 'Sessions'],
]);

it('matches framework tables by prefix', function (string $table, string $group): void {
    expect((new FrameworkTableCatalog)->match($table))->toBe($group);
})->with([
    ['telescope_entries', 'Laravel Telescope'],
    ['pulse_aggregates', 'Laravel Pulse'],
    ['solid_queue_jobs', 'Rails Solid Queue'],
]);

it('matches case-insensitively', function (): void {
    expect((new FrameworkTableCatalog)->match('Failed_Jobs'))->toBe('Laravel queue');
});

it('returns null for application tables', function (string $table): void {
    expect((new FrameworkTableCatalog)->match($table))->toBeNull();
})->with(['users', 'orders', 'cached_prices', 'job_offers']);
