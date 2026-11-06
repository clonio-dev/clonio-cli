<?php

declare(strict_types=1);

use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\SourceRowEstimator;
use Illuminate\Support\Facades\DB;

function sreTable(string $strategy = 'full', ?int $limit = null): TableCloningConfigData
{
    return new TableCloningConfigData(
        tableName: 'users',
        rows: new TableRowConfigData(strategy: $strategy, limit: $limit, sortBy: 'id', clear: ClearMode::None),
        columns: [],
    );
}

it('returns an unknown, unmarked total when the query fails', function (): void {
    DB::shouldReceive('connection')->with('src')->andReturnSelf();
    DB::shouldReceive('selectOne')->andThrow(new RuntimeException('denied'));

    expect((new SourceRowEstimator)->estimate('src', sreTable(), DatabaseConnectionType::Mysql))->toBe([null, false]);
});

it('returns an unknown total for an unreadable or negative catalog value', function (mixed $result): void {
    DB::shouldReceive('connection')->with('src')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn($result);

    expect((new SourceRowEstimator)->estimate('src', sreTable(), DatabaseConnectionType::PostgreSQL))->toBe([null, true]);
})->with([
    'no row' => [null],
    'non-numeric' => [(object) ['c' => 'n/a']],
    'never analyzed' => [(object) ['c' => -1]],
]);

it('keeps an empty table at zero rows', function (): void {
    DB::shouldReceive('connection')->with('src')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['c' => 0]);

    expect((new SourceRowEstimator)->estimate('src', sreTable('first', 10), DatabaseConnectionType::SqlServer))->toBe([0, true]);
});
