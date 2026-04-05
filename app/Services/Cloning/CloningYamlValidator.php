<?php

declare(strict_types=1);

namespace App\Services\Cloning;

class CloningYamlValidator
{
    private const array KNOWN_FAKER_METHODS = [
        'name', 'firstName', 'lastName', 'prefix', 'suffix', 'gender', 'title',
        'safeEmail', 'email', 'freeEmail', 'companyEmail', 'userName', 'phoneNumber',
        'e164PhoneNumber', 'tollFreePhoneNumber',
        'address', 'streetAddress', 'streetName', 'buildingNumber', 'city', 'state',
        'stateAbbr', 'postcode', 'country', 'countryCode', 'latitude', 'longitude',
        'company', 'companySuffix', 'jobTitle', 'iban', 'swiftBicNumber',
        'creditCardNumber', 'creditCardType', 'creditCardExpirationDate', 'currencyCode',
        'url', 'domainName', 'slug', 'ipv4', 'ipv6', 'macAddress', 'userAgent',
        'mimeType', 'fileExtension', 'md5', 'sha1', 'sha256', 'uuid',
        'word', 'words', 'sentence', 'sentences', 'paragraph', 'text',
        'randomNumber', 'numberBetween', 'randomFloat', 'numerify', 'lexify',
        'bothify', 'regexify', 'boolean', 'randomElement',
        'date', 'time', 'dateTime', 'dateTimeBetween', 'unixTime', 'year',
        'month', 'dayOfMonth', 'timezone',
        'ean13', 'ean8', 'isbn10', 'isbn13',
    ];

    private const array VALID_ROW_STRATEGIES = ['full', 'first', 'last'];

    private const array VALID_COLUMN_STRATEGIES = ['keep', 'fake', 'hash', 'mask', 'null', 'static', 'remapping'];

    private const array VALID_HASH_ALGORITHMS = ['sha256', 'sha512', 'md5', 'sha1'];

