# PRD — Cloning YAML Schema

**Version:** 0.1
**Status:** Draft
**Date:** 2026-04-01

---

## 1. Goal

Define the canonical structure of a `.cloning.yaml` file. This document is the single source of truth for:

- What a valid cloning configuration looks like
- The JSON Schema that validators use at runtime (`cloning:run --validate`) and in IDEs
- The set of supported anonymization strategies and their options
- The set of supported FakerPHP methods available in the `fake` strategy

---

## 2. Design Principles

- **Credential-free** — a YAML file contains only a connection *name* (the reference), never credentials. It is safe to commit to version control.
- **One file, one source** — each YAML file describes one source database connection. The target is always supplied at runtime (`--target`).
- **Human-editable** — the format is intentionally flat and readable; complex nesting is avoided.
- **Strict but forward-compatible** — `additionalProperties: false` at known paths; a `version` field allows future schema evolution.
- **Explicit over implicit** — every value that affects behaviour must be stated in the file. No hidden defaults are applied at runtime. A reader must be able to understand the full transfer configuration by reading the YAML alone, without knowing what the tool's built-in defaults are. The single exception to this rule is column listing: columns that are not listed under a table are implicitly treated as `keep` (see §4.3).

---

## 3. File Naming Convention

```
<anything>.cloning.yaml
```

The `.cloning.yaml` suffix is a soft convention that tooling can use to identify cloning files. The file name has no functional effect.

---

## 4. YAML Structure

### 4.1 Top-level

```yaml
version: "1"
connection: <string>    # name of the source connection in clonio.json
options:                # required; all fields must be set explicitly
  ...
tables:                 # required; at least one table
  <table_name>:
    ...
```

### 4.2 `options`

`options` is **required** and every field within it must be set explicitly. There are no runtime fallbacks — the file must be self-contained.

```yaml
options:
  chunk_size: 1000                   # rows per insert batch; integer ≥ 1
  enforce_column_types: false        # modify target column types if they differ
  drop_unknown_tables: false         # remove tables on target not present in source
  disable_foreign_key_checks: true   # disable FK constraints on target during transfer
  faker_locale: en_US                # FakerPHP locale for all fake strategies (BCP 47, e.g. de_DE)
```

`cloning:dump` always writes all five fields. When editing by hand, all five must remain present.

### 4.3 `tables.<table_name>`

```yaml
tables:
  users:
    rows:
      strategy: full     # required; full | first | last — no default
      limit: 1000        # required when strategy is first or last
      sort_by: id        # optional; column to order by for first/last
    columns:             # optional section; see column rule below
      <column_name>:
        strategy: keep   # keep | fake | hash | mask | null | static
        # strategy-specific options follow (see §5)
```

**Column listing rule — the only implicit default:**
Columns that are **not listed** under `columns` are implicitly treated as `keep`. This is the single exception to the explicit-over-implicit principle: requiring every column of every table to be written out would make the file unreadable for schemas with hundreds of columns. All other values — `options`, `rows.strategy`, and every strategy option on a listed column — must be explicit.

---

## 5. Column Strategy Reference

### 5.1 `keep`

Copy the value as-is. No additional options.

```yaml
id:
  strategy: keep
```

### 5.2 `fake`

Generate a realistic synthetic value using FakerPHP.

```yaml
email:
  strategy: fake
  faker_method: safeEmail
  faker_arguments: []
```

| Field | Type | Required | Description |
|-------|------|:--------:|-------------|
| `faker_method` | string | yes | FakerPHP method name (see §6) |
| `faker_arguments` | array of scalars | yes | Positional arguments passed to the method. Use `[]` when the method takes no arguments. |

### 5.3 `hash`

Replace the value with a deterministic hash. The same input always produces the same output.

```yaml
password:
  strategy: hash
  algorithm: sha256
  salt: ""
```

| Field | Type | Required | Description |
|-------|------|:--------:|-------------|
| `algorithm` | string | yes | PHP `hash()` algorithm: `sha256`, `sha512`, `md5`, `sha1` |
| `salt` | string | yes | Prefix prepended before hashing. Use `""` when no salt is desired. |

### 5.4 `mask`

Partially obscure the value. Non-alphanumeric characters (e.g. `@`, `.`, `-`) are preserved when `preserve_format` is true.

```yaml
phone:
  strategy: mask
  visible_chars: 4
  mask_char: "*"
  preserve_format: false
```

