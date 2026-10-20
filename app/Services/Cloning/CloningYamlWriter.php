<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\ColumnDumpData;
use App\Data\Cloning\DumpResultData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class CloningYamlWriter
{
    public function write(DumpResultData $result): string
    {
        $lines = [];

        $lines[] = '# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json';
        $lines[] = 'version: "1"';
        $lines[] = sprintf('connection: %s', $this->encodeYamlScalar($result->connectionName));
        $lines[] = '';
        $lines[] = 'options:';
        $lines[] = '  chunk_size: 1000';
        $lines[] = sprintf('  enforce_column_types: %s', $result->enforceColumnTypes ? 'true' : 'false');
        $lines[] = sprintf('  drop_unknown_tables: %s', $result->dropUnknownTables ? 'true' : 'false');
        $lines[] = sprintf('  drop_extra_columns: %s', $result->dropExtraColumns ? 'true' : 'false');
        $lines[] = sprintf('  recreate_tables: %s', $result->recreateTables ? 'true' : 'false');
        $lines[] = sprintf('  disable_foreign_key_checks: %s', $result->disableForeignKeyChecks ? 'true' : 'false');
        $lines[] = sprintf('  remap_keys: %s', $result->remapKeys ? 'true' : 'false');
        $lines[] = sprintf('  faker_locale: %s', $this->encodeYamlScalar($result->fakerLocale));
        $lines[] = '';
        $lines[] = 'tables:';

        foreach ($result->tables as $table) {
            $lines[] = sprintf('  %s:', $this->encodeYamlScalar($table->name));
            $lines[] = '    rows:';
            $lines[] = sprintf('      strategy: %s', $this->encodeYamlScalar($table->rowStrategy));
            $lines[] = '      clear: delete';

            if ($table->rowLimit !== null) {
                $lines[] = sprintf('      limit: %d', $table->rowLimit);
            }

            if ($table->sortBy !== null) {
                $lines[] = sprintf('      sort_by: %s', $this->encodeYamlScalar($table->sortBy));
            }

            $krTable = $result->keyRemapping?->getTable($table->name);

            $nonKeepColumns = $result->includeKeepColumns
                ? $table->columns
                : array_filter(
                    $table->columns,
                    static fn (ColumnDumpData $col): bool => $col->strategy !== 'keep'
                );

            // Exclude the primary key column when key remapping handles it,
            // to avoid duplicate entries in the YAML output.
            if ($krTable instanceof KeyRemappingTableData) {
                $nonKeepColumns = array_filter(
                    $nonKeepColumns,
                    static fn (ColumnDumpData $col): bool => $col->name !== $krTable->primaryKey
                );
            }

            if ($nonKeepColumns === [] && ! $krTable instanceof KeyRemappingTableData) {
                $lines[] = '    # no PII detected — no columns listed; all kept as-is';
            } else {
                $lines[] = '    columns:';

                if ($krTable instanceof KeyRemappingTableData) {
                    $lines[] = '      # Primary Key — remapped';
                    $lines[] = sprintf('      %s:', $this->encodeYamlScalar($krTable->primaryKey));
                    $lines[] = '        strategy: remapping';
                    $lines[] = '        arguments:';
                    $lines[] = sprintf('          - use: %s', $krTable->strategy->value);

                    if ($krTable->foreignKeys !== []) {
                        $fkYaml = array_map(
                            fn (KeyRemappingForeignKeyData $fk): string => sprintf(
                                '{table: %s, column: %s, self_referential: %s}',
                                $this->encodeYamlScalar($fk->table),
                                $this->encodeYamlScalar($fk->column),
                                $fk->selfReferential ? 'true' : 'false'
                            ),
                            $krTable->foreignKeys
                        );
                        $lines[] = '          - foreign_keys: ['.implode(', ', $fkYaml).']';
                    } else {
                        $lines[] = '          - foreign_keys: []';
                    }
                }

                foreach ($nonKeepColumns as $column) {
                    if ($column->piiDetected && $column->piiCategory !== null) {
                        $lines[] = sprintf('      # PII: %s', $column->piiCategory);
                    }

                    $lines[] = sprintf('      %s:', $this->encodeYamlScalar($column->name));
                    $lines[] = sprintf('        strategy: %s', $this->encodeYamlScalar($column->strategy));

                    switch ($column->strategy) {
                        case 'fake':
                            $lines[] = sprintf('        faker_method: %s', $this->encodeYamlScalar($column->fakerMethod));
                            $args = $this->encodeYamlInlineList($column->fakerArguments);
                            $lines[] = sprintf('        faker_arguments: %s', $args);
                            break;

                        case 'hash':
                            $lines[] = sprintf('        algorithm: %s', $this->encodeYamlScalar($column->hashAlgorithm ?? 'sha256'));
                            // Salt is only written when explicitly configured.
                            // An absent 'salt' key tells the engine to apply
                            // a per-run random salt (GDPR-aligned default).
                            if ($column->hashSalt !== null) {
                                $lines[] = sprintf('        salt: %s', $this->quote($column->hashSalt));
                            }

                            break;

                        case 'mask':
                            $lines[] = sprintf('        visible_chars: %d', $column->visibleChars ?? 0);
                            $lines[] = sprintf('        mask_char: %s', $this->quote($column->maskChar ?? '*'));
                            $lines[] = sprintf('        preserve_format: %s', ($column->preserveFormat ?? false) ? 'true' : 'false');
                            break;

                        case 'static':
                            $lines[] = sprintf('        value: %s', $this->encodeYamlScalar($column->staticValue));
                            break;

                        case 'template':
                            $lines[] = sprintf('        template: %s', $this->encodeYamlScalar($column->template));
                            break;

                        case 'keep':
                        case 'exclude':
                            // no extra fields needed
                            break;
                    }
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    public function writeToFile(DumpResultData $result, string $path): void
    {
        $yaml = $this->write($result);
        Storage::disk('local')->put($path, $yaml);
    }

    /** @param list<scalar> $items */
    private function encodeYamlInlineList(array $items): string
    {
        if ($items === []) {
            return '[]'; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $encoded = array_map($this->encodeYamlScalar(...), $items);

        return '['.implode(', ', $encoded).']';
    }

    private function encodeYamlScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null'; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return 'null';
        }

        $str = $value;

        // If the string needs quoting (contains special chars, looks like bool/null/number)
        if ($this->needsQuoting($str)) {
            return $this->quote($str);
        }

        return $str;
    }

    /**
     * Double-quoted YAML scalar. addslashes() is not YAML-safe: it turns ' into
     * \', which is an invalid escape in double-quoted YAML.
     */
    private function quote(string $value): string
    {
        $escaped = strtr($value, ['\\' => '\\\\', '"' => '\\"']);
        $escaped = (string) preg_replace_callback( // @pest-mutate-ignore: RemoveStringCast
            '/[\x00-\x1F\x7F]/',
            static fn (array $m): string => match ($m[0]) {
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\x%02X', ord($m[0])),
            },
            $escaped,
        );

        return '"'.$escaped.'"';
    }

    private function needsQuoting(string $value): bool
    {
        if ($value === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return true; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Contains special YAML characters or control characters
        if (preg_match('/[:#\[\]{}|>&*!,\'"\\\\]/', $value)) {
            return true;
        }

        // Contains actual newlines or carriage returns
        if (str_contains($value, "\n") || str_contains($value, "\r")) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            return true; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Leading/trailing whitespace
        if ($value !== trim($value)) { // @pest-mutate-ignore: UnwrapTrim
            return true; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Looks like a boolean. true/false/null/~ are also caught by the
        // parse round-trip below; yes/no/on/off are YAML 1.1 booleans only.
        if (in_array(strtolower($value), [
            'true', // @pest-mutate-ignore: RemoveArrayItem
            'false', // @pest-mutate-ignore: RemoveArrayItem
            'yes',
            'no',
            'on',
            'off',
            'null', // @pest-mutate-ignore: RemoveArrayItem
            '~', // @pest-mutate-ignore: RemoveArrayItem
        ], true)) {
            return true;
        }

        // Looks like a number
        if (is_numeric($value)) {
            return true;
        }

        // Leading indicator characters (sequence, mapping, directive, reserved)
        if (preg_match('/^[-?%@`]/', $value)) {
            return true;
        }

        // Anything else the parser would not read back verbatim, e.g. dates
        // (parsed to timestamps), .inf, 0x1A, 1_000.
        try {
            return Yaml::parse('k: '.$value) !== ['k' => $value];
        } catch (ParseException) {
            return true;
        }
    }
}
