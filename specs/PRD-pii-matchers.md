# PRD — PII Matcher Configuration (`clonio.pii-matchers.yaml`)

**Version:** 0.2
**Status:** Draft
**Date:** 2026-04-01

---

## 1. Goal

Define how PII column-detection rules are stored, organised, merged, and applied across Clonio. Matchers live in a dedicated `clonio.pii-matchers.yaml` file — separate from `clonio.json` — so that teams can inspect, extend, and **commit them to version control** without exposing database credentials. The binary ships a baseline set of matchers organised into semantic groups; users extend or override that baseline by editing `clonio.pii-matchers.yaml`. `cloning:dump` consults the active matcher set when auto-populating a cloning YAML file.

> **Relationship to PRD-clonio-json.md §5.2** — the `mappings.pii` key described there is superseded by this file. `mappings.pii` will be removed from the `clonio.json` schema in a future version.

---

## 2. Why a Separate File?

`clonio.json` contains encrypted database credentials and **must not** be committed to version control. PII matcher rules contain no sensitive data — they describe how column names are classified, not which databases are involved. Keeping them in a separate, credential-free file means they can be committed alongside the `.cloning.yaml` files they inform.

| File | Contains credentials? | Commit to VCS? |
|------|--------------------|----------------|
| `clonio.json` | Yes (encrypted passwords) | **No** |
| `clonio.pii-matchers.yaml` | No | **Yes** |
| `*.cloning.yaml` | No | **Yes** |

---

## 3. Concepts

### 3.1 Matcher

A **matcher** is a named rule that:
1. Identifies a category of PII (e.g. "Email Address", "Date of Birth").
2. Defines one or more patterns matched against a column name to determine membership.
3. Specifies the full transformation `cloning:dump` should suggest for matching columns.

### 3.2 Group

Matchers are organised into **groups** — semantic PII categories (e.g. "Personal Identity", "Financial Data"). Groups exist solely for human readability; they have no effect on matching order or precedence. Every matcher belongs to exactly one group.

### 3.3 Baseline vs. user-defined

The binary ships a **baseline** set of matchers organised into baseline groups. The baseline is never written to disk — it exists only inside the binary.

When `clonio.pii-matchers.yaml` is present, it is the **sole source of truth**. The baseline is only consulted when no file exists. There is no silent merging at runtime; what is in the file is exactly what runs.

Users manage the file explicitly via `matchers init` (write the full baseline) and `matchers update` (add new matchers from a newer binary). See **PRD-cloning-matchers.md**.

---

## 4. File Location and Naming

```
clonio.pii-matchers.yaml
```

The file lives in the **current working directory** (the project root), written via `Storage::disk('local')`. It has no `encrypted:` values and is safe to commit.

File permissions: `0644` (world-readable; no credentials to protect).

---

## 5. YAML Structure

### 5.1 Top-level

```yaml
version: "1"

groups:
  <group_key>:
    name: "Human-Readable Group Name"
    matchers:
      <matcher_key>:
        ...
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `version` | yes | Schema version. Currently only `"1"`. |
| `groups` | yes | Map of group keys to group objects. At least one group required. |

### 5.2 Group object

| Field | Required | Description |
|-------|:--------:|-------------|
| `name` | yes | Human-readable display name shown in CLI output |
| `matchers` | yes | Map of matcher keys to matcher objects. At least one matcher required. |

### 5.3 Matcher object

```yaml
email_address:
  name: "Email Address"
  enabled: true
  patterns:
    - "/^(e[-_]?mail|email[-_]?addr(ess)?|user[-_]?email|contact[-_]?email)$/i"
    - reply_to
    - "*_email"
  transformation:
    strategy: fake
    faker_method: safeEmail
    faker_arguments: []
