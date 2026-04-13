# YAML-Level Table Skipping

**Date:** 2026-04-13
**Status:** Approved
**Issue:** [#64](https://github.com/clonio-dev/clonio-cli/issues/64)

## Problem

Tables can only be excluded from a run via `--skip-tables` and `--only-tables` CLI flags. There is no way to declare in the `cloning.yaml` itself that a table should always be skipped, requiring users to remember and repeat the flag on every invocation.

## Goals

- Allow tables to be marked for skipping inside `cloning.yaml`, so they are excluded automatically on every run without a CLI flag
- Support two complementary syntaxes: a top-level `skip:` list and a per-table `rows.strategy: skip`
- Produce identical runtime behaviour to `--skip-tables`: cascade exclusions apply (FK-dependent tables are also skipped)
- YAML-level skips and `--skip-tables` are additive — both lists are merged at runtime

## YAML Syntax

### Top-level `skip:` list

For tables that need no other configuration:

```yaml
skip:
  - audit_logs
  - telescope_entries
  - failed_jobs
```

### Per-table `rows.strategy: skip`

For tables already present in `tables:`:

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
```

### Conflict resolution

- If a table appears in both `skip:` and `tables:` with `strategy: skip` → skipped once (deduplicated)
- If a table appears in `skip:` and `tables:` with a non-skip strategy → `skip:` wins, table is skipped

## Architecture

### `CloningConfigData`

New field:

```php
/** @param list<string> $skipTables */
public readonly array $skipTables = []
```

Populated by the loader from both sources, deduplicated.

### `CloningYamlLoader::mapToDto()`

Two additions:

1. Parse `$data['skip']` as `list<string>` — ignore non-array / non-string entries gracefully
2. After building `$tables`, scan for entries where `rows.strategy === 'skip'` and add those table names to the skip list
3. Merge and deduplicate both sources into `CloningConfigData::$skipTables`

### `CloningRunOrchestrator`

No changes to parameters. `RunCommand` merges `$config->skipTables` with the CLI `--skip-tables` list before passing to the orchestrator. The orchestrator's existing skip + cascade exclusion logic handles the rest unchanged.

### `CloningYamlValidator`

Two additions:

- `skip:` must be a list of strings if present; fail validation with a clear error message if not
- `rows.strategy: skip` is added to the set of accepted strategy values

### `CloningYamlWriter`

No changes needed — if a table config has `strategy: skip`, it is written faithfully. The writer does not generate top-level `skip:` entries (it generates from source schema).

## Files Changed

| File | Change |
|---|---|
| `app/Data/Cloning/CloningConfigData.php` | Add `$skipTables` field |
| `app/Services/Cloning/CloningYamlLoader.php` | Parse `skip:` list and collect `strategy: skip` tables |
| `app/Services/Cloning/CloningYamlValidator.php` | Validate `skip:` and accept `strategy: skip` |
| `app/Commands/Cloning/RunCommand.php` | Merge `$config->skipTables` with CLI `--skip-tables` before orchestrator call |
| `tests/Unit/Services/Cloning/CloningYamlLoaderTest.php` | Tests for both skip syntaxes, deduplication, conflict resolution |
| `tests/Unit/Services/Cloning/CloningYamlValidatorTest.php` | Validation tests for `skip:` and `strategy: skip` |
| `tests/Unit/Services/Cloning/CloningRunOrchestratorTest.php` | Test that `config->skipTables` entries are excluded with cascade |
| `docs/commands/cloning-run.md` | Document YAML-level skip syntax and additive merge with `--skip-tables` |

## Test Plan

- `skip:` list is parsed into `CloningConfigData::$skipTables`
- `rows.strategy: skip` entries are collected into `$skipTables`
- Both sources are merged and deduplicated
- A table in `skip:` with a non-skip strategy in `tables:` → still skipped (skip wins)
- Invalid `skip:` value (non-string entry) → ignored gracefully; non-array `skip:` → validation error
- `rows.strategy: skip` is accepted by validator
- Tables from `config->skipTables` are excluded in orchestrator with cascade
- YAML-level skips and CLI `--skip-tables` are additive (both applied)
