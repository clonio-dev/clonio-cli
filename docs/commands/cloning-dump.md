# cloning:dump

Inspect a live database and generate a `.cloning.yaml` configuration file that describes how to anonymize each column when cloning the database to a target environment.

## Synopsis

```
clonio cloning:dump [options]
```

## Options

| Option | Description |
|--------|-------------|
| `--connection=<name>` | Name of the saved connection to inspect |
| `--output=<path>` | Output file path (default: `<connection-name>.cloning.yaml`) |
| `--force` | Overwrite an existing file without asking |
| `--only-pii` | Omit tables and columns with no PII match |
| `--all-columns` | Include all columns in the YAML, not just PII-detected ones |
| `--locale=<locale>` | FakerPHP locale for `options.faker_locale` (default: `en_US`) |
| `--enforce-column-types` | Set `enforce_column_types: true` in the generated YAML |
| `--drop-unknown-tables` | Set `drop_unknown_tables: true` in the generated YAML |
| `--drop-extra-columns` | Set `drop_extra_columns: true` in the generated YAML |
| `--no-disable-fk-checks` | Set `disable_foreign_key_checks: false` in the generated YAML |
| `--ci` | CI mode — suppress non-error output and require `--connection` |

## Description

`cloning:dump` connects to a database, reads its schema (tables and columns), and runs the PII auto-detection engine against every column name. It then writes a `cloning.yaml` file that:

- Lists every table in the database
- For each column that matched a PII pattern, assigns the recommended anonymization strategy (e.g. `fake`, `hash`, `mask`)
- For all non-PII columns, defaults to `keep` (no transformation)

The generated file is meant to be reviewed, adjusted, and committed to your repository. Once you're satisfied with the configuration, use `cloning:run` to apply it.

## Prerequisites

1. Run `clonio init` in your working directory to create a `.env` file with `APP_KEY`
2. Add a connection with `clonio connection:add`
3. (Optional) Customise PII detection with `clonio matchers:init`

## Usage

### Basic usage

```bash
clonio cloning:dump --connection production-db
```

This will:
1. Connect to the `production-db` database
2. Inspect its schema
3. Run PII detection on all column names
4. Write `production-db.cloning.yaml` in the current directory

### Interactive connection selection

If you omit `--connection`, Clonio will prompt you to choose from your saved connections:

```bash
clonio cloning:dump
```

```
 Select a connection to inspect:
  [0] production-db
  [1] staging-db
 > 0

  Inspecting "production-db" (pgsql @ db.prod.io:5432) ...

  Schema fetched: 24 tables, 187 columns

  Transfer options:
    Enforce column types on target? (no)
    Drop unknown tables on target? (no)
    Drop extra columns on target? (no)
    Disable foreign key checks? (yes)

  PII auto-detection:
    ✓  12 columns matched across 5 tables
    ○  175 columns set to keep

  Written: ./production-db.cloning.yaml

  Review the file, adjust strategies as needed, then run:
    clonio cloning:run production-db.cloning.yaml --target <name>
```

### Custom output path

```bash
clonio cloning:dump --connection production-db --output config/cloning.yaml
```

### Overwrite an existing file

```bash
clonio cloning:dump --connection production-db --force
```

### Only include PII columns

Use `--only-pii` to generate a minimal config that only lists tables and columns where PII was detected. Tables with no PII columns are omitted entirely.

```bash
clonio cloning:dump --connection production-db --only-pii
```

### Setting transfer options at dump time

After inspecting the schema, Clonio prompts for four schema-transfer options that control how `cloning:run` synchronizes the target schema. In interactive mode you answer yes/no at the prompt; defaults match the recommended safe settings:

| Prompt | Flag | Default | Effect on target |
|--------|------|---------|-----------------|
| Enforce column types on target? | `--enforce-column-types` | `false` | Add columns to existing tables that are present in the source but missing from the target |
| Drop unknown tables on target? | `--drop-unknown-tables` | `false` | Drop tables from the target that do not exist in the source |
| Drop extra columns on target? | `--drop-extra-columns` | `false` | Drop columns from existing target tables that are absent from the source |
| Disable foreign key checks? | `--no-disable-fk-checks` to disable | `true` | Disable FK constraint checks during data transfer |

