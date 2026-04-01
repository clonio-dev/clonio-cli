<?php

declare(strict_types=1);

namespace App\Services\Pii;

use App\Data\Cloning\MatchersUpdateDiffData;
use App\Data\Cloning\NewMatcherEntryData;
use App\Data\Cloning\OrphanedMatcherEntryData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;

class PiiMatcherUpdateService
{
    /**
     * @param  list<PiiMatcherGroupData>  $fileGroups
     * @param  list<PiiMatcherGroupData>  $baselineGroups
     */
    public function computeDiff(array $fileGroups, array $baselineGroups): MatchersUpdateDiffData
    {
        $fileMatcherKeys = $this->buildMatcherKeyMap($fileGroups);
        $baselineMatcherKeys = $this->buildMatcherKeyMap($baselineGroups);

        $additions = [];
        $orphans = [];

        // Find baseline matchers not in file -> additions
        foreach ($baselineGroups as $baselineGroup) {
            foreach ($baselineGroup->matchers as $baselineMatcher) {
                $compositeKey = $baselineGroup->key.'.'.$baselineMatcher->key;

                if (! isset($fileMatcherKeys[$compositeKey])) {
                    $groupExistsInFile = $this->groupExistsInFile($fileGroups, $baselineGroup->key);

                    $additions[] = new NewMatcherEntryData(
                        groupKey: $baselineGroup->key,
                        groupName: $baselineGroup->name,
                        matcherKey: $baselineMatcher->key,
                        matcherName: $baselineMatcher->name,
                        groupIsNew: ! $groupExistsInFile,
                    );
                }
            }
        }

        // Find file matchers not in baseline -> orphans
        foreach ($fileGroups as $fileGroup) {
            foreach ($fileGroup->matchers as $fileMatcher) {
                $compositeKey = $fileGroup->key.'.'.$fileMatcher->key;

                if (! isset($baselineMatcherKeys[$compositeKey])) {
                    $orphans[] = new OrphanedMatcherEntryData(
                        groupKey: $fileGroup->key,
                        matcherKey: $fileMatcher->key,
                        matcherName: $fileMatcher->name,
                    );
                }
            }
        }

        return new MatchersUpdateDiffData(
            additions: $additions,
            orphans: $orphans,
            hasChanges: $additions !== [],
        );
    }

    /**
     * @param  list<PiiMatcherGroupData>  $fileGroups
     * @param  list<PiiMatcherGroupData>  $baselineGroups
     * @return list<PiiMatcherGroupData>
     */
    public function applyDiff(array $fileGroups, MatchersUpdateDiffData $diff, array $baselineGroups): array
    {
        if (! $diff->hasChanges) {
            return $fileGroups;
        }

        // Index baseline groups and matchers for lookup
        $baselineGroupMap = [];

        foreach ($baselineGroups as $group) {
            $baselineGroupMap[$group->key] = $group;
        }

        // Index existing file groups by key for mutation
        $resultGroups = [];

        foreach ($fileGroups as $group) {
            $resultGroups[$group->key] = $group;
        }

        foreach ($diff->additions as $addition) {
            $baselineGroup = $baselineGroupMap[$addition->groupKey] ?? null;

            if ($baselineGroup === null) {
                continue;
            }

            // Find the new matcher in the baseline group
            $newMatcher = null;

            foreach ($baselineGroup->matchers as $m) {
                if ($m->key === $addition->matcherKey) {
                    $newMatcher = $m;
                    break;
                }
            }

            if ($newMatcher === null) {
                continue;
            }

            if (isset($resultGroups[$addition->groupKey])) {
                // Append matcher to existing group
                $existingGroup = $resultGroups[$addition->groupKey];
                $updatedMatchers = [...$existingGroup->matchers, $newMatcher];
                $resultGroups[$addition->groupKey] = new PiiMatcherGroupData(
                    key: $existingGroup->key,
                    name: $existingGroup->name,
                    matchers: $updatedMatchers,
                );
            } else {
                // Create a new group with just this matcher
                $resultGroups[$addition->groupKey] = new PiiMatcherGroupData(
                    key: $baselineGroup->key,
                    name: $baselineGroup->name,
                    matchers: [$newMatcher],
                );
            }
        }

        return array_values($resultGroups);
    }

    /**
     * @param  list<PiiMatcherGroupData>  $groups
     * @return array<string, PiiMatcherData>
     */
    private function buildMatcherKeyMap(array $groups): array
    {
        $map = [];

        foreach ($groups as $group) {
            foreach ($group->matchers as $matcher) {
                $map[$group->key.'.'.$matcher->key] = $matcher;
            }
        }

        return $map;
    }

    /**
     * @param  list<PiiMatcherGroupData>  $fileGroups
     */
    private function groupExistsInFile(array $fileGroups, string $groupKey): bool
    {
        return array_any($fileGroups, fn ($group): bool => $group->key === $groupKey);
    }
}
