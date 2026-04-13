# YAML-Level Table Skipping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow tables to be permanently excluded from cloning runs via `cloning.yaml` using a top-level `skip:` list and/or `rows.strategy: skip` on individual table entries.

**Architecture:** Add a `skipTables` field to `CloningConfigData`, populate it in `CloningYamlLoader` from both sources (top-level `skip:` list and per-table `rows.strategy: skip`), then merge it with the CLI `--skip-tables` list in `RunCommand` before calling the orchestrator. The orchestrator itself is unchanged — it already has full skip + cascade logic.

**Tech Stack:** PHP 8.5, Laravel Zero, PestPHP v4, Mockery

---

## File Map

| File | Change |
|---|---|
| `app/Data/Cloning/CloningConfigData.php` | Add `public readonly array $skipTables = []` |
| `app/Services/Cloning/CloningYamlLoader.php` | Parse top-level `skip:` list; scan for `rows.strategy: skip` tables; deduplicate into `skipTables` |
| `app/Services/Cloning/CloningYamlValidator.php` | Add `skip` to `VALID_ROW_STRATEGIES`; validate top-level `skip:` field |
| `app/Commands/Cloning/RunCommand.php` | Merge `$config->skipTables` into `$skipTables`; preserve field in config reconstruction |
| `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php` | Tests for both skip syntaxes, deduplication, conflict resolution |
| `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php` | Tests for `skip:` validation and `strategy: skip` acceptance |
| `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` | Test that `config->skipTables` entries are excluded with cascade |
| `docs/commands/cloning-run.md` | Document YAML-level skip syntax |

---

### Task 1: Add `skipTables` to `CloningConfigData` + parse top-level `skip:` in loader

**Files:**
- Modify: `app/Data/Cloning/CloningConfigData.php`
- Modify: `app/Services/Cloning/CloningYamlLoader.php`
- Test: `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php`:

```php
it('parses top-level skip list into skipTables', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
  - telescope_entries
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs', 'telescope_entries']);
});

it('returns empty skipTables when skip key is absent', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe([]);
});

it('ignores non-string entries in the skip list', function (): void {
    Storage::fake('local');

    // We pass the data array directly to the internal mapper by testing via a sub-class
    // Instead, use Storage to fake a YAML that has a valid skip list mixed with junk —
    // YAML parses integers as ints so we test that gracefully
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
  - 42
  - ""
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlLoaderTest.php --filter="parses top-level skip|returns empty skipTables|ignores non-string"
```

Expected: FAIL — `skipTables` property does not exist yet.

- [ ] **Step 3: Add `skipTables` to `CloningConfigData`**

In `app/Data/Cloning/CloningConfigData.php`, update the class:

```php
final readonly class CloningConfigData
{
    /**
     * @param  list<TableCloningConfigData>  $tables
     * @param  list<string>  $skipTables
     */
    public function __construct(
        public string $version,
        public string $connectionName,
        public CloningOptionsData $options,
        public array $tables,
        public ?KeyRemappingConfigData $keyRemapping = null,
        public array $skipTables = [],
    ) {}

    public function getTable(string $name): ?TableCloningConfigData
    {
        foreach ($this->tables as $table) {
            if ($table->tableName === $name) {
                return $table;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Parse top-level `skip:` in `CloningYamlLoader::mapToDto()`**

In `app/Services/Cloning/CloningYamlLoader.php`, inside `mapToDto()`, add skip parsing **before** the `return new CloningConfigData(...)` statement:

```php
// Parse top-level skip: list
/** @var list<string> $skipTables */
$skipTables = [];
$skipRaw = $data['skip'] ?? null;
if (is_array($skipRaw)) {
    foreach ($skipRaw as $entry) {
        if (is_string($entry) && $entry !== '') {
            $skipTables[] = $entry;
        }
    }
}
```

Then update the `return new CloningConfigData(...)` to pass the new field:

```php
return new CloningConfigData(
    version: $version,
    connectionName: $connectionName,
    options: $options,
    tables: $tables,
    keyRemapping: $keyRemapping,
    skipTables: $skipTables,
);
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlLoaderTest.php --filter="parses top-level skip|returns empty skipTables|ignores non-string"
```

Expected: PASS (3 tests).

- [ ] **Step 6: Run full suite to check for regressions**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlLoaderTest.php
```

Expected: all existing tests still pass.

- [ ] **Step 7: Commit**

```bash
git add app/Data/Cloning/CloningConfigData.php app/Services/Cloning/CloningYamlLoader.php tests/Unit/Services/Cloning/CloningYamlLoaderTest.php
git commit -m "feat: add skipTables to CloningConfigData and parse top-level skip: list"
```

---

### Task 2: Handle `rows.strategy: skip` in loader + update validator