```

| Field | Required | Default | Description |
|-------|:--------:|---------|-------------|
| `name` | yes | — | Label shown in `cloning:dump` output and written as a YAML comment |
| `enabled` | no | `true` | `false` disables the matcher without removing it |
| `patterns` | yes* | — | Column name patterns (see §6). Required when `enabled` is `true`. |
| `transformation` | yes* | — | Full transformation config (see §7). Required when `enabled` is `true`. |

*When `enabled: false`, `patterns` and `transformation` may be omitted — the matcher is simply skipped.

---

## 6. Pattern Syntax

Each entry in `patterns` is evaluated in the order it appears. Three forms are supported:

| Form | Example | Behaviour |
|------|---------|-----------|
| **Regex** | `/^email$/i` | Matched via `preg_match()`. Must start and end with `/`. Case modifier in the expression. |
| **Glob** | `*_email`, `email_*` | `*` matches any character sequence. Always case-insensitive. |
| **Literal** | `reply_to` | Exact case-insensitive string match. |

Matching is applied to the **column name only**. The first matcher across all groups whose patterns produce a hit wins. Groups and matchers within a group are evaluated top-to-bottom as they appear in the file.

---

## 7. Transformation Object

The `transformation` object uses the identical shape as column strategy objects in `.cloning.yaml` (defined in **PRD-cloning-yaml-schema.md §5**). A transformation can be copy-pasted between `clonio.pii-matchers.yaml` and a cloning YAML file without any conversion.

All strategy fields that are required in the YAML schema are equally required here.

### 7.1 `strategy: fake`

```yaml
transformation:
  strategy: fake
  faker_method: safeEmail
  faker_arguments: []
```

### 7.2 `strategy: hash`

```yaml
transformation:
  strategy: hash
  algorithm: sha256
  # salt omitted → engine applies a per-run random salt at transform time
```

`salt` is optional. When absent, the cloning engine prepends a 32-byte random salt that is generated once per `cloning:run`. This:

- Defeats **cross-run linkability** (two snapshots of the same source database produce different hashes for the same input).
- Defeats rainbow-table attacks against small input spaces (SSN, employee numbers, etc.).
- Preserves **intra-run referential integrity** — identical source values still hash to identical target values within a single run, so foreign-key joins on hashed columns continue to work.

Set an explicit `salt:` string only when reproducible hashes across runs are required (e.g. integration-test fixtures).

> **GDPR.** `hash` produces *pseudonymized* data, not anonymized data (GDPR Art. 4 Nr. 5 / Recital 26). The output remains personal data and is still subject to the GDPR. For columns where any chance of linkage must be eliminated, use `fake`, `null`, or `static` instead.

### 7.3 `strategy: mask`

```yaml
transformation:
  strategy: mask
  visible_chars: 4
  mask_char: "*"
  preserve_format: false
```

### 7.4 `strategy: null`

```yaml
transformation:
  strategy: "null"
```

### 7.5 `strategy: static`

```yaml
transformation:
  strategy: static
  value: "[REDACTED]"
```

### 7.6 `strategy: template`

Build a string from literal text plus `{fakerMethod}` placeholders. Each placeholder is replaced with the no-argument output of the named Faker method. Useful when only part of a value needs to be randomized (e.g. fixed email domain).

```yaml
transformation:
  strategy: template
  template: "{userName}@acme.test"
