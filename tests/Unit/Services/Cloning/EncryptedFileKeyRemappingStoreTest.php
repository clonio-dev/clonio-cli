<?php

declare(strict_types=1);

use App\Services\Cloning\EncryptedFileKeyRemappingStore;
use Symfony\Component\Process\Process;

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

/**
 * Runs a PHP snippet in a separate process with the composer autoloader loaded.
 * When Pest mutation testing is active, the mutated source is wired in the same way
 * Pest does it in its own worker processes.
 *
 * @param  list<string>  $phpOptions
 */
function g3_run_store_subprocess(string $body, array $phpOptions = []): Process
{
    // Exercise the same code path in-process as well: Pest's --covered-only mutation
    // run selects tests by in-process coverage, which cannot see the child process.
    $inProcess = new EncryptedFileKeyRemappingStore;
    $inProcess->storeTable('warmup', ['1' => '1']);
    $inProcess->cleanup();

    $script = sys_get_temp_dir().'/g3-store-'.uniqid().'.php';
    $autoload = var_export(base_path('vendor/autoload.php'), true);

    file_put_contents($script, <<<PHP
        <?php
        require {$autoload};
        if ((\$orig = getenv('PEST_MUTATION_TESTING')) !== false) {
            \\Pest\\Mutate\\Support\\StreamWrapper::start(\$orig, (string) getenv('PEST_MUTATION_FILE'));
        }
        {$body}
        PHP);

    $process = new Process([PHP_BINARY, ...$phpOptions, $script]);
    $process->run();

    @unlink($script);

    return $process;
}

it('generates a 32-byte per-run encryption key', function (): void {
    $store = new EncryptedFileKeyRemappingStore;

    $key = (new ReflectionProperty($store, 'key'))->getValue($store);

    expect(strlen((string) $key))->toBe(32);

    $store->cleanup();
});

it('creates temp files readable by the owner only', function (): void {
    $store = new EncryptedFileKeyRemappingStore;
    $store->storeTable('users', ['1' => 'u-1']);

    /** @var array<string, string> $files */
    $files = (new ReflectionProperty($store, 'tableFiles'))->getValue($store);

    expect(fileperms($files['users']) & 0777)->toBe(0600);

    $store->cleanup();
});

it('does not reload an already-loaded table from disk', function (): void {
    $store = new EncryptedFileKeyRemappingStore;
    $store->storeTable('users', ['1' => 'u-1']);
    $store->loadTable('users');

    /** @var array<string, string> $files */
    $files = (new ReflectionProperty($store, 'tableFiles'))->getValue($store);
    unlink($files['users']);

    $store->loadTable('users');

    expect($store->lookup('users', '1'))->toBe('u-1');

    $store->cleanup();
});

it('returns null when the temp file cannot be decrypted', function (): void {
    $store = new EncryptedFileKeyRemappingStore;
    $store->storeTable('users', ['1' => 'u-1']);

    /** @var array<string, string> $files */
    $files = (new ReflectionProperty($store, 'tableFiles'))->getValue($store);
    file_put_contents($files['users'], str_repeat("\0", 16).'garbage-that-is-not-valid-ciphertext!!');

    expect($store->lookup('users', '1'))->toBeNull();

    $store->cleanup();
});

it('resets to its initial state on cleanup', function (): void {
    $fresh = new EncryptedFileKeyRemappingStore;
    $store = new EncryptedFileKeyRemappingStore;
    $store->storeTable('users', ['1' => 'u-1']);
    $store->lookup('users', '1');

    $store->cleanup();

    foreach (['tableFiles', 'loadedTable', 'loadedMapping'] as $property) {
        $reflection = new ReflectionProperty(EncryptedFileKeyRemappingStore::class, $property);
        expect($reflection->getValue($store))->toBe($reflection->getValue($fresh));
    }

    $fresh->cleanup();
});

it('deletes temp files when the process shuts down without explicit cleanup', function (): void {
    $process = g3_run_store_subprocess(<<<'PHP'
        $store = new App\Services\Cloning\EncryptedFileKeyRemappingStore;
        $store->storeTable('users', ['1' => 'u-1']);
        $files = (new ReflectionProperty($store, 'tableFiles'))->getValue($store);
        echo $files['users'];
        PHP);

    $path = trim($process->getOutput());

    expect($process->getExitCode())->toBe(0)
        ->and($path)->toContain('clonio-km-')
        ->and(file_exists($path))->toBeFalse();

    @unlink($path);
});

it('deletes temp files and exits with code 1 on termination signals', function (string $signal): void {
    $process = g3_run_store_subprocess(<<<PHP
        pcntl_async_signals(true);
        \$store = new App\\Services\\Cloning\\EncryptedFileKeyRemappingStore;
        \$store->storeTable('users', ['1' => 'u-1']);
        \$files = (new ReflectionProperty(\$store, 'tableFiles'))->getValue(\$store);
        echo \$files['users'];
        posix_kill(getmypid(), {$signal});
        usleep(500000);
        echo ' not-terminated';
        PHP);

    $path = trim($process->getOutput());

    expect($process->getExitCode())->toBe(1)
        ->and($path)->not->toContain('not-terminated')
        ->and(file_exists($path))->toBeFalse();

    @unlink($path);
})->with(['SIGTERM', 'SIGINT'])->skip(! function_exists('pcntl_signal'), 'pcntl extension not available');

it('throws when no temporary file can be created', function (): void {
    $process = g3_run_store_subprocess(<<<'PHP'
        $store = new App\Services\Cloning\EncryptedFileKeyRemappingStore;
        try {
            @$store->storeTable('users', ['1' => 'u-1']);
            echo 'stored';
        } catch (Throwable $e) {
            echo get_class($e).': '.$e->getMessage();
        }
        PHP, ['-d', 'sys_temp_dir=/nonexistent-g3-'.uniqid()]);

    expect(trim($process->getOutput()))
        ->toBe('RuntimeException: Failed to create temporary file for key mapping store.');
});