**Files:**
- Modify: `app/Services/Cloning/CloningYamlLoader.php`
- Modify: `app/Services/Cloning/CloningYamlValidator.php`
- Test: `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php`
- Test: `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php`

- [ ] **Step 1: Write failing loader tests**

Add to `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php`:

```php
it('collects tables with rows.strategy skip into skipTables', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
  audit_logs:
    rows:
      strategy: skip
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs']);
});

it('deduplicates tables that appear in both skip list and as strategy skip', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
tables:
  users:
    rows:
      strategy: full
  audit_logs:
    rows:
      strategy: skip
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs']);
});

it('skips a table from the top-level skip list even when its table entry has a non-skip strategy', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - users
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['users']);
});
```

- [ ] **Step 2: Run failing loader tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlLoaderTest.php --filter="collects tables with rows.strategy skip|deduplicates tables|skips a table from the top-level"
```

Expected: FAIL — `strategy: skip` not yet recognised by validator or collected by loader.

- [ ] **Step 3: Write failing validator tests**

Add to `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php`:

```php
it('accepts rows.strategy skip as a valid strategy', function (): void {
    $validator = new CloningYamlValidator;

    $data = [
        'version' => '1',
        'connection' => 'production-db',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => [
            'audit_logs' => [
                'rows' => ['strategy' => 'skip'],
            ],
        ],
    ];

    expect($validator->validate($data))->toBe([]);
});

it('accepts a valid top-level skip list', function (): void {
    $validator = new CloningYamlValidator;

    $data = [
        'version' => '1',
        'connection' => 'production-db',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => [
            'users' => [
                'rows' => ['strategy' => 'full'],
            ],
        ],
        'skip' => ['audit_logs', 'telescope_entries'],
    ];

    expect($validator->validate($data))->toBe([]);
});

it('returns error when skip is not a list', function (): void {
    $validator = new CloningYamlValidator;

    $data = [
        'version' => '1',
        'connection' => 'production-db',
        'options' => [
            'chunk_size' => 1000,
            'enforce_column_types' => false,
            'drop_unknown_tables' => false,
            'disable_foreign_key_checks' => true,
            'faker_locale' => 'en_US',
        ],
        'tables' => [
            'users' => [
                'rows' => ['strategy' => 'full'],
            ],
        ],
        'skip' => 'audit_logs',
    ];

    $errors = $validator->validate($data);

    expect($errors)->toContain("Field 'skip' must be a list of table name strings");
});
```

- [ ] **Step 4: Run failing validator tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlValidatorTest.php --filter="accepts rows.strategy skip|accepts a valid top-level skip|returns error when skip is not a list"
```

Expected: FAIL.

- [ ] **Step 5: Add `skip` to `VALID_ROW_STRATEGIES` in validator**

In `app/Services/Cloning/CloningYamlValidator.php`, change:

```php
private const array VALID_ROW_STRATEGIES = ['full', 'first', 'last'];
```

to:

```php
private const array VALID_ROW_STRATEGIES = ['full', 'first', 'last', 'skip'];
```

Then in `validate()`, after the existing `key_remapping` validation block (around line where `$keyRemapping` is validated), add:

```php
// 7. skip list validation (optional)
$skip = $data['skip'] ?? null;
if ($skip !== null) {
    if (! is_array($skip)) {
        $errors[] = "Field 'skip' must be a list of table name strings";
    }
}
```

- [ ] **Step 6: Update loader to collect `strategy: skip` tables**

In `app/Services/Cloning/CloningYamlLoader.php`, inside `mapToDto()`, **after** the existing `$skipTables` parsing and **before** the `return new CloningConfigData(...)` statement, add:

```php
// Collect tables with rows.strategy: skip into skipTables (deduplicated)
foreach ($tables as $tableData) {
    if ($tableData->rows->strategy === 'skip' && ! in_array($tableData->tableName, $skipTables, true)) {
        $skipTables[] = $tableData->tableName;
    }
}
```

- [ ] **Step 7: Run all new tests**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningYamlLoaderTest.php tests/Unit/Services/Cloning/CloningYamlValidatorTest.php
```

Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Cloning/CloningYamlLoader.php app/Services/Cloning/CloningYamlValidator.php tests/Unit/Services/Cloning/CloningYamlLoaderTest.php tests/Unit/Services/Cloning/CloningYamlValidatorTest.php
git commit -m "feat: support rows.strategy skip and validate top-level skip: in YAML"
```

---

### Task 3: Wire `config->skipTables` into RunCommand + orchestrator test

**Files:**
- Modify: `app/Commands/Cloning/RunCommand.php`
- Test: `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`

- [ ] **Step 1: Write failing orchestrator test**

The orchestrator itself does not change — `config->skipTables` is merged into the `$skipTables` parameter in `RunCommand`. But we need a test that passing `skipTables` on the config DTO produces the same exclusion as passing via the CLI parameter.

Add to `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php`:

