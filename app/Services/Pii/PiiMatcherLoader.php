<?php

declare(strict_types=1);

namespace App\Services\Pii;

use App\Data\Pii\PiiMatcherSetData;
use Illuminate\Support\Facades\Storage;

class PiiMatcherLoader
{
    public function __construct(
        private readonly PiiMatcherBaselineProvider $baselineProvider,
        private readonly PiiMatcherYamlReader $yamlReader,
    ) {}

    public function load(?string $path = null): PiiMatcherSetData
    {
        $filePath = $path ?? 'clonio.pii-matchers.yaml';

        if (! Storage::disk('local')->exists($filePath)) {
            return $this->baselineProvider->getMatcherSet();
        }

        $content = Storage::disk('local')->get($filePath);

        if (! is_string($content)) {
            return $this->baselineProvider->getMatcherSet();
        }

        $groups = $this->yamlReader->read($content);

        $matchers = [];

        foreach ($groups as $group) {
            foreach ($group->matchers as $matcher) {
                $matchers[] = $matcher;
            }
        }

        return new PiiMatcherSetData($matchers);
    }
}
