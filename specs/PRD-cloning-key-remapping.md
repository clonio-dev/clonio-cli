# PRD — Key Remapping in `cloning:run`

**Version:** 0.2
**Status:** Draft
**Date:** 2026-04-02

---

## 1. Goal

Extend the `.cloning.yaml` schema and the `cloning:run` pipeline to support key remapping: replacing primary key values during transfer and rewriting all foreign key references that point to remapped tables, so that transferred rows never collide with existing IDs in the target environment.

---

## 2. Background

In long-lived staging or development environments, records accumulate over time. When cloning production data on top of existing records, source IDs frequently collide with IDs already present on the target — even after truncation, because auto-increment counters or sequences may be out of sync.

Key remapping solves this by assigning non-conflicting IDs to every transferred primary key, then rewriting all foreign key columns that reference those keys consistently. The full relational graph is preserved.

This feature is documented in the companion web app at `content/docs/2-clonings/05-key-remapping.md`. The CLI must parse its configuration from the `.cloning.yaml` file and orchestrate the same three-phase transfer process locally.

See **PRD-cloning-yaml-schema.md** for the base YAML schema. See **PRD-cloning-run.md** for the full execution pipeline.

---

## 3. YAML Schema Extension

### 3.1 Preferred: inline `strategy: remapping` column

As of v0.4, key remapping is configured **inline inside the table's `columns` block** using `strategy: remapping`. This keeps all per-table configuration in one place and is the format that `cloning:dump` will produce.

```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: production

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
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - min: 100000
          - max: 9999999
          - foreign_keys:
              - table: orders
                column: user_id
              - table: employees
                column: manager_id
                self_referential: true
      # PII: Email Address
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      # PII: First Name
      first_name:
        strategy: fake
        faker_method: firstName
        faker_arguments: []

  orders:
    rows:
      strategy: full
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - min: 100000
          - max: 9999999
          - foreign_keys:
              - table: order_items
                column: order_id
      # user_id is a FK to users.id — rewritten automatically by remapping
      # PII: Shipping address
      shipping_address:
        strategy: fake
        faker_method: address
        faker_arguments: []

  order_items:
    rows:
      strategy: full
    # no PII detected — no columns listed; all kept as-is

  employees:
    rows:
      strategy: full
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - min: 100000
          - max: 9999999
          - foreign_keys:
              - table: employees
                column: manager_id
                self_referential: true
```

**How FK rewriting works in this example:**

| Source row | Column | Transformation |
|---|---|---|
| `orders` row | `user_id` | Replaced with the new mapped value for the `users.id` that matched the original `user_id` |
| `order_items` row | `order_id` | Replaced with the new mapped value for the `orders.id` that matched the original `order_id` |
| `employees` row | `manager_id` | Self-referential: inserted with `null`, then updated in a second pass after all employees are inserted |

The `foreign_keys` list on a remapped column declares **where that column's new values must be propagated**. Each entry says: "after remapping `users.id`, also rewrite `orders.user_id` to keep referential integrity."

### 3.2 `strategy: remapping` field reference

The remapping strategy is configured via an `arguments` list of single-key mappings:

| Argument key | Type | Required | Description |
| --- | --- | --- | --- |
| `use` | enum | yes | `random_integer` or `new_uuid` |
| `min` | integer | only for `random_integer` | Lower bound, inclusive. Default: 100000. |
| `max` | integer | only for `random_integer` | Upper bound, inclusive. Default: 9999999. |
| `foreign_keys` | list | yes | FK columns on other (or the same) tables that reference this column. May be empty (`[]`). |

Each entry in `foreign_keys`:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `table` | string | yes | Table that holds the FK column. |
| `column` | string | yes | Column name of the FK. |
| `self_referential` | bool | no | `true` when the FK table is the same as the remapped table (e.g. `employees.manager_id → employees.id`). Defaults to `false`. |

### 3.3 Legacy: top-level `key_remapping` section (still supported)

The original top-level `key_remapping:` section is still parsed and will continue to work for backward compatibility. Existing YAML files do not need to be migrated. When both formats are present in the same file, **inline column remapping takes priority**.