| Field | Type | Required | Description |
|-------|------|:--------:|-------------|
| `visible_chars` | integer | yes | Number of leading characters to leave unmasked (0 = mask all) |
| `mask_char` | string (1 char) | yes | Character used to replace masked positions (e.g. `"*"`) |
| `preserve_format` | boolean | yes | Whether to keep structural characters (`.`, `@`, `-`, spaces) unmasked |

### 5.5 `null`

Set the column value to `NULL`. Only valid on nullable columns.

```yaml
notes:
  strategy: "null"
```

No additional options.

### 5.6 `static`

Replace every value with a fixed string. Useful for environment markers or redacted placeholders.

```yaml
environment_tag:
  strategy: static
  value: "[REDACTED]"
```

| Field | Type | Required | Description |
|-------|------|:--------:|-------------|
| `value` | string | yes | The fixed value to use (may be empty string) |

---

## 6. Supported FakerPHP Methods

The `fake` strategy accepts any `faker_method` from this list. The list can be extended in future YAML schema versions. The validator in `cloning:run` checks that the provided method is in this list.

### 6.1 Personal Identity

| Method | Example Output |
|--------|---------------|
| `name` | Jane Smith |
| `firstName` | Alice |
| `lastName` | Johnson |
| `prefix` | Dr. |
| `suffix` | Jr. |
| `gender` | female |
| `title` | Software Engineer |

### 6.2 Contact

| Method | Example Output |
|--------|---------------|
| `safeEmail` | alice.johnson@example.com |
| `email` | alice@domain.tld |
| `freeEmail` | alice.johnson@gmail.com |
| `companyEmail` | alice@acme.com |
| `userName` | alice.j42 |
| `phoneNumber` | +1-555-0142 |
| `e164PhoneNumber` | +15550142000 |
| `tollFreePhoneNumber` | 800-555-0142 |

### 6.3 Location

| Method | Example Output |
|--------|---------------|
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

### 6.4 Company and Finance

| Method | Example Output |
|--------|---------------|
| `company` | Acme Corp |
| `companySuffix` | LLC |
| `jobTitle` | Senior Developer |
| `iban` | DE89370400440532013000 |
| `swiftBicNumber` | COBADEFFXXX |
| `creditCardNumber` | 4111111111111111 |
| `creditCardType` | Visa |
| `creditCardExpirationDate` | 06/28 |
| `currencyCode` | EUR |

### 6.5 Internet and Technology

| Method | Example Output |
|--------|---------------|
| `url` | https://example.com/page |
| `domainName` | example.com |
| `slug` | some-url-slug |
| `ipv4` | 192.168.1.100 |
| `ipv6` | 2001:0db8:85a3::8a2e:0370:7334 |
| `macAddress` | 00:1A:2B:3C:4D:5E |
| `userAgent` | Mozilla/5.0 (Windows NT 10.0...) |
| `mimeType` | image/jpeg |
| `fileExtension` | pdf |
| `md5` | 1bc29b36f623ba82aaf6724fd3b16718 |
| `sha1` | da39a3ee5e6b4b0d3255bfef95601890afd80709 |
| `sha256` | e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855 |
| `uuid` | 550e8400-e29b-41d4-a716-446655440000 |

### 6.6 Text and Content

| Method | Example Output |
|--------|---------------|
| `word` | lorem |
| `words` | lorem ipsum dolor (array — use `sentence` instead for strings) |
| `sentence` | Lorem ipsum dolor sit amet. |
| `sentences` | Multiple sentences. |
| `paragraph` | Lorem ipsum dolor... |
| `text` | Lorem ipsum... (up to N chars) |

`faker_arguments` for `text`: `[200]` (max character count).
`faker_arguments` for `sentence`: `[6]` (word count).

### 6.7 Numbers and Patterns

