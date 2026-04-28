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
