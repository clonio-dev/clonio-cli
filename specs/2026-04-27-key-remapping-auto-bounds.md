# Spec — Key Remapping with Automatic Type-Aware Bounds

**Date:** 2026-04-27
**Status:** Draft
**Supersedes:** range-based configuration in `PRD-cloning-key-remapping.md` v0.2 (sections 3.2 `min`/`max`, 3.4 range validation, 4.1 `random_integer` strategy)

---

## 1. Problem

`cloning:run` with `random_integer` key remapping produces duplicate-entry errors during INSERT:

```
SQLSTATE[23000]: Integrity constraint violation: 1062
Duplicate entry '2889387-65535-255' for key 'werkstatt_netz2institution.composite_key'
```

Root cause: the remapper draws random integers from a user-configured range `[range_min, range_max]` (default `[100000, 9999999]`) without inspecting the target column's type. When the column is narrower than the range (e.g. `SMALLINT UNSIGNED` max `65535`, `TINYINT UNSIGNED` max `255`), MySQL non-strict mode silently clamps overflowed values to the column's type ceiling. Multiple distinct source IDs all collapse to the same ceiling value, producing duplicate composite keys downstream.

Secondary problems:

- The range is a footgun: users must know the schema's narrowest PK column to pick a valid range, and have no feedback when they get it wrong.
- Random selection requires collision dedup within a run (current `$usedIntegers` set) and degrades sharply when the range is small (loop on collisions).
- Random IDs are non-deterministic and harder to debug than ordered IDs.

## 2. Goal

Remove the `min` / `max` / `range_min` / `range_max` configuration knobs entirely. Replace the `random_integer` strategy with an automatic, type-aware, deterministic integer-id strategy that:

1. Inspects the source column's data type and unsigned flag to compute a hard upper bound (`typeMax`).
2. Allocates new IDs sequentially starting at `MAX(id) + 1` (where `MAX(id)` is read from the source PK column).
3. Falls back to filling unused gaps in `[1, MAX(id)]` if the upper region runs out.
4. Aborts with a clear, actionable error if the column type cannot host the required row count.

The `new_uuid` strategy is unchanged.

## 3. Non-Goals

- No backward compatibility for legacy YAML files containing `min`, `max`, `range_min`, `range_max`. Pre-1.0.0 — clean break. Validator hard-errors on legacy keys to force user migration.
- No optional offset / shift knob. If real users ask for one later, add it then.
- No support for negative-range PKs (signed types are still supported, but new IDs are always allocated in the positive half: `[max(MAX(id)+1, 1), typeMax]`).
- No change to `cloning:dump` FK discovery, junction-table handling, or the rest of the run pipeline.

## 4. Design

### 4.1 Type ceiling table

Implemented as a pure helper, `KeyTypeBoundsResolver::ceilingFor(ColumnSchemaData $col): int`.

Mapping (case-insensitive `$col->type`):

| Type token | Signed ceiling | Unsigned ceiling |
| --- | --- | --- |
| `tinyint` | 127 | 255 |
| `smallint` | 32767 | 65535 |
| `mediumint` | 8388607 | 16777215 |
| `int`, `integer` | 2147483647 | 4294967295 |
| `bigint` | `PHP_INT_MAX` (9223372036854775807) | `PHP_INT_MAX` (we do not return values > `PHP_INT_MAX`) |

Other types (`varchar`, `char`, `uuid`, `text`, …) → throw `UnsupportedKeyColumnTypeException`. The remapping service must only call the resolver after confirming the strategy is `RandomInteger` (was named `random_integer` in YAML — see §5 for rename rationale).

