# PRD — `clonio.json` — Configuration File & JSON Schema

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Define the structure, validation rules, and merge behaviour of `clonio.json` — the project-level configuration file. This document is the single source of truth for the file format and is referenced by all command PRDs that read from or write to `clonio.json`.

---

## 2. File Location

`clonio.json` lives in the **current working directory** (the user's project root). Clonio reads and writes it via the `local` filesystem disk, which is already rooted at `getcwd()`.

File permissions: `0600` (owner read/write only).

---

## 3. Top-Level Structure

```json
{
  "$schema": "https://clonio.dev/schema/v1/clonio.schema.json",
  "connections": { },
  "mappings": {
    "pii": { },
    "types": { }
  }
}
```

| Key           | Required | Description                                              |
|---------------|:--------:|----------------------------------------------------------|
| `$schema`     | No       | URI for editor auto-complete and validation              |
| `connections` | No       | Named database connections (see §4)                      |
| `mappings`    | No       | User overrides on top of built-in config (see §5)        |

Both `connections` and `mappings` are optional so that `clonio init` can create an empty but valid file before any connections or mappings are added.

---

## 4. `connections`

Full specification: **PRD-connection-add.md §7**.

```json
"connections": {
  "local-dev": {
    "type": "mysql",
    "host": "127.0.0.1",
    "port": 3306,
    "database": "myapp",
    "username": "root",
    "password": "encrypted:eyJpdiI6...",
    "is_production": false
  },
  "local-sqlite": {
    "type": "sqlite",
    "database": "/absolute/path/to/db.sqlite",
    "is_production": false
  }
}
```

---

## 5. `mappings`

Mappings configure how Clonio interprets and transforms data during a transfer. Clonio ships with a **built-in baseline** (compiled into the binary). The `mappings` section in `clonio.json` contains only **user-defined overrides and extensions** on top of that baseline.

### 5.1 Merge Strategy

| Section          | User entry behaviour                                         |
|------------------|--------------------------------------------------------------|
| `mappings.pii`   | Patterns are **merged** (user patterns added to built-in). `default_transformation` and `transformations` list **override** the built-in values for that class. New PII classes not in the built-in are **added**. |
| `mappings.types` | Per-driver-pair, each key **overrides** the built-in entry. New pairs not in the built-in are **added**. |

The built-in config is never written to `clonio.json` — only the delta lives there.

---

### 5.2 `mappings.pii` — PII Classes

Each key in `pii` is a **PII class identifier** (e.g. `email`, `first_name`). A PII class groups column names that contain the same category of personal data and defines how they are anonymised by default.

```json
"mappings": {
  "pii": {
    "email": {
      "patterns": ["kontakt_email", "reply_to"],
      "default_transformation": "mask",
      "transformations": ["fake_email", "mask", "hash", "nullify"]
    },
    "customer_id": {
      "patterns": ["cid", "kunden_nr", "kundennummer"],
      "default_transformation": "hash",
      "transformations": ["hash", "nullify", "static"]
    }
  }
}
```

#### PII Class Object

| Field                    | Required | Type            | Description                                                    |
|--------------------------|:--------:|-----------------|----------------------------------------------------------------|
| `patterns`               | Yes      | `string[]`      | Column name patterns that identify this class (see §5.2.1)     |
| `default_transformation` | No       | `string`        | Transformation applied unless overridden per-column; inherits built-in default if omitted |
| `transformations`        | No       | `string[]`      | Full list of allowed transformations for this class; inherits built-in list if omitted |

#### 5.2.1 Pattern Matching Rules

- Patterns are matched **case-insensitively** against the column name
- A pattern may be a **literal string** (`email`) or a **glob-style wildcard** (`*_email`, `email_*`)
- The first matching PII class wins (built-in classes are evaluated before user-defined classes, unless the user defines a class with the same key — in that case patterns are merged and matched together)

#### 5.2.2 Built-in PII Classes (baseline, shipped with binary)

| Class key       | Example patterns (non-exhaustive)                              | Default transformation  |
|-----------------|----------------------------------------------------------------|-------------------------|
| `email`         | `email`, `email_address`, `e_mail`, `mail`, `*_email`         | `fake_email`            |
| `first_name`    | `first_name`, `firstname`, `vorname`, `fname`, `given_name`   | `fake_first_name`       |
| `last_name`     | `last_name`, `lastname`, `nachname`, `lname`, `surname`       | `fake_last_name`        |
| `full_name`     | `name`, `full_name`, `fullname`, `display_name`               | `fake_full_name`        |
| `phone`         | `phone`, `phone_number`, `telefon`, `tel`, `mobile`, `handy`  | `fake_phone`            |
| `address`       | `address`, `street`, `adresse`, `strasse`, `address_line*`    | `fake_address`          |
| `city`          | `city`, `stadt`, `ort`, `town`                                | `fake_city`             |
| `zip_code`      | `zip`, `zip_code`, `postal_code`, `plz`, `postleitzahl`       | `fake_postcode`         |
| `country`       | `country`, `land`, `country_code`                             | `fake_country`          |
| `ip_address`    | `ip`, `ip_address`, `remote_addr`, `client_ip`                | `mask_ip`               |
| `password`      | `password`, `passwd`, `pwd`, `passwort`, `hashed_password`    | `static_hash`           |
| `username`      | `username`, `user_name`, `login`, `benutzername`              | `fake_username`         |
| `date_of_birth` | `dob`, `date_of_birth`, `birthdate`, `geburtsdatum`           | `fake_date_of_birth`    |
| `national_id`   | `ssn`, `national_id`, `id_number`, `svn`, `personalausweis`   | `hash`                  |
| `credit_card`   | `credit_card`, `card_number`, `cc_number`, `pan`              | `mask_credit_card`      |
| `iban`          | `iban`, `bank_account`, `kontonummer`                         | `mask_iban`             |

#### 5.2.3 Transformations

The following transformations are available globally. Each PII class defines a subset of applicable ones.

| Transformation key  | Description                                                              |
|---------------------|--------------------------------------------------------------------------|
| `fake_email`        | Replace with a randomly generated realistic email address                |
| `fake_first_name`   | Replace with a locale-aware fake first name                              |
| `fake_last_name`    | Replace with a locale-aware fake last name                               |
| `fake_full_name`    | Replace with a locale-aware fake full name                               |
| `fake_phone`        | Replace with a locale-aware fake phone number                            |
| `fake_address`      | Replace with a fake street address                                       |
| `fake_city`         | Replace with a fake city name                                            |
| `fake_postcode`     | Replace with a fake postal code                                          |
| `fake_country`      | Replace with a fake country name                                         |
| `fake_username`     | Replace with a fake username                                             |
| `fake_date_of_birth`| Replace with a random realistic birth date                               |
| `mask`              | Partially mask the value (e.g. `j***@example.com`, `+49 *** ****78`)    |
| `mask_ip`           | Anonymise last octet(s): `192.168.1.x`                                   |
| `mask_credit_card`  | Keep last 4 digits: `**** **** **** 1234`                                |
| `mask_iban`         | Keep country code + last 4: `DE** **** **** **** **** 12`                |
| `hash`              | Replace with a deterministic SHA-256 hex hash of the original value      |
| `static_hash`       | Replace with a fixed bcrypt hash (useful for password fields)            |
| `nullify`           | Set to `NULL`                                                            |
| `static:<value>`    | Replace with a fixed literal value, e.g. `static:REDACTED`              |
| `truncate`          | Set to empty string `""`                                                 |

> Locale for `fake_*` transformations is read from `APP_LOCALE` (env var or `.env` file). Laravel Zero inherits this automatically. No additional config key in `clonio.json` is needed.

---

### 5.3 `mappings.types` — Database Type Mappings

Defines how column types are translated when transferring between database engines. Keys follow the pattern `<source>_to_<target>`.

```json
"mappings": {
  "types": {
    "mysql_to_pgsql": {
      "TINYINT(1)": "BOOLEAN",
      "LONGTEXT":   "TEXT"
    }
  }
}
```

#### Type Mapping Object

Each key is the source column type string (case-insensitive, may include parameters like `VARCHAR(255)`). The value is the target type string.

#### 5.3.1 Supported Driver Pairs

| Key               | Source    | Target    |
|-------------------|-----------|-----------|
| `mysql_to_pgsql`  | MySQL     | PostgreSQL |
| `mysql_to_sqlsrv` | MySQL     | SQL Server |
| `mysql_to_sqlite` | MySQL     | SQLite    |
| `mariadb_to_pgsql`| MariaDB   | PostgreSQL |
| `mariadb_to_sqlsrv`| MariaDB  | SQL Server |
| `pgsql_to_mysql`  | PostgreSQL | MySQL    |
| `pgsql_to_sqlsrv` | PostgreSQL | SQL Server |
| `sqlsrv_to_mysql` | SQL Server | MySQL    |
| `sqlsrv_to_pgsql` | SQL Server | PostgreSQL |

> The full built-in type mapping table is maintained in the binary source and is documented in a dedicated Type Mappings reference (future document).

---

## 6. Complete Example

```json
{
  "$schema": "https://clonio.dev/schema/v1/clonio.schema.json",
  "connections": {
    "local-dev": {
      "type": "mysql",
      "host": "127.0.0.1",
      "port": 3306,
      "database": "myapp",
      "username": "root",
      "password": "encrypted:eyJpdiI6...",
      "is_production": false
    },
    "prod": {
      "type": "pgsql",
      "host": "db.example.com",
      "port": 5432,
      "database": "myapp_prod",
      "schema": "public",
      "username": "clonio",
      "password": "encrypted:eyJpdiI6...",
      "is_production": true
    }
  },
  "mappings": {
    "pii": {
      "email": {
        "patterns": ["reply_to", "kontakt_email"]
      },
      "customer_id": {
        "patterns": ["cid", "kunden_nr"],
        "default_transformation": "hash",
        "transformations": ["hash", "nullify"]
      }
    },
    "types": {
      "mysql_to_pgsql": {
        "MEDIUMTEXT": "TEXT"
      }
    }
  }
}
```

---

## 7. JSON Schema

### 7.1 Location in Repository

```
resources/schema/clonio.schema.json
```

Published at: `https://clonio.dev/schema/v1/clonio.schema.json`

The schema is also embedded in the binary so that `clonio` can validate `clonio.json` offline without network access.

### 7.2 Key Constraints

- Root: `$schema`, `connections`, `mappings` — no additional properties
- `connections`: object; each key matches `^[a-z0-9_-]+$`; connection objects validated via `if/then` per driver (sqlite omits host/port/username/password)
- `password` values must match `^encrypted:.+`
- `mappings.pii`: each key matches `^[a-z0-9_]+$`; `patterns` must be a non-empty array of non-empty strings; `default_transformation` and `transformations` entries must match known transformation keys (enum)
- `mappings.types`: driver-pair keys match `^(mysql|mariadb|pgsql|sqlsrv|sqlite)_to_(mysql|mariadb|pgsql|sqlsrv|sqlite)$`; source ≠ target
- No additional properties allowed anywhere

---

## 8. Open Questions

- [ ] Should `clonio.json` be added to `.gitignore` automatically on first creation? (See also PRD-init §6.4)
- [ ] Should the `$schema` URL be versioned from the start (`/v1/`) to allow breaking changes later?
- [ ] Should `transformations` in user-defined PII classes be restricted to known built-in keys, or should custom transformation identifiers be allowed (for a future plugin system)?

> **Decided:** Locale is read from `APP_LOCALE` (env var / `.env`) — no config key in `clonio.json` needed.
> **Decided:** `static:<value>` uses the string-with-pattern encoding (e.g. `"static:REDACTED"`) — revisit if an object form is needed later.