```

| Field | Required | Description |
|-------|:--------:|-------------|
| `template` | yes | Non-empty template string. Unknown method names are rejected at config-load time. |

Only no-argument Faker methods may appear inside `{…}`. For arguments, use `strategy: fake` instead.

---

## 8. Baseline Groups and Matchers

The binary baseline is organised into six groups. When `matchers init` writes the file, this is the structure it produces.

### Group: `personal_identity` — Personal Identity

| Matcher key | Name | Patterns (non-exhaustive) | Strategy |
|-------------|------|---------------------------|----------|
| `first_name` | First Name | `/^(first[-_]?name\|given[-_]?name\|vorname\|prenom)$/i` | `fake` → `firstName` |
| `last_name` | Last Name | `/^(last[-_]?name\|sur[-_]?name\|family[-_]?name\|nachname\|nom)$/i` | `fake` → `lastName` |
| `full_name` | Person Name | `/^(full[-_]?name\|display[-_]?name\|name\|user[-_]?name\|nick[-_]?name)$/i` | `fake` → `name` |
| `date_of_birth` | Date of Birth | `/^(birth[-_]?date\|date[-_]?of[-_]?birth\|dob\|birthday\|geburtsdatum)$/i` | `fake` → `date` |
| `national_id` | National ID / SSN | `/^(ssn\|social[-_]?security\|national[-_]?id\|tax[-_]?id\|personal[-_]?id)$/i` | `fake` → `numerify('###-##-####')` |

### Group: `contact` — Contact Information

| Matcher key | Name | Patterns (non-exhaustive) | Strategy |
|-------------|------|---------------------------|----------|
| `email_address` | Email Address | `/^(e[-_]?mail\|email[-_]?addr(ess)?\|user[-_]?email\|contact[-_]?email)$/i` | `fake` → `safeEmail` |
| `phone_number` | Phone Number | `/^(phone\|phone[-_]?number\|tel(ephone)?\|mobile\|cell\|fax)$/i` | `fake` → `phoneNumber` |
| `username` | Username / Login | `/^(username\|user[-_]?name\|login\|benutzername\|handle)$/i` | `fake` → `userName` |

### Group: `location` — Location

| Matcher key | Name | Patterns (non-exhaustive) | Strategy |
|-------------|------|---------------------------|----------|
| `street_address` | Street Address | `/^(address\|street\|street[-_]?address\|addr(ess)?[-_]?line[-_]?\d?)$/i` | `fake` → `address` |
| `city` | City | `/^(city\|town\|ort\|stadt\|ville)$/i` | `fake` → `city` |
| `postal_code` | Postal Code | `/^(zip\|zip[-_]?code\|postal[-_]?code\|postcode\|plz)$/i` | `fake` → `postcode` |
| `country` | Country | `/^(country\|land\|country[-_]?code\|pays)$/i` | `fake` → `countryCode` |
| `latitude` | Latitude | `/^(lat\|latitude\|geo[-_]?lat)$/i` | `fake` → `latitude` |
| `longitude` | Longitude | `/^(lon\|lng\|longitude\|geo[-_]?lon\|geo[-_]?lng)$/i` | `fake` → `longitude` |

### Group: `financial` — Financial Data

| Matcher key | Name | Patterns (non-exhaustive) | Strategy |
|-------------|------|---------------------------|----------|
| `credit_card` | Credit Card Number | `/^(credit[-_]?card\|card[-_]?number\|cc[-_]?number\|payment[-_]?card\|pan)$/i` | `fake` → `creditCardNumber` |
| `iban` | IBAN / Bank Account | `/^(iban\|bank[-_]?account\|kontonummer\|bic\|swift)$/i` | `fake` → `iban` |
| `company_name` | Company Name | `/^(company\|company[-_]?name\|organization\|org[-_]?name\|firma)$/i` | `fake` → `company` |

### Group: `authentication` — Authentication & Secrets

| Matcher key | Name | Patterns (non-exhaustive) | Strategy |
|-------------|------|---------------------------|----------|
| `password` | Password / Secret | `/^(password\|passwd\|pwd\|secret\|passwort)$/i` | `static` → `"REDACTED"` |
| `api_token` | API Token / Key | `/^(token\|api[-_]?key\|access[-_]?token\|refresh[-_]?token\|auth[-_]?token)$/i` | `static` → `"REDACTED"` |

### Group: `network` — Network & Technical

| Matcher key | Name | Patterns (non-exhaustive) | Strategy |
|-------------|------|---------------------------|----------|
| `ip_address` | IP Address | `/^(ip\|ip[-_]?addr(ess)?\|client[-_]?ip\|remote[-_]?ip\|user[-_]?ip)$/i` | `fake` → `ipv4` |

---

## 9. Complete File Example

```yaml
# yaml-language-server: $schema=https://schema.clonio.dev/pii-matchers/v1.json
version: "1"

