<?php

declare(strict_types=1);

namespace App\Commands\Cloning;

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Yaml\Yaml;

class TableEditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cloning:table:edit
        {file?              : Path to the .cloning.yaml file}
        {--table=           : Table name}
        {--rows-strategy=   : Row strategy (full, first, last, skip)}
        {--rows-limit=      : (first/last) Number of rows}
        {--rows-sort-by=    : (first/last) Column to sort by}
        {--rows-clear=      : Clear mode (none, truncate, delete)}';

    /**
     * @var string
     */
    protected $description = 'Edit a table strategy in a cloning configuration YAML file';

    /** @var array<string, string> */
    private const array ROW_STRATEGY_LABELS = [
        'full' => 'Full — copy every row',
        'first' => 'First N — copy the first N rows after sorting',
        'last' => 'Last N — copy the last N rows after sorting',
        'skip' => "Skip — don't transfer this table",
    ];

    /** @var array<string, string> */
    private const array CLEAR_MODE_LABELS = [
        'none' => 'None — leave existing target rows',
        'truncate' => 'Truncate — fast wipe before insert (no FKs)',
        'delete' => 'Delete — DELETE FROM before insert (FK-safe)',
    ];

    public function handle(): int
    {
        // ─── Resolve file ──────────────────────────────────────────────────
        $fileArg = $this->argument('file');
        $filePath = is_string($fileArg) && $fileArg !== '' ? $fileArg : null;

        if ($filePath === null) {
            $allFiles = Storage::disk('local')->files('.');
            $files = array_values(array_filter(
                $allFiles,
                static fn (mixed $f): bool => is_string($f) && str_ends_with($f, '.cloning.yaml'),
            ));

            if ($files === []) {
                $this->error('No .cloning.yaml files found in the current directory.');

                return ExitCode::IoError->value;
            }

            if (count($files) === 1) {
                $filePath = $files[0];
            } else {
                $chosen = $this->choice('Select a cloning YAML file', $files, 0);
                $filePath = is_string($chosen) ? $chosen : $files[0];
            }
        }

        /** @var string $filePath */
        if (! Storage::disk('local')->exists($filePath)) {
            $this->error(sprintf('File not found: %s', $filePath));

            return ExitCode::IoError->value;
        }

        $content = Storage::disk('local')->get($filePath);

        if (! is_string($content)) {
            $this->error(sprintf('Cannot read file: %s', $filePath));

            return ExitCode::IoError->value;
        }

        $data = Yaml::parse($content);

        if (! is_array($data) || ! is_array($data['tables'] ?? null)) {
            $this->error('Invalid cloning YAML: missing tables section.');

            return ExitCode::ValidationError->value;
        }

        /** @var array<string, mixed> $data */
        /** @var array<string, mixed> $tables */
        $tables = $data['tables'];

        // ─── Resolve table ─────────────────────────────────────────────────
        $tableOption = $this->option('table');
        $tableName = is_string($tableOption) && $tableOption !== '' ? $tableOption : null;

        if ($tableName === null) {
            $tableNames = array_keys($tables);

            if ($tableNames === []) {
                $asked = $this->ask('Table name (no tables listed yet — enter the name to add)');
                $tableName = is_string($asked) ? $asked : '';
            } else {
                $choices = $tableNames;
                $choices[] = '+ Add new table';
                $chosen = $this->choice('Select a table', $choices, 0);

                if ($chosen === '+ Add new table') {
                    $asked = $this->ask('Table name');
                    $tableName = is_string($asked) ? $asked : '';
                } else {
                    $tableName = is_string($chosen) ? $chosen : $tableNames[0];
                }
            }
        }

        if ($tableName === '') {
            $this->error('Table name cannot be empty.');

            return ExitCode::ValidationError->value;
        }

        $existingTableConfig = is_array($tables[$tableName] ?? null) ? $tables[$tableName] : [];
        /** @var array<string, mixed> $existingTableConfig */
        $existingRowsConfig = is_array($existingTableConfig['rows'] ?? null) ? $existingTableConfig['rows'] : [];
        /** @var array<string, mixed> $existingRowsConfig */
        $currentStrategy = is_string($existingRowsConfig['strategy'] ?? null) ? $existingRowsConfig['strategy'] : null;
        $currentLimit = is_int($existingRowsConfig['limit'] ?? null) ? $existingRowsConfig['limit'] : null;
        $currentSortBy = is_string($existingRowsConfig['sort_by'] ?? null) ? $existingRowsConfig['sort_by'] : null;
        $currentClear = $existingRowsConfig['clear'] ?? false;
        $currentClearKey = $currentClear === false ? 'none' : (is_string($currentClear) ? $currentClear : 'none');

        if ($currentStrategy !== null) {
            $this->line(sprintf('  Current strategy for <info>%s</info>: <comment>%s</comment>', $tableName, $currentStrategy));
            $this->line('');
        }

        // ─── Resolve strategy ──────────────────────────────────────────────
        $strategyOption = $this->option('rows-strategy');
        $strategy = is_string($strategyOption) && $strategyOption !== '' ? $strategyOption : null;

        if ($strategy === null) {
            $defaultKey = $currentStrategy ?? 'full';
            $defaultIdx = (int) array_search($defaultKey, array_keys(self::ROW_STRATEGY_LABELS), true);
            $chosen = $this->choice('Select a row strategy', array_values(self::ROW_STRATEGY_LABELS), $defaultIdx);
            $resolved = is_string($chosen) ? array_search($chosen, self::ROW_STRATEGY_LABELS, true) : false;
            $strategy = is_string($resolved) ? $resolved : 'full';
        }

        if (! array_key_exists($strategy, self::ROW_STRATEGY_LABELS)) {
            $this->error(sprintf("Unknown row strategy: '%s'. Valid: %s", $strategy, implode(', ', array_keys(self::ROW_STRATEGY_LABELS))));

            return ExitCode::ValidationError->value;
        }

        // ─── Resolve limit + sort_by (only for first/last) ─────────────────
        $limit = null;
        $sortBy = null;

        if ($strategy === 'first' || $strategy === 'last') {
            $limitOption = $this->option('rows-limit');

            if (is_string($limitOption) && $limitOption !== '') {
                if (! ctype_digit($limitOption) || (int) $limitOption < 1) {
                    $this->error(sprintf("--rows-limit must be a positive integer (got '%s').", $limitOption));

                    return ExitCode::ValidationError->value;
                }

                $limit = (int) $limitOption;
            } else {
                $defaultLimit = $currentLimit !== null ? (string) $currentLimit : '100';
                $answer = $this->ask('Row limit', $defaultLimit);
                $limitStr = is_string($answer) ? trim($answer) : '';

                if (! ctype_digit($limitStr) || (int) $limitStr < 1) {
                    $this->error('Row limit must be a positive integer.');

                    return ExitCode::ValidationError->value;
                }

                $limit = (int) $limitStr;
            }

            $sortOption = $this->option('rows-sort-by');

            if (is_string($sortOption) && $sortOption !== '') {
                $sortBy = $sortOption;
            } else {
                $answer = $this->ask('Sort by column (optional, leave blank to skip)', $currentSortBy ?? '');
                $sortBy = is_string($answer) && $answer !== '' ? $answer : null;
            }
        }

        // ─── Resolve clear mode ────────────────────────────────────────────
        $clearOption = $this->option('rows-clear');
        $clearKey = is_string($clearOption) && $clearOption !== '' ? $clearOption : null;

        if ($clearKey === null) {
            $defaultIdx = (int) array_search($currentClearKey, array_keys(self::CLEAR_MODE_LABELS), true);
            $chosen = $this->choice('Select a clear mode', array_values(self::CLEAR_MODE_LABELS), $defaultIdx);
            $resolved = is_string($chosen) ? array_search($chosen, self::CLEAR_MODE_LABELS, true) : false;
            $clearKey = is_string($resolved) ? $resolved : 'none';
        }

        if (! array_key_exists($clearKey, self::CLEAR_MODE_LABELS)) {
            $this->error(sprintf("Unknown clear mode: '%s'. Valid: %s", $clearKey, implode(', ', array_keys(self::CLEAR_MODE_LABELS))));

            return ExitCode::ValidationError->value;
        }

        // ─── Summary ──────────────────────────────────────────────────────
        $this->line('');
        $rows = [
            ['Table', $tableName],
            ['Strategy', self::ROW_STRATEGY_LABELS[$strategy]],
        ];

        if ($limit !== null) {
            $rows[] = ['Limit', (string) $limit];
        }

        if ($sortBy !== null) {
            $rows[] = ['Sort by', $sortBy];
        }

        $rows[] = ['Clear mode', self::CLEAR_MODE_LABELS[$clearKey]];

        $this->table(['Field', 'Value'], $rows);

        if (! $this->confirm('Apply this change?', true)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        // ─── Build new rows config ─────────────────────────────────────────
        /** @var array<string, mixed> $newRows */
        $newRows = ['strategy' => $strategy];

        if ($limit !== null) {
            $newRows['limit'] = $limit;
        }

        if ($sortBy !== null) {
            $newRows['sort_by'] = $sortBy;
        }

        $newRows['clear'] = $clearKey === 'none' ? false : $clearKey;

        // Preserve any other rows.* keys we don't manage explicitly.
        foreach ($existingRowsConfig as $key => $value) {
            if (! array_key_exists($key, $newRows) && ! in_array($key, ['limit', 'sort_by', 'clear'], true)) {
                $newRows[$key] = $value;
            }
        }

        $existingTableConfig['rows'] = $newRows;
        $tables[$tableName] = $existingTableConfig;
        $data['tables'] = $tables;

        $yaml = Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        Storage::disk('local')->put($filePath, $yaml);

        $this->info(sprintf('Updated %s → strategy: %s in %s', $tableName, $strategy, $filePath));

        return ExitCode::Success->value;
    }
}
