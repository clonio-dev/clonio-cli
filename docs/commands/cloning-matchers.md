# cloning:matchers

Manage PII column-detection rules stored in `pii-matchers.yaml`.

Clonio detects personally identifiable information (PII) columns automatically by matching column names against a set of rules called **matchers**. Matchers are grouped into semantic categories and can use regex, glob, or literal patterns. When a column matches, a configured transformation strategy (fake, hash, mask, etc.) is applied during `cloning:dump`.

The binary ships a **baseline** set of matchers. You can customise detection by initialising a `pii-matchers.yaml` file in your project root and editing it — it contains no credentials and is safe to commit.

---

## Sub-commands

- [`cloning:matchers init`](#cloningmatchers-init) — write the baseline to disk
- [`cloning:matchers update`](#cloningmatchers-update) — add new baseline matchers to an existing file
- [`cloning:matchers list`](#cloningmatchers-list) — show the effective matcher set
- [`cloning:matchers check`](#cloningmatchers-check) — test a column name against the active matcher set

---

## cloning:matchers init

Write the full baseline PII matcher configuration to `pii-matchers.yaml`.

```bash
php clonio cloning:matchers init
```

### Options

| Option | Description |
|--------|-------------|
| `--force` | Overwrite an existing `pii-matchers.yaml` without confirmation |
| `--path=<path>` | Output path (default: `pii-matchers.yaml` in cwd) |

### Behaviour

1. If `pii-matchers.yaml` already exists and `--force` is not set, prompts for confirmation.
2. Writes all baseline groups and matchers to the file.
3. Prints a summary of groups and matcher counts.

### Example output

```
  Writing PII matcher baseline to pii-matchers.yaml ...

  Groups written:
    personal_identity     5 matchers
    contact               3 matchers
    location              6 matchers
    financial             3 matchers
    authentication        2 matchers
    network               1 matchers

  Total: 20 matchers across 6 groups

  Edit pii-matchers.yaml to customise detection, then commit it to your repository.
  Run cloning:matchers update after upgrading Clonio to add new baseline matchers.

  Tip: pii-matchers.yaml contains no credentials and is safe to commit.
       Make sure clonio.json is in your .gitignore.
```

### Notes

- Run this once per project to get started.
- After editing `pii-matchers.yaml`, the file is the sole source of truth. The baseline is only used when the file is absent.
- After upgrading Clonio, run `cloning:matchers update` to pick up any new baseline matchers.

---

## cloning:matchers update

Sync an existing `pii-matchers.yaml` with the current binary baseline by adding any new matchers that are in the baseline but not in your file.

```bash
php clonio cloning:matchers update
```

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be added without writing anything |
| `--path=<path>` | Path to `pii-matchers.yaml` (default: `pii-matchers.yaml` in cwd) |

### Behaviour

1. Reads the existing `pii-matchers.yaml`.
2. Compares it against the baseline shipped in the current binary.
3. Reports any new baseline matchers (additions) and any matchers in your file that no longer exist in the baseline (orphans).
4. If `--dry-run` is not set, writes the additions to the file. Orphans and your customisations are left untouched.

### Example output — new matchers found

```
  Checking pii-matchers.yaml against binary baseline ...

  New matchers added:
    financial             →  crypto_wallet_address  "Crypto Wallet Address"
    network               →  mac_address            "MAC Address"   (new group)

  Orphaned matchers (in file but no longer in baseline):
    personal_identity     →  maiden_name  "Maiden Name"
    ⚠  These are no longer recognised by the current Clonio baseline.
       They may be custom entries or from an older version.
       Remove them manually if they are no longer needed.

  2 matchers added. 1 orphaned matcher reported. Your existing customisations were not changed.
```

### Example output — up to date

```
  Checking pii-matchers.yaml against binary baseline ...

  pii-matchers.yaml is up to date. No changes needed.
```

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | Success |
| `5` | `pii-matchers.yaml` not found — run `cloning:matchers init` first |

---

## cloning:matchers list

Show the full effective matcher set — from `pii-matchers.yaml` if it exists, otherwise from the binary baseline.

```bash
php clonio cloning:matchers list
```

### Options

| Option | Description |
|--------|-------------|
| `--path=<path>` | Path to `pii-matchers.yaml` (default: `pii-matchers.yaml` in cwd) |

### Example output

```
  Effective PII matchers  (source: pii-matchers.yaml)

  Personal Identity
    ✓  first_name            "First Name"                    fake → firstName         [file]
    ✓  last_name             "Last Name"                     fake → lastName          [file]
    ✓  full_name             "Person Name"                   fake → name              [file]
    ✓  date_of_birth         "Date of Birth"                 fake → date              [file]
    ✓  national_id           "National ID / SSN"             hash → sha256            [file]

  Contact Information
    ✓  email_address         "Email Address"                 fake → safeEmail         [file]
    ✓  phone_number          "Phone Number"                  fake → phoneNumber       [file]
    ✓  username              "Username / Login"              fake → userName          [file]

  Authentication & Secrets
    —  api_token             "API Token / Key"               [file, disabled]

  Total: 19 active matchers across 6 groups  (1 disabled)
  Source: pii-matchers.yaml
```

### Source annotation

Each row is annotated with its source:
- `[file]` — defined in `pii-matchers.yaml`
- `[baseline]` — from the binary baseline (when no file exists)
- `[file, disabled]` — in the file but `enabled: false`

---

## cloning:matchers check

Test a column name against the active matcher set and show the result.

```bash
php clonio cloning:matchers check <column>
```

### Arguments

| Argument | Description |
|----------|-------------|
| `column` | Column name to test |

### Options

| Option | Description |
|--------|-------------|
| `--path=<path>` | Path to `pii-matchers.yaml` (default: `pii-matchers.yaml` in cwd) |

### Example — match found

```bash
php clonio cloning:matchers check email
```

```
  Column "email" matched:

    Matcher:        email_address
    Group:          contact
    PII category:   "Email Address"
    Source:         pii-matchers.yaml
    Matched by:     /^(e[-_]?mail|email[-_]?addr(ess)?|user[-_]?email|contact[-_]?email)$/i  (regex)

    Transformation:
      strategy:       fake
      faker_method:   safeEmail
      faker_arguments: []
```

### Example — no match

```bash
php clonio cloning:matchers check created_at
```

```
  Column "created_at" — no matcher found

  This column will be treated as strategy: keep by cloning:dump.
```

### Exit codes

Always exits `0`, regardless of whether a match is found.

---

## The pii-matchers.yaml file

### File format

```yaml
# yaml-language-server: $schema=https://schema.clonio.dev/pii-matchers/v1.json
version: "1"

groups:
  contact:
    name: "Contact Information"
    matchers:
      email_address:
        name: "Email Address"
        enabled: true
        patterns:
          - "/^(e[-_]?mail|email[-_]?addr(ess)?)$/i"
          - reply_to
          - "*_email"
        transformation:
          strategy: fake
          faker_method: safeEmail
          faker_arguments: []
```

### Pattern syntax

| Form | Example | Behaviour |
|------|---------|-----------|
| Regex | `/^email$/i` | Matched via `preg_match()`. Must start and end with `/`. |
| Glob | `*_email` | `*` matches any characters. Always case-insensitive. |
| Literal | `reply_to` | Exact case-insensitive string match. |

### Transformation strategies

| Strategy | Fields |
|----------|--------|
| `fake` | `faker_method`, `faker_arguments` |
| `hash` | `algorithm` (e.g. `sha256`), `salt` |
| `mask` | `visible_chars`, `mask_char`, `preserve_format` |
| `static` | `value` |
| `null` | (no extra fields) |
| `keep` | (no extra fields — column is not anonymised) |

### Recommended workflow

```bash
# 1. Initialise the matcher file in your project
php clonio cloning:matchers init

# 2. Review and edit pii-matchers.yaml
# 3. Commit pii-matchers.yaml to version control

# 4. Test that a column is classified correctly
php clonio cloning:matchers check user_email

# 5. After upgrading Clonio, sync new baseline matchers
php clonio cloning:matchers update
```
