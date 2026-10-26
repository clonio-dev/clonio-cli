<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Config\ConfigService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function makeDeleteConnection(string $name = 'staging', bool $isProduction = false): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'encrypted:abc123',
        isProduction: $isProduction,
    );
}

it('deletes connection after user confirms', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging'])
        ->expectsConfirmation('Delete this connection?', 'yes')
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('cancels when user declines confirmation', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->never();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging'])
        ->expectsConfirmation('Delete this connection?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('skips confirmation and deletes immediately with --force', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging', '--force' => true])
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('fails when connection not found', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => makeDeleteConnection('staging')]);
    $config->shouldReceive('getConnection')->with('unknown')->andReturn(null);
    $config->shouldReceive('deleteConnection')->never();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'unknown'])
        ->expectsOutputToContain('No connection named unknown found.')
        ->assertExitCode(2);
});

it('shows production warning when connection is marked as production', function (): void {
    $connection = makeDeleteConnection('prod', isProduction: true);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['prod' => $connection]);
    $config->shouldReceive('getConnection')->with('prod')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('prod')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'prod'])
        ->expectsOutputToContain('Warning: This is a production connection!')
        ->expectsConfirmation('Delete this connection?', 'yes')
        ->expectsOutputToContain('Connection prod deleted.')
        ->assertExitCode(0);
});

it('auto-selects the only connection when no name is given', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['--force' => true])
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('errors when no connections exist', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([]);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete')
        ->expectsOutputToContain('No connections found.')
        ->assertExitCode(2);
});

it('prompts the user to choose when multiple connections exist and no name is given', function (): void {
    $staging = makeDeleteConnection('staging');
    $production = makeDeleteConnection('production');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $staging,
        'production' => $production,
    ]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($staging);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete')
        ->expectsChoice('Select a connection to delete', 'staging', ['staging', 'production'])
        ->expectsConfirmation('Delete this connection?', 'yes')
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('fails with config error when multiple connections exist, no name given, and non-interactive', function (): void {
    $staging = makeDeleteConnection('staging');
    $production = makeDeleteConnection('production');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $staging,
        'production' => $production,
    ]);
    $config->shouldNotReceive('getConnection');
    $config->shouldNotReceive('deleteConnection');

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['--no-interaction' => true])
        ->expectsOutputToContain('Multiple connections found; pass the connection name: clonio connection:delete <name>.')
        ->assertExitCode(2);
});

it('auto-selects the only connection when no name is given and non-interactive, but still cancels without --force', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->never();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['--no-interaction' => true])
        ->expectsConfirmation('Delete this connection?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('auto-selects the only connection when no name is given and deletes with --force in non-interactive mode', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['--no-interaction' => true, '--force' => true])
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('renders the schema row for a connection that has a schema', function (): void {
    $connection = new ConnectionData(
        name: 'pg',
        type: DatabaseConnectionType::PostgreSQL,
        host: 'localhost',
        port: 5432,
        database: 'mydb',
        schema: 'public',
        username: 'postgres',
        password: 'encrypted:abc123',
        isProduction: false,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['pg' => $connection]);
    $config->shouldReceive('getConnection')->with('pg')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('pg')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'pg', '--force' => true])
        ->expectsOutputToContain('public')
        ->expectsOutputToContain('Connection pg deleted.')
        ->assertExitCode(0);
});

it('returns an IO error when deletion throws', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once()->andThrow(new RuntimeException('disk full'));

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging', '--force' => true])
        ->expectsOutputToContain('disk full')
        ->assertExitCode(5);
});

/**
 * Runs connection:delete with a real question helper fed from an in-memory stdin stream.
 *
 * @param  list<string>  $lines
 * @return array{int, string}
 */
function g4b_runDeleteWithStdin(array $params, array $lines): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, implode(PHP_EOL, $lines).PHP_EOL);
    rewind($stream);

    $input = new ArrayInput(['command' => 'connection:delete', ...$params]);
    $input->setStream($stream);
    $input->setInteractive(true);
    $output = new BufferedOutput;

    $command = app(Kernel::class)->all()['connection:delete'];
    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

function g4b_fakeDeleteConfig(ConnectionData $connection, bool $expectDelete): ConfigService
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([$connection->name => $connection]);
    $config->shouldReceive('getConnection')->with($connection->name)->andReturn($connection);

    if ($expectDelete) {
        $config->shouldReceive('deleteConnection')->with($connection->name)->once();
    } else {
        $config->shouldNotReceive('deleteConnection');
    }

    return $config;
}

