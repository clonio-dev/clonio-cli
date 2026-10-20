<?php

declare(strict_types=1);

use App\Data\Audit\AuditRecordData;
use App\Data\Cloning\CloningOptionsData;
use App\Services\Audit\AuditLogSigner;

function makeAuditRecord(): AuditRecordData
{
    return new AuditRecordData(
        clonioVersion: '1.0.0',
        sourceConnection: 'production-db',
        targetConnection: 'staging',
        yamlFileName: 'test.cloning.yaml',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        success: true,
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: true,
            fakerLocale: 'en_US',
        ),
        tables: [],
        totalRowsTransferred: 100,
        totalRowsSkipped: 0,
        channels: [],
        contentHash: '',
        hmacSignature: '',
        sourceConnectionDetails: ['name' => 'production-db', 'type' => 'mysql', 'host' => 'db.prod.io', 'port' => 3306, 'database' => 'mydb', 'schema' => null, 'username' => 'root'],
        targetConnectionDetails: ['name' => 'staging', 'type' => 'mysql', 'host' => 'db.staging.io', 'port' => 3306, 'database' => 'stagingdb', 'schema' => null, 'username' => 'root'],
    );
}

it('produces canonical JSON, content hash, and HMAC signature', function (): void {
    $signer = new AuditLogSigner;
    $record = makeAuditRecord();

    [$canonicalJson, $contentHash, $hmacSignature] = $signer->sign($record);

    expect($canonicalJson)->toBeString();
    expect($contentHash)->toHaveLength(64); // sha256 hex = 64 chars
    expect($hmacSignature)->toHaveLength(64);

    // Verify the hash is consistent
    expect(hash('sha256', $canonicalJson))->toBe($contentHash);
});

it('produces the same signature for the same record', function (): void {
    $signer = new AuditLogSigner;
    $record = makeAuditRecord();

    [, $hash1, $sig1] = $signer->sign($record);
    [, $hash2, $sig2] = $signer->sign($record);

    expect($hash1)->toBe($hash2);
    expect($sig1)->toBe($sig2);
});

it('canonical JSON contains expected fields', function (): void {
    $signer = new AuditLogSigner;
    $record = makeAuditRecord();

    [$canonicalJson] = $signer->sign($record);

    $decoded = json_decode($canonicalJson, true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('source_connection');
    expect($decoded)->toHaveKey('target_connection');
    expect($decoded)->toHaveKey('success');
    expect($decoded['source_connection'])->toBe('production-db');
});

it('produces the exact sorted, pretty-printed, unescaped canonical JSON and its HMAC', function (): void {
    config(['app.key' => 'g7-signing-key']);

    $base = makeAuditRecord();
    $record = new AuditRecordData(
        clonioVersion: '1.2.3',
        sourceConnection: 'prod/ü',
        targetConnection: 'staging',
        yamlFileName: 'dir/clone.yaml',
        startedAt: $base->startedAt,
        finishedAt: $base->finishedAt,
        success: false,
        options: $base->options,
        tables: [],
        totalRowsTransferred: 42,
        totalRowsSkipped: 3,
        channels: [],
        contentHash: '',
        hmacSignature: '',
        sourceConnectionDetails: $base->sourceConnectionDetails,
        targetConnectionDetails: $base->targetConnectionDetails,
    );

    [$canonicalJson, $contentHash, $hmacSignature] = (new AuditLogSigner)->sign($record);

    $expectedJson = <<<'JSON'
{
    "clonio_version": "1.2.3",
    "finished_at": "2026-04-01T14:34:14+00:00",
    "source_connection": "prod/ü",
    "started_at": "2026-04-01T14:32:00+00:00",
    "success": false,
    "target_connection": "staging",
    "total_rows_skipped": 3,
    "total_rows_transferred": 42,
    "yaml_file_name": "dir/clone.yaml"
}
JSON;

    expect($canonicalJson)->toBe($expectedJson)
        ->and($contentHash)->toBe(hash('sha256', $expectedJson))
        ->and($hmacSignature)->toBe(hash_hmac('sha256', hash('sha256', $expectedJson), 'g7-signing-key'));
});

it('signs with an empty key when the app key is not a string', function (): void {
    config(['app.key' => null]);

    [, $contentHash, $hmacSignature] = (new AuditLogSigner)->sign(makeAuditRecord());

    expect($hmacSignature)->toBe(hash_hmac('sha256', $contentHash, ''));
});

it('falls back to an empty canonical JSON when the record cannot be encoded', function (): void {
    config(['app.key' => 'g7-signing-key']);

    $base = makeAuditRecord();
    $record = new AuditRecordData(
        clonioVersion: '1.0.0',
        sourceConnection: "\xB1\x31",
        targetConnection: 'staging',
        yamlFileName: 'x.yaml',
        startedAt: $base->startedAt,
        finishedAt: $base->finishedAt,
        success: true,
        options: $base->options,
        tables: [],
        totalRowsTransferred: 0,
        totalRowsSkipped: 0,
        channels: [],
        contentHash: '',
        hmacSignature: '',
        sourceConnectionDetails: $base->sourceConnectionDetails,
        targetConnectionDetails: $base->targetConnectionDetails,
    );

    [$canonicalJson, $contentHash] = (new AuditLogSigner)->sign($record);

    expect($canonicalJson)->toBe('')
        ->and($contentHash)->toBe(hash('sha256', ''));
});