```yaml
# Legacy format — still works, but prefer inline strategy: remapping
key_remapping:
  tables:
    - table: users
      primary_key: id
      strategy: random_integer
      range_min: 100000
      range_max: 9999999
      foreign_keys:
        - table: posts
          column: user_id
        - table: comments
          column: user_id
    - table: posts
      primary_key: id
      strategy: new_uuid
      foreign_keys:
        - table: comments
          column: post_id
          self_referential: false
```

### 3.4 Validation rules

- `strategy: new_uuid` is only valid when `primary_key` is a UUID/CHAR(36) column. The validator checks the source schema if a connection is available; otherwise it is accepted and fails at runtime.
- `range_min` must be `≥ 1` and `< range_max`.
- No two entries in `key_remapping.tables` may reference the same `table`.
- A table listed in `key_remapping.tables` must also appear in `tables`.
- A FK entry referencing a table that is not in `tables` is a warning, not a hard error (the FK update will be skipped for unlisted tables).
- If the `key_remapping` section is present but `tables` is absent or empty, it is treated as if the section were absent — no remapping runs and no error is raised.

---

## 4. Execution Pipeline Extension

Key remapping adds two new phases and modifies Phase 6 (Data Transfer). All other phases are unchanged.

```
Phase 1  — YAML Validation                     (extended: validates key_remapping section)
Phase 2  — Connection Checks
Phase 3  — Dry-run (if --dry-run; exits here)
Phase 4  — Schema Replication
Phase 5  — Dependency Resolution
Phase 5b — Key Mapping Generation              (NEW: when key_remapping.tables is non-empty and --skip-remapping-keys is not set)
Phase 6  — Data Transfer                       (modified: PK and FK rewriting)
Phase 7  — Key Mapping Cleanup                 (NEW: when key_remapping.tables is non-empty and --skip-remapping-keys is not set)
Phase 8  — Audit Log
Phase 9  — Summary
```

### 4.1 Phase 5b — Key Mapping Generation

Runs after dependency resolution, before any rows are transferred.

For each table in `key_remapping.tables` (processed in dependency order):

1. Read all primary key values from the source table, respecting the table's `rows` strategy (full / first N / last N with the configured `sort_by` and `limit`).
2. For each source PK value, generate a new value:
   - `random_integer`: draw a random integer in `[range_min, range_max]`. Retry if already used in this run (in-memory collision set). If the range is exhausted, abort with `ExitCode::GeneralError`.
   - `new_uuid`: generate a UUID v7.
3. Store the mapping as `old_value → new_value` in an in-memory map keyed by `table.primary_key`.
4. Mappings are scoped to this run instance and are never written to the target database.

Output (verbose only): `✓ Key mapping generated for users (12,450 rows)`

### 4.2 Phase 6 — Data Transfer (modified)

For each row read from source:

1. If the row's table has a `key_remapping` entry, replace `row[primary_key]` with `mapping[old_pk_value]`.
2. For each `foreign_keys` entry whose FK table is the current table, replace `row[column]` with `mapping_for_parent_table[row[column]]`. If no mapping exists for a FK value (parent was not transferred), leave the column as-is — the FK constraint handler will skip the row if it causes a violation.
3. **Self-referential FKs**: rows whose self-referential FK column references a not-yet-inserted row require two passes:
   - First pass: insert with the self-referential FK set to `null` (FK constraints disabled during transfer already).
   - Second pass: after the full table is inserted, update self-referential FK columns using the mapping.

### 4.3 Phase 7 — Key Mapping Cleanup

After all tables have been transferred:

1. Release in-memory mapping tables.
2. Log a `key_mapping_cleanup_completed` event to the run log.
3. If cleanup fails (e.g. out-of-memory), log a warning but do not fail the run.

This phase always runs before audit log generation (Phase 8), so the audit log reflects the completed state.

### 4.4 Junction tables

