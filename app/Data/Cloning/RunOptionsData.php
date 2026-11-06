<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use InvalidArgumentException;

/**
 * The `cloning:run` flags, parsed and checked once.
 *
 * The override fields are null when neither the flag nor its `--no-` form
 * was passed, so the value from cloning.yaml applies.
 */
final readonly class RunOptionsData
{
    /**
     * @param  list<string>  $skipTables
     * @param  list<string>  $onlyTables
     * @param  list<string>  $auditChannels
     * @param  ?string  $remapKeysDisabledBy  the flag that turned key remapping off, e.g. `--no-remap-keys`
     */
    public function __construct(
        public string $yamlPath,
        public ?string $targetConnectionName = null,
        public bool $allowFailure = false,
        public bool $dryRun = false,
        public bool $ci = false,
        public bool $skipSchema = false,
        public bool $breakOnFailure = false,
        public bool $noMemoryLimit = false,
        public bool $fileBased = false,
        public array $skipTables = [],
        public array $onlyTables = [],
        public array $auditChannels = [],
        public ?bool $enforceColumnTypes = null,
        public ?bool $dropUnknownTables = null,
        public ?bool $dropExtraColumns = null,
        public ?bool $recreateTables = null,
        public ?bool $disableForeignKeyChecks = null,
        public ?bool $remapKeys = null,
        public ?string $remapKeysDisabledBy = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options  the command options, keyed by option name without dashes
     *
     * @throws InvalidArgumentException when flags conflict or a name list is invalid
     */
    public static function fromOptions(string $yamlPath, array $options): self
    {
        $skipGiven = self::value($options, 'skip-tables') !== '';
        $onlyGiven = self::value($options, 'only-tables') !== '';

        throw_if($skipGiven && $onlyGiven, InvalidArgumentException::class, '--skip-tables and --only-tables are mutually exclusive.');

        $skipTables = self::tableNameList($options, 'skip-tables');
        $onlyTables = self::tableNameList($options, 'only-tables');
        $auditChannels = self::nameList($options, 'audit-channel');

        $remapKeysOffFlag = self::firstFlag($options, ['no-remap-keys', 'skip-remapping-keys']);

        $target = self::value($options, 'target');

        return new self(
            yamlPath: $yamlPath,
            targetConnectionName: $target === '' ? null : $target,
            allowFailure: self::flag($options, 'allow-failure'),
            dryRun: self::flag($options, 'dry-run'),
            ci: self::flag($options, 'ci'),
            skipSchema: self::flag($options, 'skip-schema'),
            breakOnFailure: self::flag($options, 'break-on-failure'),
            noMemoryLimit: self::flag($options, 'no-memory-limit'),
            fileBased: self::flag($options, 'file-based'),
            skipTables: $skipTables,
            onlyTables: $onlyTables,
            auditChannels: $auditChannels,
            enforceColumnTypes: self::override($options, 'enforce-column-types', ['no-enforce-column-types']),
            dropUnknownTables: self::override($options, 'drop-unknown-tables', ['no-drop-unknown-tables']),
            dropExtraColumns: self::override($options, 'drop-extra-columns', ['no-drop-extra-columns']),
            recreateTables: self::override($options, 'recreate-tables', ['no-recreate-tables']),
            disableForeignKeyChecks: self::override($options, 'disable-fk-checks', ['no-disable-fk-checks']),
            remapKeys: self::override($options, 'remap-keys', ['no-remap-keys', 'skip-remapping-keys']),
            remapKeysDisabledBy: $remapKeysOffFlag === null ? null : '--'.$remapKeysOffFlag,
        );
    }

    /**
     * Returns the config with the CLI overrides applied on top of its options.
     */
    public function applyTo(CloningConfigData $config): CloningConfigData
    {
        $options = $config->options;

        return new CloningConfigData(
            version: $config->version,
            connectionName: $config->connectionName,
            options: new CloningOptionsData(
                chunkSize: $options->chunkSize,
                enforceColumnTypes: $this->enforceColumnTypes ?? $options->enforceColumnTypes,
                dropUnknownTables: $this->dropUnknownTables ?? $options->dropUnknownTables,
                dropExtraColumns: $this->dropExtraColumns ?? $options->dropExtraColumns,
                disableForeignKeyChecks: $this->disableForeignKeyChecks ?? $options->disableForeignKeyChecks,
                fakerLocale: $options->fakerLocale,
                remapKeys: $this->remapKeys ?? $options->remapKeys,
                recreateTables: $this->recreateTables ?? $options->recreateTables,
            ),
            tables: $config->tables,
            keyRemapping: $config->keyRemapping,
            skipTables: $config->skipTables,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function flag(array $options, string $name): bool
    {
        return (bool) ($options[$name] ?? false);
    }

    /**
     * Returns true for the flag, false for one of its negations, and null for
     * neither.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $offFlags
     *
     * @throws InvalidArgumentException when the flag and a negation are both set
     */
    private static function override(array $options, string $onFlag, array $offFlags): ?bool
    {
        $offFlag = self::firstFlag($options, $offFlags);
        $on = self::flag($options, $onFlag);

        if ($on && $offFlag !== null) {
            throw new InvalidArgumentException(sprintf('--%s and --%s are mutually exclusive.', $onFlag, $offFlag));
        }

        return $on ? true : ($offFlag === null ? null : false);
    }

    /**
     * Returns the first of the flags that is set.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $flags
     */
    private static function firstFlag(array $options, array $flags): ?string
    {
        return array_find($flags, static fn (string $flag): bool => self::flag($options, $flag));
    }

    /**
     * Returns the option value as a string, or '' when it is missing or not a scalar.
     *
     * @param  array<string, mixed>  $options
     */
    private static function value(array $options, string $name): string
    {
        $value = $options[$name] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Splits a comma-separated table name option and rejects patterns.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function tableNameList(array $options, string $name): array
    {
        $names = self::nameList($options, $name);
        $pattern = array_find($names, static fn (string $tableName): bool => str_starts_with($tableName, '/'));

        if ($pattern !== null) {
            throw new InvalidArgumentException(sprintf(
                '--%s takes table names, not patterns ("%s"). Write table patterns in cloning.yaml.',
                $name,
                $pattern,
            ));
        }

        return $names;
    }

    /**
     * Splits a comma-separated option into trimmed, non-empty names. A missing
     * or empty option gives an empty list; a value with only commas and spaces
     * is an error.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function nameList(array $options, string $name): array
    {
        $value = self::value($options, $name);

        if ($value === '') {
            return [];
        }

        $names = array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
            array_map(trim(...), explode(',', $value)),
            static fn (string $part): bool => $part !== '',
        ));

        if ($names === []) {
            throw new InvalidArgumentException(sprintf('--%s needs at least one name.', $name));
        }

        return $names;
    }
}