    /**
     * Validates a parsed YAML array against cloning config rules.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function validate(array $data): array
    {
        $errors = [];

        // 1. Required top-level fields
        foreach (['version', 'connection', 'options', 'tables'] as $field) {
            if (! array_key_exists($field, $data)) {
                $errors[] = sprintf("Missing required field: '%s'", $field);
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        // 2. version must be "1"
        if ($data['version'] !== '1' && $data['version'] !== 1) {
            $errors[] = "Field 'version' must be \"1\"";
        }

        // 3. connection must match ^[a-z0-9_-]+$
        if (! is_string($data['connection']) || ! preg_match('/^[a-z0-9_-]+$/', $data['connection'])) {
            $errors[] = "Field 'connection' must match pattern ^[a-z0-9_-]+\$";
        }

        // 4. options validation
        $options = $data['options'];

        if (! is_array($options)) {
            $errors[] = "Field 'options' must be an object";
        } else {
            /** @var array<string, mixed> $typedOptions */
            $typedOptions = $options;
            $errors = array_merge($errors, $this->validateOptions($typedOptions));
        }

        // 5. tables must have at least 1 entry
        $tables = $data['tables'];

        if (! is_array($tables) || $tables === []) {
            $errors[] = "Field 'tables' must have at least 1 entry";
        } else {
            /** @var array<string, mixed> $typedTables */
            $typedTables = $tables;
            $errors = array_merge($errors, $this->validateTables($typedTables));
        }

        // 6. key_remapping validation (optional section)
        $keyRemapping = $data['key_remapping'] ?? null;
        if ($keyRemapping !== null) {
            if (! is_array($keyRemapping)) {
                $errors[] = "Field 'key_remapping' must be an object";
            } else {
                /** @var array<string, mixed> $keyRemapping */
                $errors = array_merge($errors, $this->validateKeyRemapping($keyRemapping, is_array($data['tables']) ? array_keys($data['tables']) : []));
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function validateOptions(array $options): array
    {
        $errors = [];

        if (! array_key_exists('chunk_size', $options) || ! is_int($options['chunk_size']) || $options['chunk_size'] < 1) {
            $errors[] = "Field 'options.chunk_size' must be an integer >= 1";
        }

        foreach (['enforce_column_types', 'drop_unknown_tables', 'disable_foreign_key_checks'] as $boolField) {
            if (! array_key_exists($boolField, $options) || ! is_bool($options[$boolField])) {
                $errors[] = sprintf("Field 'options.%s' must be a boolean", $boolField);
            }
        }

        if (! array_key_exists('faker_locale', $options) || ! is_string($options['faker_locale'])) {
            $errors[] = "Field 'options.faker_locale' must be a string";
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $tables
     * @return list<string>
     */
    private function validateTables(array $tables): array
    {
        $errors = [];

        foreach ($tables as $tableName => $tableConfig) {
            $prefix = sprintf("Table '%s'", $tableName);

            if (! is_array($tableConfig)) {
                $errors[] = sprintf('%s: must be an object', $prefix);

                continue;
            }

            /** @var array<string, mixed> $typedTableConfig */
            $typedTableConfig = $tableConfig;

            // Validate rows
            $rows = $typedTableConfig['rows'] ?? null;

            if (! is_array($rows)) {
                $errors[] = sprintf("%s: missing 'rows' configuration", $prefix);
            } else {
                /** @var array<string, mixed> $typedRows */
                $typedRows = $rows;
                $errors = array_merge($errors, $this->validateRows($prefix, $typedRows));
            }

            // Validate columns (optional)
            $columns = $typedTableConfig['columns'] ?? null;

            if ($columns !== null) {
                if (! is_array($columns)) {
                    $errors[] = sprintf("%s: 'columns' must be an object", $prefix);
                } else {
                    /** @var array<string, mixed> $typedColumns */
                    $typedColumns = $columns;
                    $errors = array_merge($errors, $this->validateColumns($prefix, $typedColumns));
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return list<string>
     */
    private function validateRows(string $prefix, array $rows): array
    {
        $errors = [];

        $strategy = $rows['strategy'] ?? null;

        if (! is_string($strategy) || ! in_array($strategy, self::VALID_ROW_STRATEGIES, true)) {
            $errors[] = sprintf('%s: rows.strategy must be one of: %s', $prefix, implode(', ', self::VALID_ROW_STRATEGIES));

            return $errors;
        }

        // first/last require limit >= 1
        if ($strategy === 'first' || $strategy === 'last') {
            $limit = $rows['limit'] ?? null;

            if (! is_int($limit) || $limit < 1) {
                $errors[] = sprintf("%s: rows.limit must be an integer >= 1 when strategy is '%s'", $prefix, $strategy);
            }
        }

        // clear is optional; when present must be false, 'truncate', or 'delete'
        if (array_key_exists('clear', $rows)) {
            $clear = $rows['clear'];

            if (! in_array($clear, [false, 'truncate', 'delete'], true)) {
                $errors[] = sprintf("%s: rows.clear must be false, 'truncate', or 'delete'", $prefix);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return list<string>
     */
    private function validateColumns(string $tablePrefix, array $columns): array
    {
        $errors = [];

        foreach ($columns as $columnName => $columnConfig) {
            $prefix = sprintf("%s, column '%s'", $tablePrefix, $columnName);

            if (! is_array($columnConfig)) {
                $errors[] = sprintf('%s: must be an object', $prefix);

                continue;
            }

            /** @var array<string, mixed> $typedColumnConfig */
            $typedColumnConfig = $columnConfig;

            $strategy = $typedColumnConfig['strategy'] ?? null;

            if (! is_string($strategy) || ! in_array($strategy, self::VALID_COLUMN_STRATEGIES, true)) {
                $errors[] = sprintf('%s: strategy must be one of: %s', $prefix, implode(', ', self::VALID_COLUMN_STRATEGIES));

                continue;
            }

            $errors = array_merge($errors, $this->validateColumnStrategy($prefix, $strategy, $typedColumnConfig));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $keyRemapping
     * @param  list<string>  $knownTableNames
     * @return list<string>
     */
    private function validateKeyRemapping(array $keyRemapping, array $knownTableNames): array
    {
        $errors = [];
        $tablesRaw = $keyRemapping['tables'] ?? null;

        if ($tablesRaw === null || (is_array($tablesRaw) && $tablesRaw === [])) {
            // Empty or absent tables — treated as if section is absent, no error
            return $errors;
        }

        if (! is_array($tablesRaw)) {
            $errors[] = "Field 'key_remapping.tables' must be a list";

            return $errors;
        }

        $seenTables = [];

        foreach ($tablesRaw as $index => $entry) {
            $prefix = sprintf('key_remapping.tables[%d]', $index);

            if (! is_array($entry)) {
                $errors[] = sprintf('%s: must be an object', $prefix);

                continue;
            }

            /** @var array<string, mixed> $entry */
            $table = $entry['table'] ?? null;
            if (! is_string($table) || $table === '') {
                $errors[] = sprintf("%s: 'table' is required", $prefix);

                continue;
            }

            if (in_array($table, $seenTables, true)) {
                $errors[] = sprintf("%s: duplicate table '%s' in key_remapping.tables", $prefix, $table);

                continue;
            }

            $seenTables[] = $table;

            if (! in_array($table, $knownTableNames, true)) {
                $errors[] = sprintf("%s: table '%s' is not defined in the 'tables' section", $prefix, $table);
            }

            $primaryKey = $entry['primary_key'] ?? null;
            if (! is_string($primaryKey) || $primaryKey === '') {
                $errors[] = sprintf("%s: 'primary_key' is required", $prefix);
            }

            $strategy = $entry['strategy'] ?? null;
            $validStrategies = ['random_integer', 'new_uuid'];
            if (! is_string($strategy) || ! in_array($strategy, $validStrategies, true)) {
                $errors[] = sprintf("%s: 'strategy' must be one of: %s", $prefix, implode(', ', $validStrategies));
            }

            if (($strategy ?? '') === 'random_integer') {
                $rangeMin = $entry['range_min'] ?? 100000;
                $rangeMax = $entry['range_max'] ?? 9999999;
                if (! is_int($rangeMin) || $rangeMin < 1) {
                    $errors[] = sprintf("%s: 'range_min' must be an integer >= 1", $prefix);
                }

                if (! is_int($rangeMax) || $rangeMax < 1) {
                    $errors[] = sprintf("%s: 'range_max' must be an integer >= 1", $prefix);
                }

                if (is_int($rangeMin) && is_int($rangeMax) && $rangeMin >= $rangeMax) {
                    $errors[] = sprintf("%s: 'range_min' must be less than 'range_max'", $prefix);
                }
            }

            $fksRaw = $entry['foreign_keys'] ?? null;
            if ($fksRaw !== null && ! is_array($fksRaw)) {
                $errors[] = sprintf("%s: 'foreign_keys' must be a list", $prefix);
            } elseif (is_array($fksRaw)) {
                foreach ($fksRaw as $fkIndex => $fk) {
                    $fkPrefix = sprintf('%s.foreign_keys[%d]', $prefix, $fkIndex);
                    if (! is_array($fk)) {
                        $errors[] = sprintf('%s: must be an object', $fkPrefix);

                        continue;
                    }

                    /** @var array<string, mixed> $fk */
                    if (! is_string($fk['table'] ?? null) || $fk['table'] === '') {
                        $errors[] = sprintf("%s: 'table' is required", $fkPrefix);
                    }

                    if (! is_string($fk['column'] ?? null) || $fk['column'] === '') {
                        $errors[] = sprintf("%s: 'column' is required", $fkPrefix);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function validateColumnStrategy(string $prefix, string $strategy, array $config): array
    {
        $errors = [];

        switch ($strategy) {
            case 'fake':
                $fakerMethod = $config['faker_method'] ?? null;

                if (! is_string($fakerMethod) || $fakerMethod === '') {
                    $errors[] = sprintf("%s: 'fake' strategy requires 'faker_method'", $prefix);
                } elseif (! in_array($fakerMethod, self::KNOWN_FAKER_METHODS, true)) {
                    $errors[] = sprintf("%s: unknown faker_method '%s'", $prefix, $fakerMethod);
                }

                if (! array_key_exists('faker_arguments', $config) || ! is_array($config['faker_arguments'])) {
                    $errors[] = sprintf("%s: 'fake' strategy requires 'faker_arguments' (use [] for none)", $prefix);
                }

                break;

            case 'hash':
                $algorithm = $config['algorithm'] ?? null;

                if (! is_string($algorithm) || ! in_array($algorithm, self::VALID_HASH_ALGORITHMS, true)) {
                    $errors[] = sprintf("%s: 'hash' strategy requires 'algorithm' (one of: %s)", $prefix, implode(', ', self::VALID_HASH_ALGORITHMS));
                }

                if (! array_key_exists('salt', $config)) {
                    $errors[] = sprintf("%s: 'hash' strategy requires 'salt'", $prefix);
                }

                break;

            case 'mask':
                $visibleChars = $config['visible_chars'] ?? null;

                if (! is_int($visibleChars) || $visibleChars < 0) {
                    $errors[] = sprintf("%s: 'mask' strategy requires 'visible_chars' (integer >= 0)", $prefix);
                }

                $maskChar = $config['mask_char'] ?? null;

                if (! is_string($maskChar) || mb_strlen($maskChar) !== 1) {
                    $errors[] = sprintf("%s: 'mask' strategy requires 'mask_char' (exactly 1 character)", $prefix);
                }

                if (! array_key_exists('preserve_format', $config) || ! is_bool($config['preserve_format'])) {
                    $errors[] = sprintf("%s: 'mask' strategy requires 'preserve_format' (boolean)", $prefix);
                }

                break;

            case 'static':
                if (! array_key_exists('value', $config)) {
                    $errors[] = sprintf("%s: 'static' strategy requires 'value'", $prefix);
                }

                break;

            case 'remapping':
                $argsRaw = $config['arguments'] ?? null;

                if (! is_array($argsRaw) || $argsRaw === []) {
                    $errors[] = sprintf("%s: 'remapping' strategy requires 'arguments' list", $prefix);
                    break;
                }

                // Flatten arguments list into a single map
                $args = [];
                foreach ($argsRaw as $entry) {
                    if (is_array($entry)) {
                        /** @var array<string, mixed> $entry */
                        foreach ($entry as $k => $v) {
                            $args[$k] = $v;
                        }
                    }
                }

                $validUseValues = ['random_integer', 'new_uuid'];
                $use = $args['use'] ?? null;

                if (! is_string($use) || ! in_array($use, $validUseValues, true)) {
                    $errors[] = sprintf("%s: 'remapping' requires argument 'use' (one of: %s)", $prefix, implode(', ', $validUseValues));
                    break;
                }

                if ($use === 'random_integer') {
                    $min = $args['min'] ?? null;
                    $max = $args['max'] ?? null;

                    if (! is_int($min) || $min < 1) {
                        $errors[] = sprintf("%s: 'remapping' requires argument 'min' (integer >= 1)", $prefix);
                    }

                    if (! is_int($max) || $max < 1) {
                        $errors[] = sprintf("%s: 'remapping' requires argument 'max' (integer >= 1)", $prefix);
                    }

                    if (is_int($min) && is_int($max) && $min >= $max) {
                        $errors[] = sprintf("%s: 'remapping' argument 'min' must be less than 'max'", $prefix);
                    }
                }

                break;
        }

        return $errors;
    }
}