A table where every column in `primary_key` is also listed as a FK entry in another table's `foreign_keys` is treated as a junction table. Its PK columns are not offered for remapping in the YAML and must not appear in `key_remapping.tables`. Their values are rewritten automatically when the parent tables are processed.

---

## 5. Command Signature

### 5.1 `cloning:run` flags

| Flag | Description |
| --- | --- |
| `--skip-remapping-keys` | Bypass phases 5b and 7 entirely (key mapping generation and cleanup), even when the YAML contains a `key_remapping` section with entries. No PK or FK rewriting is performed during data transfer. |

Example:

```
php clonio cloning:run --skip-remapping-keys
```

---

## 6. Dry-run Behaviour

When `--dry-run` is active, Phase 5b is executed but generates no in-memory mappings. Instead, for each remapped table the dry-run output shows:

```
  key_remapping  users.id → random_integer [100000–9999999]
```

This is appended to the per-table block in the existing dry-run table output.

---

## 7. Audit Log Extension

The audit record produced in Phase 8 is extended with a `key_remapping` block:

```json
{
  "key_remapping": {
    "enabled": true,
    "tables": [
      {
        "table": "users",
        "primary_key": "id",
        "strategy": "random_integer",
        "range_min": 100000,
        "range_max": 9999999,
        "rows_remapped": 12450,
        "rows_skipped_unique_violation": 3,
        "rows_skipped_fk_violation": 1
      }
    ]
  }
}
```

---

## 8. `cloning:dump` Behaviour

`cloning:dump` **always** emits a `key_remapping` section when generating a `.cloning.yaml` file. It introspects the source schema to build the section automatically:

- **FK discovery**: FK relationships are derived from the source schema's information schema (`information_schema.KEY_COLUMN_USAGE` / `REFERENTIAL_CONSTRAINTS` or equivalent). Each FK constraint becomes an entry in the `foreign_keys` list of the referenced table's remapping block.
- **Strategy auto-detection**: `random_integer` is selected for integer PKs; `new_uuid` is selected for UUID or CHAR(36) PKs.
- **Unsupported tables**: If a table has a composite PK that is not a pure-FK junction table, it is omitted from `key_remapping.tables`. The dump output prints a note for each omitted table, e.g.:

  ```
  note  orders — composite PK (order_id, line_id) not supported for remapping; skipped
  ```

The resulting `key_remapping` section is ready for use without manual edits in the common case. It can be refined or removed by hand before running `cloning:run`.

---

## 9. Error Handling

| Condition | Behaviour |
| --- | --- |
| Range exhausted (random_integer) | Abort run with `ExitCode::GeneralError`; print table name and range in error message. |
| Source PK column not found on source | Abort at Phase 5b with validation error. |
| FK column not found on FK table | Warning logged; FK column not rewritten. |
| Self-referential second-pass update fails | Warning logged; row is left with original FK value. |
| `key_remapping.tables` references a table not in `tables` | YAML validation error in Phase 1. |

---

## 10. Constraints and Limitations

- Only **single-column primary keys** are supported for remapping. Composite PKs that are not pure-FK junction tables are not supported and must not appear in `key_remapping.tables`.
- The **range must be large enough** for the number of rows being transferred. There is no automatic range expansion.
- All tables whose FK columns reference a remapped table must themselves be included in the `tables` section; otherwise FK rewriting is silently skipped for missing tables.
- Key mappings are **in-memory only** for the CLI. Unlike the web app, there is no `cloning_run_key_mappings` database table; the CLI holds the full mapping in RAM. For very large tables, memory usage should be monitored.

---

## 11. Compliance Relevance

Key remapping is a technical control for regulatory compliance:

- **GDPR / DSGVO Art. 4(5)** — Supports pseudonymisation. Combined with field-level anonymization, it prevents re-identification in test environments.
- **HIPAA / PCI DSS / SOC 2** — Satisfies the requirement to remove or replace direct identifiers before using data outside production.

Mapping values are never persisted to the target environment and are discarded after Phase 7, satisfying EDPB requirements that pseudonymisation keys must not be accessible within the pseudonymisation domain.
