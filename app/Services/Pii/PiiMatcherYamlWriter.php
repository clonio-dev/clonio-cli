<?php

declare(strict_types=1);

namespace App\Services\Pii;

use App\Data\Pii\PiiMatcherGroupData;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

class PiiMatcherYamlWriter
{
    /**
     * @param  list<PiiMatcherGroupData>  $groups
     */
    public function write(array $groups, string $path): void
    {
        $data = [
            'version' => '1',
            'groups' => [],
        ];

        foreach ($groups as $group) {
            $matchersData = [];

            foreach ($group->matchers as $matcher) {
                $matcherEntry = [
                    'name' => $matcher->name,
                    'sensitivity' => $matcher->sensitivity->value,
                    'enabled' => $matcher->enabled,
                ];

                if (count($matcher->patterns) > 0) {
                    $matcherEntry['patterns'] = $matcher->patterns;
                }

                $t = $matcher->transformation;
                $transformationData = ['strategy' => $t->strategy];

                if ($t->strategy === 'fake') {
                    if ($t->fakerMethod !== null) {
                        $transformationData['faker_method'] = $t->fakerMethod;
                    }

                    $transformationData['faker_arguments'] = $t->fakerArguments;
                } elseif ($t->strategy === 'hash') {
                    if ($t->hashAlgorithm !== null) {
                        $transformationData['algorithm'] = $t->hashAlgorithm;
                    }

                    // Only write 'salt' when the matcher carries an explicit
                    // override. Omitting it instructs the engine to apply its
                    // per-run random salt (GDPR-aligned pseudonymization).
                    if ($t->hashSalt !== null) {
                        $transformationData['salt'] = $t->hashSalt;
                    }
                } elseif ($t->strategy === 'mask') {
                    if ($t->visibleChars !== null) {
                        $transformationData['visible_chars'] = $t->visibleChars;
                    }

                    if ($t->maskChar !== null) {
                        $transformationData['mask_char'] = $t->maskChar;
                    }

                    if ($t->preserveFormat !== null) {
                        $transformationData['preserve_format'] = $t->preserveFormat;
                    }
                } elseif ($t->strategy === 'static') {
                    $transformationData['value'] = $t->staticValue ?? '';
                } elseif ($t->strategy === 'template') {
                    $transformationData['template'] = $t->template ?? '';
                }

                $matcherEntry['transformation'] = $transformationData;
                $matchersData[$matcher->key] = $matcherEntry;
            }

            $data['groups'][$group->key] = [
                'name' => $group->name,
                'matchers' => $matchersData,
            ];
        }

        $header = "# yaml-language-server: \$schema=https://schema.clonio.dev/pii-matchers/v1.json\n";
        $yaml = Yaml::dump($data, 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        Storage::disk('local')->put($path, $header.$yaml);
    }
}