groups:
  personal_identity:
    name: "Personal Identity"
    matchers:
      first_name:
        name: "First Name"
        enabled: true
        patterns:
          - "/^(first[-_]?name|given[-_]?name|vorname|prenom)$/i"
        transformation:
          strategy: fake
          faker_method: firstName
          faker_arguments: []

      last_name:
        name: "Last Name"
        enabled: true
        patterns:
          - "/^(last[-_]?name|sur[-_]?name|family[-_]?name|nachname|nom)$/i"
        transformation:
          strategy: fake
          faker_method: lastName
          faker_arguments: []

      date_of_birth:
        name: "Date of Birth"
        enabled: true
        patterns:
          - "/^(birth[-_]?date|date[-_]?of[-_]?birth|dob|birthday|geburtsdatum)$/i"
        transformation:
          strategy: fake
          faker_method: date
          faker_arguments: ["Y-m-d"]

      national_id:
        name: "National ID / SSN"
        enabled: true
        patterns:
          - "/^(ssn|social[-_]?security|national[-_]?id|tax[-_]?id|personal[-_]?id)$/i"
        transformation:
          strategy: fake
          faker_method: numerify
          faker_arguments: ["###-##-####"]

  contact:
    name: "Contact Information"
    matchers:
      email_address:
        name: "Email Address"
        enabled: true
        patterns:
          - "/^(e[-_]?mail|email[-_]?addr(ess)?|user[-_]?email|contact[-_]?email)$/i"
          - reply_to
          - "*_email"
        transformation:
          strategy: fake
          faker_method: safeEmail
          faker_arguments: []

      phone_number:
        name: "Phone Number"
        enabled: true
        patterns:
          - "/^(phone|phone[-_]?number|tel(ephone)?|mobile|cell|fax)$/i"
        transformation:
          strategy: fake
          faker_method: phoneNumber
          faker_arguments: []

  financial:
    name: "Financial Data"
    matchers:
      credit_card:
        name: "Credit Card Number"
        enabled: true
        patterns:
          - "/^(credit[-_]?card|card[-_]?number|cc[-_]?number|payment[-_]?card|pan)$/i"
        transformation:
          strategy: fake
          faker_method: creditCardNumber
          faker_arguments: []

      iban:
        name: "IBAN / Bank Account"
        enabled: true
        patterns:
          - "/^(iban|bank[-_]?account|kontonummer)$/i"
        transformation:
          strategy: fake
          faker_method: iban
          faker_arguments: []

  authentication:
    name: "Authentication & Secrets"
    matchers:
      password:
        name: "Password / Secret"
        enabled: true
        patterns:
          - "/^(password|passwd|pwd|secret|passwort)$/i"
        transformation:
          strategy: static
          value: "REDACTED"

      api_token:
        name: "API Token / Key"
        enabled: false   # disabled — too broad; enable if your schema uses these column names

  custom:
    name: "Project-Specific"
    matchers:
      loyalty_id:
        name: "Loyalty Programme ID"
        enabled: true
        patterns:
          - loyalty_id
          - bonus_nr
          - "/^loyalty[-_]?(number|no|id)$/i"
        transformation:
          strategy: hash
          algorithm: sha256
          # salt omitted on purpose — engine prepends a per-run random salt
          # so joins stay valid within the run but the values are unrelatable
          # to any other run / snapshot.
