<?php

declare(strict_types=1);

namespace App\Services\Pii;

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;
use App\Enums\PiiSensitivity;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class PiiMatcherYamlReader
{
    /**
     * @return list<PiiMatcherGroupData>
     *
     * @throws RuntimeException
     */
    public function read(string $content): array
    {
        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $parseException) {
            throw new RuntimeException(
                sprintf('Failed to parse clonio.pii-matchers.yaml: %s', $parseException->getMessage()),
                0,
                $parseException,
            );
        }

        throw_unless(is_array($parsed), RuntimeException::class, 'clonio.pii-matchers.yaml must be a YAML mapping at the top level.');

        $rawGroups = $parsed['groups'] ?? null;

        throw_unless(is_array($rawGroups), RuntimeException::class, 'clonio.pii-matchers.yaml must contain a "groups" key.');

        $groups = [];

        foreach ($rawGroups as $groupKey => $groupData) {
            if (! is_array($groupData)) {
                throw new RuntimeException(sprintf('Group "%s" must be a mapping.', $groupKey));
            }

            $groupName = $groupData['name'] ?? null;

            if (! is_string($groupName) || $groupName === '') {
                throw new RuntimeException(sprintf('Group "%s" must have a non-empty "name" field.', $groupKey));
            }

            $rawMatchers = $groupData['matchers'] ?? null;

            if (! is_array($rawMatchers)) {
                throw new RuntimeException(sprintf('Group "%s" must have a "matchers" key.', $groupKey));
            }

            $matchers = [];

            foreach ($rawMatchers as $matcherKey => $matcherData) {
                if (! is_array($matcherData)) {
                    throw new RuntimeException(sprintf('Matcher "%s.%s" must be a mapping.', $groupKey, $matcherKey));
                }

                /** @var array<string, mixed> $matcherData */
                $matchers[] = $this->parseMatcher((string) $groupKey, (string) $matcherKey, $matcherData);
            }

            $groups[] = new PiiMatcherGroupData(
                key: (string) $groupKey,
                name: $groupName,
                matchers: $matchers,
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseMatcher(string $groupKey, string $matcherKey, array $data): PiiMatcherData
    {
        $name = $data['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new RuntimeException(sprintf('Matcher "%s.%s" must have a non-empty "name" field.', $groupKey, $matcherKey));
        }

        $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : true;

        $rawPatterns = $data['patterns'] ?? [];
        $patterns = [];

        if (is_array($rawPatterns)) {
            foreach ($rawPatterns as $p) {
                if (is_string($p)) {
                    $patterns[] = $p;
                }
            }
        }

        $transformation = $this->parseTransformation($groupKey, $matcherKey, $data['transformation'] ?? null, $matcherKey);

        $sensitivity = isset($data['sensitivity']) && is_string($data['sensitivity'])
            ? PiiSensitivity::tryFrom($data['sensitivity']) ?? PiiSensitivity::Medium
            : PiiSensitivity::Medium;

        return new PiiMatcherData(
            key: $matcherKey,
            group: $groupKey,
            name: $name,
            enabled: $enabled,
            patterns: $patterns,
            transformation: $transformation,
            isBaseline: false,
            sensitivity: $sensitivity,
        );
    }

    private function parseTransformation(string $groupKey, string $matcherKey, mixed $rawTransformation, string $columnName): ColumnCloningConfigData
    {
        if (! is_array($rawTransformation)) {
            // Provide a default empty transformation for disabled matchers or missing config
            return new ColumnCloningConfigData(
                columnName: $columnName,
                strategy: 'keep',
                fakerMethod: null,
                fakerArguments: [],
                hashAlgorithm: null,
                hashSalt: null,
                maskChar: null,
                visibleChars: null,
                preserveFormat: null,
                staticValue: null,
            );
        }

        $strategy = $rawTransformation['strategy'] ?? 'keep';

        if (! is_string($strategy)) {
            throw new RuntimeException(sprintf('Matcher "%s.%s" transformation must have a string "strategy".', $groupKey, $matcherKey));
        }

        $fakerMethod = isset($rawTransformation['faker_method']) && is_string($rawTransformation['faker_method'])
            ? $rawTransformation['faker_method']
            : null;

        $rawFakerArgs = $rawTransformation['faker_arguments'] ?? [];
        $fakerArguments = is_array($rawFakerArgs) ? array_values($rawFakerArgs) : [];

        $hashAlgorithm = isset($rawTransformation['algorithm']) && is_string($rawTransformation['algorithm'])
            ? $rawTransformation['algorithm']
            : null;

        $hashSalt = isset($rawTransformation['salt']) && is_string($rawTransformation['salt'])
            ? $rawTransformation['salt']
            : null;

        $maskChar = isset($rawTransformation['mask_char']) && is_string($rawTransformation['mask_char'])
            ? $rawTransformation['mask_char']
            : null;

        $visibleChars = isset($rawTransformation['visible_chars']) && is_int($rawTransformation['visible_chars'])
            ? $rawTransformation['visible_chars']
            : null;

        $preserveFormat = isset($rawTransformation['preserve_format'])
            ? (bool) $rawTransformation['preserve_format']
            : null;

        $staticValue = isset($rawTransformation['value']) && is_string($rawTransformation['value'])
            ? $rawTransformation['value']
            : null;

        $template = isset($rawTransformation['template']) && is_string($rawTransformation['template'])
            ? $rawTransformation['template']
            : null;

        /** @var list<scalar> $fakerArguments */
        return new ColumnCloningConfigData(
            columnName: $columnName,
            strategy: $strategy,
            fakerMethod: $fakerMethod,
            fakerArguments: $fakerArguments,
            hashAlgorithm: $hashAlgorithm,
            hashSalt: $hashSalt,
            maskChar: $maskChar,
            visibleChars: $visibleChars,
            preserveFormat: $preserveFormat,
            staticValue: $staticValue,
            template: $template,
        );
    }
}
