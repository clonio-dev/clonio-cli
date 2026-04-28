# `cloning:table:edit` Command

Interactively edit a table strategy in an existing `.cloning.yaml` configuration file. Mirrors the design of [`cloning:column:edit`](cloning-column-edit.md): step-through prompts when run without flags, fully scriptable when every option is supplied.

## Usage

```bash
clonio cloning:table:edit [<file>] [options]
```

If `file` is omitted, the command searches for `.cloning.yaml` files in the current directory. If exactly one is found, it is selected automatically. With multiple files, an interactive selection prompt is shown.

## Interactive Flow

1. **File** — Select or auto-detect the `.cloning.yaml` file.
2. **Table** — Choose from the tables defined in the YAML or select `+ Add new table` to create one.
3. **Row strategy** — Pick `Full`, `First N`, `Last N`, or `Skip`.
4. **Limit / Sort by** — Only when the strategy is `First N` or `Last N`.
5. **Clear mode** — Pick `None`, `Truncate`, or `Delete`.
6. **Summary** — A table showing all values is displayed before writing.
7. **Confirm** — Apply or cancel the change.

## Row strategies

| Strategy | Description |
|----------|-------------|
| `full` | Copy every row from the source. |
| `first` | Copy the first N rows after sorting (`limit` required, `sort_by` optional). |
| `last` | Copy the last N rows after sorting (`limit` required, `sort_by` optional). |
| `skip` | Skip this table — no data transferred. |

## Clear modes

| Mode | YAML representation | Description |
|------|---------------------|-------------|
| `none` | `clear: false` | Leave existing target rows in place. |
| `truncate` | `clear: truncate` | Fast wipe before insert. Requires `disable_foreign_key_checks` because TRUNCATE cannot be used on tables referenced by foreign keys. |
| `delete` | `clear: delete` | `DELETE FROM` before insert. Slower but FK-safe. |

## Options

| Option | Description |
|--------|-------------|
| `file` | Path to the `.cloning.yaml` file (argument, optional) |
| `--table=` | Table name. If the table is not yet present in the YAML it will be added. |
| `--rows-strategy=` | Row strategy: `full`, `first`, `last`, `skip` |
| `--rows-limit=` | (`first`/`last`) Number of rows. Must be a positive integer. |
| `--rows-sort-by=` | (`first`/`last`) Column name used for ordering. Optional. |
| `--rows-clear=` | Clear mode: `none`, `truncate`, `delete` |

When `--rows-strategy=first` or `--rows-strategy=last` is set without `--rows-limit`, the limit is prompted interactively. Same for `--rows-sort-by`.

## Behaviour

- **Adding new tables** — selecting `+ Add new table` (interactive) or passing `--table=<unknown_name>` (non-interactive) creates a new table entry. The newly created table only contains the `rows` section; no `columns` are added. Use [`cloning:column:edit`](cloning-column-edit.md) to add column strategies afterwards.
- **Existing column configuration is preserved** — the command only rewrites the `rows:` subtree of the chosen table; column strategies, comments, and any other custom keys are left untouched.
- **`clear: false` is written for `none`** — this matches the YAML convention used by `cloning:dump`. The `validator` accepts both `false` and the absence of `clear`.
- **Idempotent** — running the command twice with the same flags produces the same YAML output.

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | Table updated successfully, or change was cancelled |
| `4` | Invalid YAML, unknown row strategy, unknown clear mode, missing or non-positive `--rows-limit`, or empty table name |
| `5` | File not found or cannot be read |

## Examples

```bash
# Fully interactive
clonio cloning:table:edit

# Specify file and table interactively select strategy/clear
clonio cloning:table:edit production-db.cloning.yaml --table=users

# Non-interactive: full copy with truncate before insert
clonio cloning:table:edit production-db.cloning.yaml \
  --table=users --rows-strategy=full --rows-clear=truncate

# Non-interactive: copy the latest 100 orders by created_at
clonio cloning:table:edit production-db.cloning.yaml \
  --table=orders --rows-strategy=last --rows-limit=100 --rows-sort-by=created_at \
  --rows-clear=delete

# Non-interactive: skip a table entirely (no data transfer)
clonio cloning:table:edit production-db.cloning.yaml \
  --table=audit_events --rows-strategy=skip --rows-clear=none

# Non-interactive: add a new table to the YAML and skip it (auto-creates the entry)
clonio cloning:table:edit production-db.cloning.yaml \
  --table=ephemeral_log --rows-strategy=skip --rows-clear=none
```