```

---

## 10. DTOs

### 10.1 Single matcher DTO

```php
// app/Data/Pii/PiiMatcherData.php
final readonly class PiiMatcherData
{
    /**
     * @param list<string> $patterns
     */
    public function __construct(
        public string $key,
        public string $group,           // group key, e.g. "personal_identity"
        public string $name,
        public bool $enabled,
        public array $patterns,
        public ColumnCloningConfigData $transformation,
        public bool $isBaseline,        // true = came from binary defaults
    ) {}
}
```

### 10.2 Resolved matcher set DTO

```php
// app/Data/Pii/PiiMatcherSetData.php
final readonly class PiiMatcherSetData
{
    /**
     * @param list<PiiMatcherData> $matchers  flat list, already in evaluation order
     */
    public function __construct(
        public array $matchers,
    ) {}

    public function match(string $columnName): ?PiiMatcherData
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->enabled && $this->columnMatchesPatterns($columnName, $matcher->patterns)) {
                return $matcher;
            }
        }
        return null;
    }

    /** @param list<string> $patterns */
    private function columnMatchesPatterns(string $columnName, array $patterns): bool { /* ... */ }
}
```

### 10.3 Group DTO

```php
// app/Data/Pii/PiiMatcherGroupData.php
final readonly class PiiMatcherGroupData
{
    /**
     * @param list<PiiMatcherData> $matchers
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $matchers,
    ) {}
}
```

---

## 11. Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `PiiMatcherLoader` (new) | Reads `clonio.pii-matchers.yaml` if present; otherwise returns baseline. Returns `PiiMatcherSetData` (flat list in evaluation order). |
| `PiiMatcherBaselineProvider` (new) | Returns the hardcoded baseline as `list<PiiMatcherGroupData>`. Single source of truth for defaults in the binary. |
| `PiiMatcherYamlWriter` (new) | Serialises `list<PiiMatcherGroupData>` to `clonio.pii-matchers.yaml` via `Storage::disk('local')` |
| `PiiMatcherSetData` | Encapsulates the matcher list; provides `match(columnName)` |

`PiiMatcherLoader` logic:
1. If `clonio.pii-matchers.yaml` exists → parse it → return flat ordered list from groups
2. If absent → call `PiiMatcherBaselineProvider` → flatten groups → return

There is no runtime merging of file and baseline. Once the file exists, it owns the configuration entirely.

---

## 12. Evaluation Order

When matching a column name, matchers are evaluated in the order they appear in `clonio.pii-matchers.yaml`:
- Groups are processed top-to-bottom
- Matchers within a group are processed top-to-bottom
- First match wins; remaining matchers are not evaluated
- `enabled: false` matchers are skipped

---

## 13. JSON Schema for `clonio.pii-matchers.yaml`

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://schema.clonio.dev/pii-matchers/v1.json",
  "title": "Clonio PII Matcher Configuration",
  "type": "object",
  "required": ["version", "groups"],
  "additionalProperties": false,
  "properties": {
    "version": {
      "type": "string",
      "enum": ["1"]
    },
    "groups": {
      "type": "object",
      "minProperties": 1,
      "additionalProperties": {
        "$ref": "#/$defs/GroupConfig"
      }
    }
  },
  "$defs": {
    "GroupConfig": {
      "type": "object",
      "required": ["name", "matchers"],
      "additionalProperties": false,
      "properties": {
        "name": { "type": "string", "minLength": 1 },
        "matchers": {
          "type": "object",
          "minProperties": 1,
          "additionalProperties": { "$ref": "#/$defs/MatcherConfig" }
        }
      }
    },
    "MatcherConfig": {
      "type": "object",
      "required": ["name"],
      "additionalProperties": false,
      "properties": {
        "name":    { "type": "string", "minLength": 1 },
        "enabled": { "type": "boolean" },
        "patterns": {
          "type": "array",
          "minItems": 1,
          "items": { "type": "string", "minLength": 1 }
        },
        "transformation": { "$ref": "cloning.v1.json#/$defs/ColumnConfig" }
      },
      "if":   { "not": { "properties": { "enabled": { "const": false } } } },
      "then": { "required": ["name", "patterns", "transformation"] }
    }
  }
}
```

---

## 14. Out of Scope

- Per-table or per-connection PII matcher overrides — matchers apply project-wide
- Importing matchers from an external URL or remote file
- Versioned matcher packs (GDPR pack, HIPAA pack) — future feature
- Pattern matching against column comments, data types, or table names

---

## 15. Decisions

- **`matchers list` exists** and shows the full effective set (file if present, baseline otherwise), annotated per row with source and enabled state. See **PRD-cloning-matchers.md §5**.
- **`matchers check <column-name>` exists** as a diagnostic command showing which matcher fires, which pattern matched, and the resolved transformation. Also reports if a disabled matcher would have matched. See **PRD-cloning-matchers.md §6**.
- **Group identity is stable across updates — no special group.** When `matchers update` adds a new baseline matcher, it goes into its original baseline group. If that group does not yet exist in the user's file, the group is created and appended at the end of the file. There is no synthetic grouping (no `_new`, no `_baseline`).
