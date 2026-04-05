<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\ColumnDumpData;
use App\Data\Cloning\DumpResultData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Enums\KeyRemappingStrategy;
use Illuminate\Support\Facades\Storage;

class CloningYamlWriter
{
    public function write(DumpResultData $result): string
    {
        $lines = [];

        $lines[] = '# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json';
        $lines[] = 'version: "1"';
        $lines[] = sprintf('connection: %s', $result->connectionName);
        $lines[] = '';
        $lines[] = 'options:';
        $lines[] = '  chunk_size: 1000';
        $lines[] = '  enforce_column_types: false';
        $lines[] = '  drop_unknown_tables: false';
        $lines[] = '  disable_foreign_key_checks: true';
        $lines[] = sprintf('  faker_locale: %s', $result->fakerLocale);
        $lines[] = '';
        $lines[] = 'tables:';

        foreach ($result->tables as $table) {
            $lines[] = sprintf('  %s:', $table->name);
            $lines[] = '    rows:';
            $lines[] = sprintf('      strategy: %s', $table->rowStrategy);
            $lines[] = '      clear: delete';

            if ($table->rowLimit !== null) {
                $lines[] = sprintf('      limit: %d', $table->rowLimit);
            }

            if ($table->sortBy !== null) {
                $lines[] = sprintf('      sort_by: %s', $table->sortBy);
            }

            $krTable = $result->keyRemapping?->getTable($table->name);

            $nonKeepColumns = array_filter(
                $table->columns,
                static fn (ColumnDumpData $col): bool => $col->strategy !== 'keep'
            );

            if ($nonKeepColumns === [] && $krTable === null) {
                $lines[] = '    # no PII detected — no columns listed; all kept as-is';
            } else {
                $lines[] = '    columns:';

                if ($krTable !== null) {
                    $lines[] = '      # Primary Key — remapped';
                    $lines[] = sprintf('      %s:', $krTable->primaryKey);
                    $lines[] = '        strategy: remapping';
                    $lines[] = '        arguments:';
                    $lines[] = sprintf('          - use: %s', $krTable->strategy->value);

                    if ($krTable->strategy === KeyRemappingStrategy::RandomInteger) {
                        $lines[] = sprintf('          - min: %d', $krTable->rangeMin);
                        $lines[] = sprintf('          - max: %d', $krTable->rangeMax);
                    }

                    if ($krTable->foreignKeys !== []) {
                        $fkYaml = array_map(
                            static fn (KeyRemappingForeignKeyData $fk): string => sprintf(
                                '{table: %s, column: %s, self_referential: %s}',
                                $fk->table,
                                $fk->column,
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

                    $lines[] = sprintf('      %s:', $column->name);
                    $lines[] = sprintf('        strategy: %s', $column->strategy);

                    switch ($column->strategy) {
                        case 'fake':
                            $lines[] = sprintf('        faker_method: %s', $column->fakerMethod ?? '');
                            $args = $this->encodeYamlInlineList($column->fakerArguments);
                            $lines[] = sprintf('        faker_arguments: %s', $args);
                            break;

                        case 'hash':
                            $lines[] = sprintf('        algorithm: %s', $column->hashAlgorithm ?? 'sha256');
                            $lines[] = sprintf('        salt: "%s"', addslashes($column->hashSalt ?? ''));
                            break;

                        case 'mask':
                            $lines[] = sprintf('        visible_chars: %d', $column->visibleChars ?? 0);
                            $lines[] = sprintf('        mask_char: "%s"', addslashes($column->maskChar ?? '*'));
                            $lines[] = sprintf('        preserve_format: %s', ($column->preserveFormat ?? false) ? 'true' : 'false');
                            break;

                        case 'static':
                            $lines[] = sprintf('        value: %s', $this->encodeYamlScalar($column->staticValue));
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
            return '[]';
        }

        $encoded = array_map($this->encodeYamlScalar(...), $items);

        return '['.implode(', ', $encoded).']';
    }

    private function encodeYamlScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
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
            return '"'.addslashes($str).'"';
        }

        return $str;
    }

    private function needsQuoting(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        // Contains special YAML characters or control characters
        if (preg_match('/[:#\[\]{}|>&*!,\'"\\\\]/', $value)) {
            return true;
        }

        // Contains actual newlines or carriage returns
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            return true;
        }

        // Leading/trailing whitespace
        if ($value !== trim($value)) {
            return true;
        }

        // Looks like a boolean
        if (in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off', 'null', '~'], true)) {
            return true;
        }

        // Looks like a number
        return is_numeric($value);
    }
}
