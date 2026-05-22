# `.cloning.yaml` Format Reference

A `.cloning.yaml` file describes how to transfer and anonymize one source database. It is safe to commit to version control — it contains only a connection *name*, never credentials.

Generate a starting file with [`cloning:dump`](commands/cloning-dump.md), then review and adjust before running [`cloning:run`](commands/cloning-run.md).

---

## File structure

```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: <connection-name>

options:
  ...

tables:
  <table-name>:
    rows:
      ...
    columns:
      <column-name>:
        ...
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `version` | yes | Schema version. Must be `"1"`. |
| `connection` | yes | Name of the source connection from `clonio.json`. |
| `options` | yes | Global transfer settings (see [Options](#options)). |
| `tables` | yes | Map of table names to their transfer configuration. At least one table is required. |

The file name has no functional effect. The convention `<connection-name>.cloning.yaml` is used by `cloning:dump` by default.

---

## Options

All fields in `options` must be present. `cloning:dump` writes all of them automatically.

```yaml
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  drop_extra_columns: false
  disable_foreign_key_checks: true
  faker_locale: en_US
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `chunk_size` | integer ≥ 1 | `1000` | Rows fetched and inserted per batch. |
| `enforce_column_types` | boolean | `false` | Add columns to existing target tables that are present in the source but missing from the target. |
| `drop_unknown_tables` | boolean | `false` | Drop tables from the target that do not exist in the source. |
| `drop_extra_columns` | boolean | `false` | Drop columns from existing target tables that exist in the target but not in the source. ⚠ Irreversible — see note below. |
| `disable_foreign_key_checks` | boolean | `true` | Disable foreign-key constraint checks on the target during data transfer. Recommended when truncating or inserting out of dependency order. |
| `faker_locale` | string | `en_US` | [FakerPHP locale](https://fakerphp.github.io/localization/) applied to all `fake` column strategies. Examples: `de_DE`, `fr_FR`, `ja_JP`. |

### Schema synchronization

The three schema-control options together determine how closely the target schema is kept in sync with the source before data is transferred:

| Option | Effect during Phase 4 (Schema Replication) |
|--------|---------------------------------------------|
| *(always)* | Creates tables that exist in source but not in target |
| `enforce_column_types: true` | Adds missing columns to existing target tables |
| `drop_extra_columns: true` | Drops columns from target tables that are absent in source |
| `drop_unknown_tables: true` | Drops tables from target that are absent in source |

> **⚠ Caution — `drop_extra_columns: true`**
>
> Dropping columns is irreversible and destroys any data stored in those columns on the target. Enable this only on ephemeral environments (e.g. a fresh CI database) or when you have confirmed those columns are safe to remove.

---

## Tables

Each key under `tables` is the exact table name in the source database.

```yaml
tables:
  users:
    rows:
      strategy: full
      clear: delete
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []

  audit_logs:
    rows:
      strategy: first
      limit: 100
      sort_by: created_at
```

### `rows`

Controls which rows are transferred and whether the target table is cleared first.

| Field | Required | Values | Description |
|-------|:--------:|--------|-------------|
| `strategy` | yes | `full` \| `first` \| `last` | Row selection strategy. |
| `limit` | when `first`/`last` | integer ≥ 1 | Number of rows to transfer. |
| `sort_by` | no | column name | Column to order by for `first`/`last`. Defaults to the primary key. |
| `clear` | no | `false` \| `truncate` \| `delete` | Whether to empty the target table before inserting. Default: `false`. |

#### Row strategies

| Strategy | Behaviour |
|----------|-----------|
| `full` | Copy all rows from the source table. |
| `first` | Copy the first `limit` rows (ordered by `sort_by` ascending). |
| `last` | Copy the last `limit` rows (ordered by `sort_by` descending). |

#### `clear` values

| Value | SQL issued | Notes |
|-------|-----------|-------|
| `false` | *(none)* | Transferred rows are appended to existing target rows. |
| `truncate` | `TRUNCATE TABLE …` | Fastest. On SQLite, falls back to `DELETE FROM`. Runs after FK checks are disabled. |
| `delete` | `DELETE FROM …` | Safer on targets with FK constraints enforced at the statement level. |

### `columns`

Lists the columns that need transformation. **Columns not listed are implicitly kept as-is** — you don't need to enumerate every column in a table.

Each column entry has a `strategy` and any strategy-specific fields described in the [Column strategies](#column-strategies) section.

---

## Column strategies

### `keep`

Copy the value unchanged. You rarely need to write this explicitly — unlisted columns default to `keep`.

```yaml
id:
  strategy: keep
```

---

### `fake`

Replace with a realistic synthetic value generated by [FakerPHP](https://fakerphp.github.io/).

```yaml
email:
  strategy: fake
  faker_method: safeEmail
  faker_arguments: []
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `faker_method` | yes | FakerPHP method name. See [Faker method reference](#faker-method-reference) below. |
| `faker_arguments` | yes | Positional arguments passed to the method. Use `[]` when no arguments are needed. |

**Examples:**

```yaml
first_name:
  strategy: fake
  faker_method: firstName
  faker_arguments: []

date_of_birth:
  strategy: fake
  faker_method: date
  faker_arguments: ["Y-m-d"]

age:
  strategy: fake
  faker_method: numberBetween
  faker_arguments: [18, 99]
```

---

### `hash`

Replace the value with a one-way hash so the same input produces the same output within a single run — useful for preserving referential integrity across tables without exposing real values.

```yaml
employee_id:
  strategy: hash
  algorithm: sha256
  # salt is optional; omit it to let Clonio apply a per-run random salt.
```

| Field | Required | Values | Description |
|-------|:--------:|--------|-------------|
| `algorithm` | yes | `sha256` \| `sha512` \| `md5` \| `sha1` | PHP `hash()` algorithm. SHA-256 is recommended; SHA-1 / MD5 are accepted only for legacy use. |
| `salt` | no | string | Prefix prepended to the value before hashing. **When omitted, Clonio generates a 32-byte random salt per run.** Hashes are stable inside one run (joins work) but unrelatable across runs (rainbow tables and cross-snapshot linking are defeated). Set an explicit salt only if reproducible hashes across runs are required (e.g. test fixtures). |

> **GDPR notice.** `hash` is a *pseudonymization* technique, not anonymization (GDPR Art. 4 Nr. 5 / Recital 26). The output is still personal data and remains in scope of the GDPR. For columns where re-identification by linkage must be impossible, prefer `fake` or `null`.

---

### `mask`

Reveal only the first N characters; replace the rest with a mask character.

```yaml
phone:
  strategy: mask
  visible_chars: 4
  mask_char: "*"
  preserve_format: false
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `visible_chars` | yes | Number of leading characters to leave unmasked (`0` masks everything). |
| `mask_char` | yes | Single character used to replace masked positions (e.g. `"*"` or `"X"`). |
| `preserve_format` | yes | When `true`, structural characters (`.`, `@`, `-`, spaces) are preserved in their original positions regardless of `visible_chars`. |

**Examples:**

| Input | `visible_chars` | `preserve_format` | Output |
|-------|:-:|:-:|-------|
| `alice@example.com` | `3` | `false` | `ali***************` |
| `alice@example.com` | `3` | `true` | `ali**@*******.***` |
| `+44 20 7946 0958` | `0` | `true` | `+** ** **** ****` |

---

### `null`

Set the column value to `NULL`. Only valid on nullable columns.

```yaml
notes:
  strategy: "null"
```

No additional fields. Quote `"null"` to avoid YAML interpreting it as a null scalar.

---

### `static`

Replace every value with a fixed string. Useful for environment markers, redacted placeholders, or test sentinels.

```yaml
environment_tag:
  strategy: static
  value: "dev-imported"
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `value` | yes | The fixed string to use (may be an empty string). |

---

### `template`

Build a string by mixing literal text with `{fakerMethod}` placeholders. Each placeholder is expanded to the output of the named [Faker method](#supported-faker-methods) (no-argument form). Useful when only part of a value needs to be randomized — typically a fixed email domain, a fixed phone prefix, or a fixed organizational suffix.

```yaml
email:
  strategy: template
  template: "{userName}@acme.test"

display_name:
  strategy: template
  template: "{firstName} {lastName}"

support_ref:
  strategy: template
  template: "ACME-{uuid}"
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `template` | yes | Non-empty string. Tokens of the form `{methodName}` are replaced; everything else is passed through. The validator rejects unknown faker methods at config-load time. |

Notes:

- Only no-argument Faker methods are supported inside `{…}`. For arguments, use the regular `fake` strategy.
- The original column value is **not** read. Each output is freshly generated per row, so cross-run linkability is defeated by design.
- Validators reject `{unknown}` tokens; at runtime, unknown methods render as the empty string (defense in depth).

---

### `remapping`

Assign a new primary key value to each transferred row and rewrite all foreign key columns that reference it, preventing ID collisions on the target.

```yaml
id:
  strategy: remapping
  arguments:
    - use: random_integer
    - foreign_keys:
        - table: orders
          column: user_id
        - table: employees
          column: manager_id
          self_referential: true
```

`arguments` is a list of single-key mappings:

| Key | Required | Values | Description |
|-----|:--------:|--------|-------------|
| `use` | yes | `random_integer` \| `new_uuid` | Strategy for generating new PK values. |
| `min` | `random_integer` only | integer ≥ 1 | Lower bound, inclusive. Default: `100000`. |
| `max` | `random_integer` only | integer | Upper bound, inclusive. Default: `9999999`. |
| `foreign_keys` | yes | list | FK columns on other (or the same) table that reference this column. Use `[]` when there are none. |

Each `foreign_keys` entry:

| Field | Required | Description |
|-------|:--------:|-------------|
| `table` | yes | Table that holds the FK column. |
| `column` | yes | Name of the FK column. |
| `self_referential` | no | `true` when the FK points back to the same table (e.g. `employees.manager_id → employees.id`). Rows are inserted with `null` first, then updated in a second pass. Default: `false`. |

See [cloning-run.md — Key Remapping](commands/cloning-run.md#key-remapping) for a full worked example and the legacy `key_remapping:` top-level section format.

---

## Faker method reference

The Faker locale is set globally via `options.faker_locale`. All methods below respect the locale where translations are available.

### Personal identity

| Method | Example |
|--------|---------|
| `name` | Jane Smith |
| `firstName` | Alice |
| `lastName` | Johnson |
| `prefix` | Dr. |
| `suffix` | Jr. |
| `gender` | female |
| `title` | Software Engineer |

### Contact

| Method | Example |
|--------|---------|
| `safeEmail` | alice.johnson@example.com |
| `email` | alice@domain.tld |
| `freeEmail` | alice.johnson@gmail.com |
| `companyEmail` | alice@acme.com |
| `userName` | alice.j42 |
| `phoneNumber` | +1-555-0142 |
| `e164PhoneNumber` | +15550142000 |
| `tollFreePhoneNumber` | 800-555-0142 |

### Location

| Method | Example |
|--------|---------|
| `address` | 123 Main St, Springfield, IL 62701 |
| `streetAddress` | 123 Main St |
| `streetName` | Main Street |
| `buildingNumber` | 42 |
| `city` | Springfield |
| `state` | Illinois |
| `stateAbbr` | IL |
| `postcode` | 62701 |
| `country` | United States |
| `countryCode` | US |
| `latitude` | 37.7749 |
| `longitude` | -122.4194 |

### Company and finance

| Method | Example |
|--------|---------|
| `company` | Acme Corp |
| `companySuffix` | LLC |
| `jobTitle` | Senior Developer |
| `iban` | DE89370400440532013000 |
| `swiftBicNumber` | COBADEFFXXX |
| `creditCardNumber` | 4111111111111111 |
| `creditCardType` | Visa |
| `creditCardExpirationDate` | 06/28 |
| `currencyCode` | EUR |

### Internet and technology

| Method | Example |
|--------|---------|
| `url` | https://example.com/page |
| `domainName` | example.com |
| `slug` | some-url-slug |
| `ipv4` | 192.168.1.100 |
| `ipv6` | 2001:0db8:85a3::8a2e:0370:7334 |
| `macAddress` | 00:1A:2B:3C:4D:5E |
| `userAgent` | Mozilla/5.0 (Windows NT 10.0...) |
| `mimeType` | image/jpeg |
| `fileExtension` | pdf |
| `uuid` | 550e8400-e29b-41d4-a716-446655440000 |

### Text and content

| Method | Arguments | Example |
|--------|-----------|---------|
| `word` | — | lorem |
| `sentence` | `[word_count]` | Lorem ipsum dolor sit amet. |
| `paragraph` | — | Lorem ipsum dolor... |
| `text` | `[max_chars]` | Lorem ipsum... |

### Numbers and patterns

| Method | Arguments | Example |
|--------|-----------|---------|
| `randomNumber` | `[max_digits, strict]` | 42 |
| `numberBetween` | `[min, max]` | 18 |
| `randomFloat` | `[decimals, min, max]` | 3.14 |
| `numerify` | `["###-###"]` | 123-456 |
| `lexify` | `["???"]` | abc |
| `bothify` | `["??#"]` | ab4 |
| `regexify` | `["[A-Z]{2}-[0-9]{4}"]` | AC-0042 |
| `boolean` | `[chance_percent]` | true |
| `randomElement` | `[["a","b","c"]]` | b |

### Date and time

| Method | Arguments | Example |
|--------|-----------|---------|
| `date` | `["Y-m-d", "now"]` | 1985-03-15 |
| `time` | `["H:i:s"]` | 14:32:00 |
| `dateTime` | `["now"]` | 1985-03-15 14:32:00 |
| `dateTimeBetween` | `["-5 years", "now"]` | 2022-07-14 09:11:00 |
| `unixTime` | — | 1708000000 |
| `year` | — | 1985 |
| `month` | — | 03 |
| `dayOfMonth` | — | 15 |
| `timezone` | — | Europe/Berlin |

### Identifiers

| Method | Example |
|--------|---------|
| `ean13` | 9781234567897 |
| `ean8` | 12345678 |
| `isbn10` | 0-306-40615-2 |
| `isbn13` | 978-0-306-40615-7 |

---

## Complete example

```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: production-db

options:
  chunk_size: 500
  enforce_column_types: true    # add missing columns to target
  drop_unknown_tables: false
  drop_extra_columns: false
  disable_foreign_key_checks: true
  faker_locale: de_DE

tables:
  users:
    rows:
      strategy: last
      limit: 5000
      sort_by: created_at
      clear: delete
    columns:
      # id is remapped to avoid PK collisions on the target
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys:
              - table: orders
                column: user_id
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      first_name:
        strategy: fake
        faker_method: firstName
        faker_arguments: []
      last_name:
        strategy: fake
        faker_method: lastName
        faker_arguments: []
      phone:
        strategy: mask
        visible_chars: 0
        mask_char: "*"
        preserve_format: true
      date_of_birth:
        strategy: fake
        faker_method: date
        faker_arguments: ["Y-m-d"]
      password:
        strategy: static
        value: "REDACTED"
      employee_id:
        strategy: hash
        algorithm: sha256
        # salt omitted on purpose — engine uses its per-run random salt.
      internal_notes:
        strategy: "null"
      account_tag:
        strategy: static
        value: "dev-imported"

  orders:
    rows:
      strategy: full
      clear: delete
    columns:
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys:
              - table: order_items
                column: order_id
      # user_id is rewritten automatically because users.id declares it as a FK
      shipping_address:
        strategy: fake
        faker_method: address
        faker_arguments: []
      billing_postcode:
        strategy: fake
        faker_method: postcode
        faker_arguments: []

  order_items:
    rows:
      strategy: full
    # no PII detected — no columns listed; all kept as-is

  audit_logs:
    rows:
      strategy: first
      limit: 100
      sort_by: created_at

  product_catalog:
    rows:
      strategy: full
    # no PII — no columns listed
```

---

## Related

- [`cloning:dump`](commands/cloning-dump.md) — generate this file from a live database
- [`cloning:run`](commands/cloning-run.md) — execute the transfer
- [`cloning:run` — Key Remapping](commands/cloning-run.md#key-remapping) — full key remapping reference
- [`cloning:run` — Schema Synchronization](commands/cloning-run.md#schema-synchronization) — schema sync options in depth