it('shows every field of a network connection in the summary table', function (bool $isProduction, string $productionLabel): void {
    $this->app->instance(ConfigService::class, g4b_fakeDeleteConfig(makeDeleteConnection('staging', $isProduction), true));

    $this->artisan('connection:delete', ['name' => 'staging', '--force' => true])
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'staging'],
            ['Type', 'MySQL'],
            ['Host', 'localhost'],
            ['Port', '3306'],
            ['Database', 'mydb'],
            ['Username', 'root'],
            ['Password', '••••••••'],
            ['Production', $productionLabel],
        ])
        ->assertExitCode(0);
})->with([
    'non-production' => [false, 'No'],
    'production' => [true, 'Yes'],
]);

it('shows the schema row in the summary table', function (): void {
    $connection = new ConnectionData(
        name: 'pg', type: DatabaseConnectionType::PostgreSQL, host: 'pg.local', port: 5432, database: 'pgdb',
        schema: 'sales', username: 'pgu', password: 'encrypted:abc', isProduction: false,
    );
    $this->app->instance(ConfigService::class, g4b_fakeDeleteConfig($connection, true));

    $this->artisan('connection:delete', ['name' => 'pg', '--force' => true])
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'pg'],
            ['Type', 'PostgreSQL'],
            ['Host', 'pg.local'],
            ['Port', '5432'],
            ['Database', 'pgdb'],
            ['Schema', 'sales'],
            ['Username', 'pgu'],
            ['Password', '••••••••'],
            ['Production', 'No'],
        ])
        ->assertExitCode(0);
});

it('omits the network rows of a sqlite connection from the summary table', function (): void {
    $connection = new ConnectionData(
        name: 'local', type: DatabaseConnectionType::Sqlite, host: null, port: null, database: '/tmp/g4b.db',
        schema: null, username: null, password: '', isProduction: false,
    );
    $this->app->instance(ConfigService::class, g4b_fakeDeleteConfig($connection, true));

    $this->artisan('connection:delete', ['name' => 'local', '--force' => true])
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'local'],
            ['Type', 'SQLite'],
            ['Database', '/tmp/g4b.db'],
            ['Password', '••••••••'],
            ['Production', 'No'],
        ])
        ->doesntExpectOutputToContain('Host')
        ->doesntExpectOutputToContain('Port')
        ->doesntExpectOutputToContain('Username')
        ->assertExitCode(0);
});

it('omits the database row when the connection has no database', function (): void {
    $connection = new ConnectionData(
        name: 'dump', type: DatabaseConnectionType::Dump, host: null, port: null, database: null,
        schema: null, username: null, password: '', isProduction: false,
    );
    $this->app->instance(ConfigService::class, g4b_fakeDeleteConfig($connection, true));

    $this->artisan('connection:delete', ['name' => 'dump', '--force' => true])
        ->doesntExpectOutputToContain('Database')
        ->assertExitCode(0);
});

it('cancels when the confirmation is answered with Enter (default is no)', function (): void {
    $this->app->instance(ConfigService::class, g4b_fakeDeleteConfig(makeDeleteConnection('staging'), false));

    [$exitCode, $output] = g4b_runDeleteWithStdin(['name' => 'staging'], ['']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(' Delete this connection? (yes/no) [no]:')
        ->and($output)->toContain('Cancelled.')
        ->and($output)->not->toContain('deleted.');
});

it('treats an empty name argument like a missing one', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => makeDeleteConnection('staging'),
        'production' => makeDeleteConnection('production'),
    ]);
    $config->shouldNotReceive('getConnection');
    $config->shouldNotReceive('deleteConnection');
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => '', '--no-interaction' => true])
        ->expectsOutputToContain('Multiple connections found; pass the connection name: clonio connection:delete <name>.')
        ->assertExitCode(2);
});

it('deletes the connection picked from the list', function (?string $answer, string $expected): void {
    $staging = makeDeleteConnection('staging');
    $production = makeDeleteConnection('production');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $staging, 'production' => $production]);
    $config->shouldReceive('getConnection')->once()->with($expected)->andReturn($expected === 'staging' ? $staging : $production);
    $config->shouldReceive('deleteConnection')->once()->with($expected);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['--force' => true])
        ->expectsQuestion('Select a connection to delete', $answer)
        ->expectsOutputToContain(sprintf('Connection %s deleted.', $expected))
        ->assertExitCode(0);
})->with([
    'second entry' => ['production', 'production'],
    'no answer falls back to the first entry' => [null, 'staging'],
]);