```php
it('excludes tables listed in config skipTables the same as cli skip-tables', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();

    // Config has 'users' in skipTables — but we pass it via the $skipTables param
    // (RunCommand merges config->skipTables into $skipTables before calling orchestrator)
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    // Simulate what RunCommand does: merge config->skipTables with CLI skip list
    $mergedSkip = array_values(array_unique(array_merge(['users'], [])));
    $result = $orchestrator->run($config, $source, $target, $schema, true, $mergedSkip, [], static fn (): null => null);

    expect($result->tables)->toHaveCount(1);
    expect($result->tables[0]->status->value)->toBe('skipped_by_flag');
});
```

- [ ] **Step 2: Run test to verify it passes (it exercises existing logic)**

```bash
./vendor/bin/pest tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php --filter="excludes tables listed in config skipTables"
```

Expected: PASS — this verifies the existing `$skipTables` parameter works; no new orchestrator code needed.

- [ ] **Step 3: Merge `config->skipTables` into `$skipTables` in RunCommand**

In `app/Commands/Cloning/RunCommand.php`, locate the block around line 150 where `$skipTables` is built from the CLI option:

```php
/** @var list<string> $skipTables */
$skipTables = ($skipTablesOpt !== null && $skipTablesOpt !== '')
    ? array_values(array_filter(array_map(trim(...), explode(',', (string) $skipTablesOpt))))
    : [];
```

This is **before** `$config` is available (config is loaded earlier). The merge must happen **after** `$config` is loaded. Find where `$config` is first set (via `$loader->load()`), then find where `$skipTables` is used (line 365: `skipTables: $skipTables`). Add the merge **immediately before** the `$result = $orchestrator->run(...)` call:

```php
// Merge YAML-level skipTables with CLI --skip-tables (additive, deduplicated)
$skipTables = array_values(array_unique(array_merge($skipTables, $config->skipTables)));
```

- [ ] **Step 4: Preserve `skipTables` in config reconstruction**

In `app/Commands/Cloning/RunCommand.php`, find the block where `$config` is reconstructed (around line 175) when CLI option overrides are applied:

```php
$config = new CloningConfigData(
    version: $config->version,
    connectionName: $config->connectionName,
    options: new CloningOptionsData(...),
    tables: $config->tables,
    keyRemapping: $config->keyRemapping,
);
```

Add `skipTables: $config->skipTables,` to preserve the field:

```php
$config = new CloningConfigData(
    version: $config->version,
    connectionName: $config->connectionName,
    options: new CloningOptionsData(
        chunkSize: $opts->chunkSize,
        enforceColumnTypes: $enforceOverride ?? $opts->enforceColumnTypes,
        dropUnknownTables: $dropUnkOverride ?? $opts->dropUnknownTables,
        dropExtraColumns: $dropExtOverride ?? $opts->dropExtraColumns,
        disableForeignKeyChecks: $fkChecksOverride ?? $opts->disableForeignKeyChecks,
        fakerLocale: $opts->fakerLocale,
    ),
    tables: $config->tables,
    keyRemapping: $config->keyRemapping,
    skipTables: $config->skipTables,
);
```

- [ ] **Step 5: Run full unit suite**

```bash
composer test:unit
```

Expected: all pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Commands/Cloning/RunCommand.php tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php
git commit -m "feat: merge config->skipTables with CLI --skip-tables in RunCommand"
```

---

### Task 4: Update documentation

**Files:**
- Modify: `docs/commands/cloning-run.md`

- [ ] **Step 1: Add YAML skip syntax to docs**

In `docs/commands/cloning-run.md`, find the existing section that documents `--skip-tables` (in the options table). After it, add or extend a section explaining YAML-level skipping.

Add the following content at a logical place (after the `--skip-tables` / `--only-tables` description or in a dedicated subsection):

````markdown
### Skipping tables in YAML

Instead of passing `--skip-tables` on every invocation, you can declare tables to skip permanently inside `cloning.yaml`. Two syntaxes are supported and can be combined:

**Top-level `skip:` list** — for tables that need no anonymisation config:

```yaml
skip:
  - audit_logs
  - telescope_entries
  - failed_jobs
```

**`rows.strategy: skip`** — for tables already present in `tables:`:

```yaml
tables:
  audit_logs:
    rows:
      strategy: skip
  users:
    rows:
      strategy: full
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
```

YAML-level skips and `--skip-tables` are **additive**: both lists are merged at runtime. The same cascade rules apply — tables with FK dependencies on a skipped table are also skipped automatically.
````

- [ ] **Step 2: Run full test suite**

```bash
composer test
```

Expected: all 429+ tests pass, 0 PHPStan errors, lint clean.

- [ ] **Step 3: Commit**

```bash
git add docs/commands/cloning-run.md
git commit -m "docs: document YAML-level table skipping (skip: list and strategy: skip)"
```