In `--ci` mode the prompts are skipped and only the flags apply:

```bash
clonio cloning:dump --connection production-db --ci \
  --enforce-column-types \
  --drop-unknown-tables
```

The chosen values are written directly into the `options:` block of the generated YAML and serve as the baseline for every `cloning:run` against that file. Individual run-time overrides are available via `cloning:run` flags (see [cloning:run — Schema Synchronization](cloning-run.md#schema-synchronization)).

### CI mode

In CI pipelines, use `--ci` to suppress interactive prompts and non-error output. `--connection` is required in this mode.

```bash
clonio cloning:dump --connection production-db --ci
```

### Custom Faker locale

The generated config uses `en_US` as the default Faker locale for generating fake data. Override with `--locale`:

```bash
clonio cloning:dump --connection production-db --locale de_DE
```

## Output file format

The generated `cloning.yaml` follows this structure:

```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: production-db

options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  drop_extra_columns: false
  disable_foreign_key_checks: true
  faker_locale: en_US

tables:
  users:
    rows:
      strategy: full
    columns:
      # PII: Email Address
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      # PII: Password / Secret
      password:
        strategy: hash
        algorithm: sha256
        salt: ""

  orders:
    rows:
      strategy: full
    # no PII detected — no columns listed; all kept as-is
```

### Column strategies

| Strategy | Description |
|----------|-------------|
| `keep` | No transformation — value is copied as-is (default for non-PII columns) |
| `fake` | Replace with Faker-generated data (requires `faker_method` and `faker_arguments`) |
| `hash` | One-way hash (requires `algorithm` and `salt`) |
| `mask` | Mask characters (requires `visible_chars`, `mask_char`, `preserve_format`) |
| `null` | Replace with `NULL` |
| `static` | Replace with a fixed value (requires `value`) |

### Row strategies

| Strategy | Description |
|----------|-------------|
| `full` | Copy all rows |
| `first` | Copy the first N rows (requires `limit`) |
| `last` | Copy the last N rows (requires `limit`) |
| `skip`  | Skip this table entirely |

## PII detection

Clonio ships with a built-in set of PII matchers covering common column names for:

- Personal identity (name, first_name, last_name, date_of_birth, etc.)
- Contact information (email, phone, address, city, etc.)
- Location (latitude, longitude, postcode, etc.)
- Financial (iban, credit_card_number, bank_account, etc.)
- Authentication (password, secret_key, api_key, etc.)
- Network (ip_address, mac_address, etc.)

To customise which columns are detected and what transformations are applied, use:

```bash
clonio matchers:init   # export the baseline matchers to clonio.pii-matchers.yaml
clonio matchers:list   # view all active matchers
clonio matchers:check <column>  # test a column name against matchers
```

## Workflow

```
1.  cloning:dump --connection production-db
    → generates production-db.cloning.yaml

2.  Review the file:
    - Adjust strategies (e.g. change hash to fake for passwords)
    - Add missing PII columns
    - Tune row strategies (full vs first/last with limit)
    - Set salt values for hash strategies

3.  Commit to your repository

4.  cloning:run production-db.cloning.yaml --target local-dev
    → applies the config, cloning production → local-dev
```

## Exit codes

| Code | Name | Meaning |
|------|------|---------|
| 0 | Success | File written successfully |
| 2 | ConfigError | `clonio.json` not found, or no connections defined |
| 3 | ConnectionError | Connection not found, or database connection failed |
| 4 | ValidationError | `--connection` required in `--ci` mode but not provided |

## Related commands

- `cloning:run` — apply a cloning config to transfer and anonymize a database
- `matchers:init` — export baseline PII matchers to a customisable file
- `matchers:list` — list all active matchers and their patterns
- `matchers:check <column>` — test a column name against the active matchers
- `connection:add` — add a database connection