Unsigned detection: only meaningful for MySQL/MariaDB. For Postgres, SQLite, SQL Server: treat as signed (those drivers don't expose `UNSIGNED`). The resolver consumes a single `bool $unsigned` field on `ColumnSchemaData`.

### 4.2 ID allocation strategy

Pseudocode for one remapped table:

```
input:  ColumnSchemaData $pk     // type + unsigned + name
        list<int|string> $sourceIds   // raw PK values from source
output: array<string,string> $mapping // oldId(stringified) → newId(stringified)

typeMax    = KeyTypeBoundsResolver::ceilingFor($pk)
existing   = sort(unique(intval-cast of $sourceIds, dropping non-numeric))
maxExisting = empty(existing) ? 0 : last(existing)
rowCount   = count($sourceIds)

upperStart = max(maxExisting + 1, 1)
upperSlots = max(0, typeMax - upperStart + 1)

if upperSlots >= rowCount:
    targets = range(upperStart, upperStart + rowCount - 1)
else:
    needed   = rowCount - upperSlots
    upperPart = upperSlots > 0 ? range(upperStart, typeMax) : []
    gaps     = findGaps(existing, lo=1, hi=maxExisting)   // ascending, ints
    if count(gaps) < needed:
        throw KeyRemappingExhaustedException(table, column, typeMax, rowCount, upperSlots, count(gaps))
    targets = concat(upperPart, take(gaps, needed))

assign source-row order to targets in order:
for i, sourceId in enumerate(sourceIds):
    mapping[(string) sourceId] = (string) targets[i]
return mapping
```

Notes:

- `findGaps` returns ascending `int`s in `[lo, hi]` not present in `existing`. Implementation can be a single linear sweep over the sorted `existing` list — no per-iteration set membership test.
- Output values are stringified to preserve compatibility with the existing `KeyRemappingStoreInterface` contract (`array<string,string>`).
- Self-referential FK handling and the rest of `applyToRow` are unchanged.

### 4.3 Exhaustion error

```php
throw new KeyRemappingExhaustedException(
    "Cannot remap table '{$table}': column '{$col}' is {$type} ({$signedness}, ceiling {$typeMax}); "
  . "{$rowCount} rows requested, only {$availableSlots} slots available "
  . "({$upperSlots} above MAX(id), {$gapSlots} gaps below)."
);
```

Surfaced by `cloning:run` as `ExitCode::GeneralError`, with the message printed verbatim in the failure summary.

### 4.4 Phase 5b verbose output

```
✓ Key mapping generated for werkstatt_netz2institution (1,247,532 rows; INT UNSIGNED, MAX(id)=2,889,387)
```

Format is decorative; must include row count + column type + signedness for debugging.

## 5. YAML schema changes

### 5.1 Inline `strategy: remapping`

Remove `min` and `max` from the `arguments` list. Only `use` and `foreign_keys` remain.

```yaml
columns:
  id:
    strategy: remapping
    arguments:
      - use: random_integer
      - foreign_keys:
          - table: orders
            column: user_id
```

> The token `random_integer` is retained for now to keep the YAML contract stable, even though the new strategy is deterministic. Renaming to `auto_integer` is a follow-up nicety, not required by this spec.

### 5.2 Legacy top-level `key_remapping:` section

Remove `range_min` and `range_max` from each table entry.

```yaml
key_remapping:
  tables:
    - table: users
      primary_key: id
      strategy: random_integer
      foreign_keys: [...]
```

### 5.3 Validator behaviour

- `min`, `max`, `range_min`, `range_max` are now **unknown keys**. The validator must produce a hard error naming each occurrence:

  ```
  key_remapping.tables[0]: 'range_min' is no longer supported (auto-bounds is computed from column type)
  Table 'users', column 'id': remapping argument 'min' is no longer supported (auto-bounds is computed from column type)
  ```

- The order check (`min < max`) is removed.
- The `>= 1` check on `min`/`max` is removed.

### 5.4 `cloning:dump` output

`DumpCommand` no longer emits `min`/`max` / `range_min`/`range_max`. The generated YAML contains only `use: random_integer` plus `foreign_keys`.

## 6. Code changes (overview)

| File | Change |
| --- | --- |
| `app/Data/Schema/ColumnSchemaData.php` | Add `bool $unsigned` (default `false`) |
| `app/Services/Schema/SchemaInspector.php` (MySQL branch only) | Read `COLUMN_TYPE`, set `unsigned = str_contains(strtolower($columnType), 'unsigned')` |
| `app/Data/Cloning/KeyRemappingTableData.php` | Drop `rangeMin`, `rangeMax` |
| `app/Data/Cloning/ColumnCloningConfigData.php` | Drop `remappingMin`, `remappingMax` |
| `app/Services/Cloning/CloningYamlLoader.php` | Drop range parsing in inline + legacy branches |
| `app/Services/Cloning/CloningYamlValidator.php` | Hard-error on `min`/`max`/`range_min`/`range_max`; drop range checks |
| `app/Services/Cloning/CloningYamlWriter.php` | Drop `- min:` / `- max:` emission |
| `app/Commands/Cloning/DumpCommand.php` | Drop `rangeMin`/`rangeMax` constructor args |
| `app/Services/Cloning/KeyTypeBoundsResolver.php` (new) | Pure type-ceiling helper + `ceilingFor()`; throws `UnsupportedKeyColumnTypeException` |
| `app/Exceptions/KeyRemappingExhaustedException.php` (new) | RuntimeException subclass with table/column/typeMax/rowCount fields |
| `app/Exceptions/UnsupportedKeyColumnTypeException.php` (new) | RuntimeException subclass for unsupported column types |
| `app/Services/Cloning/KeyRemappingService.php` | Accept `DatabaseSchemaData`; rewrite `generateTableMapping`; remove `generateUniqueInteger` |
| `app/Commands/Cloning/RunCommand.php` (line 329) | Pass `$sourceSchema` to `generateMappings()` |
| Tests | Update DTO, loader, validator, writer, dump, key remapping service tests |

## 7. Test plan

New tests (Pest):

- `KeyTypeBoundsResolverTest` — TINYINT/SMALLINT/MEDIUMINT/INT/BIGINT × signed/unsigned matrix + unsupported-type error.
- `KeyRemappingServiceTest` (extended):
  - empty table → empty mapping
  - 5 rows in TINYINT UNSIGNED with `MAX(id)=200` → IDs `[201..205]`
  - 5 rows in TINYINT UNSIGNED with `MAX(id)=253` → IDs `[254, 255, gap, gap, gap]` (gap-fill kicks in)
  - 300 rows in TINYINT UNSIGNED → `KeyRemappingExhaustedException`
  - INT signed: never returns negative IDs
  - empty source table (`MAX(id)=null`) → IDs start at 1
  - `new_uuid` strategy still produces UUIDs (regression)
- `CloningYamlValidatorTest` — adds cases that legacy `min`/`max`/`range_min`/`range_max` are rejected.
- `CloningYamlLoaderTest` — drop existing range-parsing tests; add round-trip test for cleaned schema.
- `CloningYamlWriterTest` — assert no `- min:` / `- max:` lines emitted.

## 8. Migration / breakpoints for users

Pre-1.0.0 — no migration tooling. Users with legacy YAML containing range fields will see a validation error pointing to the offending key. Resolution: delete the line and re-run.

## 9. Open questions

- Should the bounds resolver expose a "max safe int" cap below `PHP_INT_MAX` for BIGINT? PHP arithmetic is safe up to `PHP_INT_MAX`; MySQL unsigned BIGINT can exceed it. For now, cap at `PHP_INT_MAX` and document.
- Should we warn when the resolver yields a tight margin (e.g. `< 10%` headroom) in verbose mode? Not in scope for this spec.
