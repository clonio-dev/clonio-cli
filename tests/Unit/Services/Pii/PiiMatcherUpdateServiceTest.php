<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\MatchersUpdateDiffData;
use App\Data\Cloning\NewMatcherEntryData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;
use App\Services\Pii\PiiMatcherUpdateService;

function updMatcher(string $key): PiiMatcherData
{
    return new PiiMatcherData(
        key: $key,
        group: 'g',
        name: ucfirst($key),
        enabled: true,
        patterns: [$key],
        transformation: new ColumnCloningConfigData($key, 'keep', null, [], null, null, null, null, null, null),
        isBaseline: true,
    );
}

function updGroup(string $key, string $name, array $matcherKeys): PiiMatcherGroupData
{
    return new PiiMatcherGroupData($key, $name, array_map('updMatcher', $matcherKeys));
}

beforeEach(function (): void {
    $this->service = new PiiMatcherUpdateService;
});

it('detects a missing baseline matcher as an addition in an existing group', function (): void {
    $file = [updGroup('g', 'Group', ['a'])];
    $baseline = [updGroup('g', 'Group', ['a', 'b'])];

    $diff = $this->service->computeDiff($file, $baseline);

    expect($diff->hasChanges)->toBeTrue();
    expect($diff->additions)->toHaveCount(1);
    expect($diff->additions[0]->matcherKey)->toBe('b');
    expect($diff->additions[0]->groupIsNew)->toBeFalse();
    expect($diff->orphans)->toBe([]);
});

it('flags an addition as new group when the group is absent from the file', function (): void {
    $file = [];
    $baseline = [updGroup('g', 'Group', ['a'])];

    $diff = $this->service->computeDiff($file, $baseline);

    expect($diff->additions)->toHaveCount(1);
    expect($diff->additions[0]->groupIsNew)->toBeTrue();
});

it('detects file matchers absent from baseline as orphans without changes', function (): void {
    $file = [updGroup('g', 'Group', ['a', 'custom'])];
    $baseline = [updGroup('g', 'Group', ['a'])];

    $diff = $this->service->computeDiff($file, $baseline);

    expect($diff->additions)->toBe([]);
    expect($diff->hasChanges)->toBeFalse();
    expect($diff->orphans)->toHaveCount(1);
    expect($diff->orphans[0]->matcherKey)->toBe('custom');
});

it('returns file groups unchanged when the diff has no changes', function (): void {
    $file = [updGroup('g', 'Group', ['a'])];
    $diff = new MatchersUpdateDiffData(additions: [], orphans: [], hasChanges: false);

    $result = $this->service->applyDiff($file, $diff, []);

    expect($result)->toBe($file);
});

it('appends an added matcher to an existing group', function (): void {
    $file = [updGroup('g', 'Group', ['a'])];
    $baseline = [updGroup('g', 'Group', ['a', 'b'])];
    $diff = $this->service->computeDiff($file, $baseline);

    $result = $this->service->applyDiff($file, $diff, $baseline);

    expect($result)->toHaveCount(1);
    $keys = array_map(fn ($m): string => $m->key, $result[0]->matchers);
    expect($keys)->toBe(['a', 'b']);
});

it('creates a new group for an addition whose group is absent', function (): void {
    $file = [updGroup('g', 'Group', ['a'])];
    $baseline = [updGroup('g', 'Group', ['a']), updGroup('h', 'Other', ['x'])];
    $diff = $this->service->computeDiff($file, $baseline);

    $result = $this->service->applyDiff($file, $diff, $baseline);

    expect($result)->toHaveCount(2);
    expect($result[1]->key)->toBe('h');
    expect($result[1]->matchers[0]->key)->toBe('x');
});

it('skips additions whose baseline group is missing', function (): void {
    $file = [updGroup('g', 'Group', ['a'])];
    $diff = new MatchersUpdateDiffData(
        additions: [new NewMatcherEntryData('ghost', 'Ghost', 'm', 'M', true)],
        orphans: [],
        hasChanges: true,
    );

    $result = $this->service->applyDiff($file, $diff, []);

    expect($result)->toBe($file);
});

it('skips additions whose matcher key is missing from the baseline group', function (): void {
    $file = [updGroup('g', 'Group', ['a'])];
    $baseline = [updGroup('g', 'Group', ['a'])];
    $diff = new MatchersUpdateDiffData(
        additions: [new NewMatcherEntryData('g', 'Group', 'nope', 'Nope', false)],
        orphans: [],
        hasChanges: true,
    );

    $result = $this->service->applyDiff($file, $diff, $baseline);

    expect($result[0]->matchers)->toHaveCount(1);
    expect($result[0]->matchers[0]->key)->toBe('a');
});