| Method | Example Output | Arguments |
|--------|---------------|-----------|
| `randomNumber` | 42 | `[max_digits, strict]` |
| `numberBetween` | 18 | `[min, max]` |
| `randomFloat` | 3.14 | `[decimals, min, max]` |
| `numerify` | 123-456 | `["###-###"]` (# = digit) |
| `lexify` | abc | `["???"]` (? = letter) |
| `bothify` | ab4 | `["??#"]` |
| `regexify` | AC-0042 | `["[A-Z]{2}-[0-9]{4}"]` |
| `boolean` | true | `[chance_of_getting_true_percentage]` |
| `randomElement` | red | `[["red","green","blue"]]` |

### 6.8 Date and Time

| Method | Example Output | Arguments |
|--------|---------------|-----------|
| `date` | 1985-03-15 | `["Y-m-d", "now"]` (format, max) |
| `time` | 14:32:00 | `["H:i:s"]` |
| `dateTime` | 1985-03-15 14:32:00 | `["now"]` (max) |
| `dateTimeBetween` | 2020-01-01 00:00:00 | `["-5 years", "now"]` |
| `unixTime` | 1708000000 | — |
| `year` | 1985 | — |
| `month` | 03 | — |
| `dayOfMonth` | 15 | — |
| `timezone` | Europe/Berlin | — |

### 6.9 Identifiers

| Method | Example Output |
|--------|---------------|
| `ean13` | 9781234567897 |
| `ean8` | 12345678 |
| `isbn10` | 0-306-40615-2 |
| `isbn13` | 978-0-306-40615-7 |

---

## 7. JSON Schema

The following JSON Schema (draft 2020-12) is the normative schema for `.cloning.yaml` files. `cloning:run` validates every input file against this schema before executing.

The schema file is published at `https://clonio.dev/schema/cloning-v1.json` and also shipped with the CLI binary at `resources/schemas/cloning.v1.json`.

A YAML language server hint can be placed at the top of every generated file:
```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
```

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://clonio.dev/schema/cloning-v1.json",
  "title": "Clonio Cloning Configuration",
  "description": "Configuration file for a cloning:run execution. Safe to commit to version control.",
  "type": "object",
  "required": ["version", "connection", "options", "tables"],
  "additionalProperties": false,
  "properties": {
    "version": {
      "type": "string",
      "enum": ["1"],
      "description": "Schema version. Currently only '1' is supported."
    },
    "connection": {
      "type": "string",
      "minLength": 1,
      "pattern": "^[a-z0-9_-]+$",
      "description": "Name of the source database connection defined in clonio.json."
    },
    "options": {
      "type": "object",
      "required": ["chunk_size", "enforce_column_types", "drop_unknown_tables", "disable_foreign_key_checks", "faker_locale"],
      "additionalProperties": false,
      "properties": {
        "chunk_size": {
          "type": "integer",
          "minimum": 1,
          "description": "Number of rows fetched and inserted per batch."
        },
        "enforce_column_types": {
          "type": "boolean",
          "description": "Modify target column types when they differ from the source."
        },
        "drop_unknown_tables": {
          "type": "boolean",
          "description": "Drop tables on the target that are not present in the source."
        },
        "disable_foreign_key_checks": {
          "type": "boolean",
          "description": "Disable FK constraint checks on the target during data transfer."
        },
        "faker_locale": {
          "type": "string",
          "pattern": "^[a-z]{2}(_[A-Z]{2})?$",
          "description": "BCP 47-style FakerPHP locale (e.g. de_DE, fr_FR, en_US)."
        }
      }
    },
    "tables": {
      "type": "object",
      "minProperties": 1,
      "description": "Map of table names to their cloning configuration.",
      "additionalProperties": {
        "$ref": "#/$defs/TableConfig"
      }
    }
  },
  "$defs": {
    "TableConfig": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "rows": {
          "$ref": "#/$defs/RowConfig"
        },
        "columns": {
          "type": "object",
          "description": "Map of column names to their transformation configuration. Unlisted columns default to strategy 'keep'.",
          "additionalProperties": {
            "$ref": "#/$defs/ColumnConfig"
          }
        }
      }
    },
    "RowConfig": {
      "type": "object",
      "additionalProperties": false,
      "required": ["strategy"],
      "properties": {
        "strategy": {
          "type": "string",
          "enum": ["full", "first", "last"],
          "description": "Row selection strategy. Must be set explicitly; no default is applied."
        },
        "limit": {
          "type": "integer",
          "minimum": 1,
          "description": "Number of rows to transfer. Required when strategy is 'first' or 'last'."
        },
        "sort_by": {
          "type": "string",
          "minLength": 1,
          "description": "Column to order by when using first/last strategy. Defaults to primary key."
        }
      },
      "if": {
        "properties": { "strategy": { "enum": ["first", "last"] } }
      },
      "then": {
        "required": ["limit"]
      }
    },
    "ColumnConfig": {
      "type": "object",
      "required": ["strategy"],
      "additionalProperties": false,
      "properties": {
        "strategy": {
          "type": "string",
          "enum": ["keep", "fake", "hash", "mask", "null", "static"]
        },
        "faker_method": {
          "type": "string",
          "minLength": 1,
          "description": "FakerPHP method name. Required when strategy is 'fake'."
        },
        "faker_arguments": {
          "type": "array",
          "items": {
            "type": ["string", "number", "boolean", "null", "array"]
          },
          "description": "Positional arguments for the faker method. Use [] when the method takes no arguments."
        },
        "algorithm": {
          "type": "string",
          "enum": ["sha256", "sha512", "md5", "sha1"],
          "description": "Hash algorithm. Required when strategy is 'hash'."
        },
        "salt": {
          "type": "string",
          "description": "Salt prefix prepended before hashing. Required when strategy is 'hash'. Use empty string for no salt."
        },
        "visible_chars": {
          "type": "integer",
          "minimum": 0,
          "description": "Number of leading characters left unmasked. Required when strategy is 'mask'."
        },
        "mask_char": {
          "type": "string",
          "minLength": 1,
          "maxLength": 1,
          "description": "Replacement character for masked positions. Required when strategy is 'mask'."
        },
        "preserve_format": {
          "type": "boolean",
          "description": "Keep structural characters (@ . - space) unmasked. Required when strategy is 'mask'."
        },
        "value": {
          "type": "string",
          "description": "Fixed replacement value. Required when strategy is 'static'."
        }
      },
      "allOf": [
        {
          "if": { "properties": { "strategy": { "const": "fake" } }, "required": ["strategy"] },
          "then": { "required": ["faker_method", "faker_arguments"] }
        },
        {
          "if": { "properties": { "strategy": { "const": "hash" } }, "required": ["strategy"] },
          "then": { "required": ["algorithm", "salt"] }
        },
        {
          "if": { "properties": { "strategy": { "const": "mask" } }, "required": ["strategy"] },
          "then": { "required": ["visible_chars", "mask_char", "preserve_format"] }
        },
        {
          "if": { "properties": { "strategy": { "const": "static" } }, "required": ["strategy"] },
          "then": { "required": ["value"] }
        }
      ]
    }
  }
}
```

---

## 8. Complete YAML Example

```yaml
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: production-db

