<?php

declare(strict_types=1);

use App\Services\Audit\LocalDeliveryAdapter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

it('delivers artefacts to local filesystem with template variable resolution', function (): void {
    Storage::fake('local');

    $adapter = new LocalDeliveryAdapter;

    $adapter->deliver(
        artefacts: ['audit.html' => '<html>test</html>', 'audit.sig' => 'sha256:abc123'],
        channelConfig: ['path' => 'audit-logs/{year}/{month}'],
        templateVars: ['year' => '2026', 'month' => '04', 'day' => '01', 'source' => 'prod', 'target' => 'staging'],
    );

    Storage::disk('local')->assertExists('audit-logs/2026/04/audit.html');
    Storage::disk('local')->assertExists('audit-logs/2026/04/audit.sig');

    expect(Storage::disk('local')->get('audit-logs/2026/04/audit.html'))->toBe('<html>test</html>');
    expect(Storage::disk('local')->get('audit-logs/2026/04/audit.sig'))->toBe('sha256:abc123');
});

it('delivers artefacts to path without template variables', function (): void {
    Storage::fake('local');

    $adapter = new LocalDeliveryAdapter;

    $adapter->deliver(
        artefacts: ['run.jsonl' => '{"event": "start"}'],
        channelConfig: ['path' => 'run-logs'],
        templateVars: [],
    );

    Storage::disk('local')->assertExists('run-logs/run.jsonl');
});

it('strips trailing slashes from the resolved path before writing', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->with('audit-logs/2026/run.jsonl', 'x');
    Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

    (new LocalDeliveryAdapter)->deliver(
        artefacts: ['run.jsonl' => 'x'],
        channelConfig: ['path' => 'audit-logs/{year}//'],
        templateVars: ['year' => '2026'],
    );
});
