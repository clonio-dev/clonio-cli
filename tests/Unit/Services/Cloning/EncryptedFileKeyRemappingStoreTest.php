<?php

declare(strict_types=1);

use App\Services\Cloning\EncryptedFileKeyRemappingStore;

it('stores and retrieves a mapping for a single table', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    $store->storeTable('users', ['1' => '100001', '2' => '100002', '3' => '100003']);

    expect($store->lookup('users', '1'))->toBe('100001');
    expect($store->lookup('users', '2'))->toBe('100002');
    expect($store->lookup('users', '3'))->toBe('100003');

    $store->cleanup();
});

it('returns null for unknown old value', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    $store->storeTable('users', ['1' => '100001']);

    expect($store->lookup('users', '999'))->toBeNull();

    $store->cleanup();
});

it('returns null for unknown table', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    expect($store->lookup('nonexistent', '1'))->toBeNull();

    $store->cleanup();
});

it('loads only one table at a time, switching on demand', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    $store->storeTable('users', ['1' => 'u-1', '2' => 'u-2']);
    $store->storeTable('orders', ['10' => 'o-10', '20' => 'o-20']);

    // Look up from users
    expect($store->lookup('users', '1'))->toBe('u-1');

    // Look up from orders (triggers a table switch)
    expect($store->lookup('orders', '10'))->toBe('o-10');

    // Switch back to users
    expect($store->lookup('users', '2'))->toBe('u-2');

    $store->cleanup();
});

it('deletes temp files on cleanup', function (): void {
    $store = new EncryptedFileKeyRemappingStore;
    $store->storeTable('users', ['1' => '100001']);

    // Force a lookup so the file is at least known to exist
    $store->lookup('users', '1');

    // Get file paths via reflection before cleanup
    $reflection = new ReflectionProperty($store, 'tableFiles');
    $reflection->setAccessible(true);
    /** @var array<string, string> $files */
    $files = $reflection->getValue($store);

    expect($files)->not->toBeEmpty();

    foreach ($files as $tmpFile) {
        expect(file_exists($tmpFile))->toBeTrue();
    }

    $store->cleanup();

    foreach ($files as $tmpFile) {
        expect(file_exists($tmpFile))->toBeFalse();
    }
});

it('returns early when loadTable is called for the already-loaded table', function (): void {
    $store = new EncryptedFileKeyRemappingStore;
    $store->storeTable('users', ['1' => 'u-1']);

    $store->loadTable('users');
    // second call hits the already-loaded early return
    $store->loadTable('users');

    expect($store->lookup('users', '1'))->toBe('u-1');

    $store->cleanup();
});

it('returns null when the backing temp file is truncated below the IV length', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    $before = glob(sys_get_temp_dir().'/clonio-km-*') ?: [];
    $store->storeTable('users', ['1' => 'u-1']);
    $after = glob(sys_get_temp_dir().'/clonio-km-*') ?: [];

    $new = array_values(array_diff($after, $before));
    expect($new)->toHaveCount(1);

    // Truncate the file to fewer than IV_LENGTH (16) bytes
    file_put_contents($new[0], 'short');

    expect($store->lookup('users', '1'))->toBeNull();

    $store->cleanup();
});

it('handles empty mappings gracefully', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    $store->storeTable('empty_table', []);

    expect($store->lookup('empty_table', 'anything'))->toBeNull();

    $store->cleanup();
});