# options is required; all five fields must be present
options:
  chunk_size: 500
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: de_DE

tables:
  users:
    rows:
      strategy: last      # required; no default
      limit: 5000
      sort_by: created_at
    # Only transformed columns are listed; everything else is implicitly kept
    columns:
      # Only columns that need transformation are listed.
      # All other columns (id, status, created_at, …) are implicitly kept as-is.
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
        strategy: fake
        faker_method: phoneNumber
        faker_arguments: []
      date_of_birth:
        strategy: fake
        faker_method: date
        faker_arguments: ["Y-m-d"]
      password:
        strategy: hash
        algorithm: sha256
        salt: "clonio"
      credit_card:
        strategy: mask
        visible_chars: 4
        mask_char: "*"
        preserve_format: false
      internal_notes:
        strategy: "null"
      account_tag:
        strategy: static
        value: "dev-imported"

  orders:
    rows:
      strategy: full
    columns:
      shipping_address:
        strategy: fake
        faker_method: address
        faker_arguments: []
      billing_postcode:
        strategy: fake
        faker_method: postcode
        faker_arguments: []
      ip_address:
        strategy: fake
        faker_method: ipv4
        faker_arguments: []

  audit_logs:
    rows:
      strategy: first
      limit: 100

  product_catalog:
    rows:
      strategy: full
```

---

## 9. Versioning and Schema Evolution

The `version` field gates which keys are permitted. Future schema versions may add new strategies or options fields without breaking existing files.

| Version | Status | Notes |
|:-------:|--------|-------|
| `"1"` | Current | Initial release |

When a breaking change is needed, `version: "2"` will be introduced and both schemas will be supported simultaneously for a migration window.

---

## 10. Schema File Location in Binary

The JSON Schema is bundled at `resources/schemas/cloning.v1.json` inside the application. `CloningYamlValidator` loads it via `Storage::disk('local')` when validating files. The resource path resolves correctly in both PHAR and SPC binary execution modes because schema files are read-only and can live inside the archive.

---

## 11. Out of Scope

- Key remapping configuration — reserved keys `key_remapping` at table level; spec deferred
- Conditional transformations (e.g. only mask if value matches a pattern)
- Per-column faker locale override
- Multi-source YAML (one file, multiple source databases)
