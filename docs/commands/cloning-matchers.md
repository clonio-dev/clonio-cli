# matchers

Manage PII column-detection rules stored in `clonio.pii-matchers.yaml`.

Clonio detects personally identifiable information (PII) columns automatically by matching column names against a set of rules called **matchers**. Matchers are grouped into semantic categories and can use regex, glob, or literal patterns. When a column matches, a configured transformation strategy (fake, hash, mask, etc.) is applied during `cloning:dump`.

The binary ships a **baseline** set of matchers. You can customise detection by initialising a `clonio.pii-matchers.yaml` file in your project root and editing it — it contains no credentials and is safe to commit.

---

## Sub-commands

- [`matchers init`](#matchers-init) — write the baseline to disk
- [`matchers update`](#matchers-update) — add new baseline matchers to an existing file
- [`matchers list`](#matchers-list) — show the effective matcher set
- [`matchers check`](#matchers-check) — test a column name against the active matcher set

---

## matchers init

Write the full baseline PII matcher configuration to `clonio.pii-matchers.yaml`.

```bash
php clonio matchers init
```

### Options

| Option | Description |
|--------|-------------|
| `--force` | Overwrite an existing `clonio.pii-matchers.yaml` without confirmation |
| `--path=<path>` | Output path (default: `clonio.pii-matchers.yaml` in cwd) |

### Behaviour

1. If `clonio.pii-matchers.yaml` already exists and `--force` is not set, prompts for confirmation.
2. Writes all baseline groups and matchers to the file.
3. Prints a summary of groups and matcher counts.

### Example output

```
  Writing PII matcher baseline to clonio.pii-matchers.yaml ...

  Groups written:
    personal_identity     5 matchers
    contact               3 matchers
    location              6 matchers
    financial             3 matchers
    authentication        2 matchers
    network               1 matchers

  Total: 20 matchers across 6 groups

  Edit clonio.pii-matchers.yaml to customise detection, then commit it to your repository.
  Run matchers update after upgrading Clonio to add new baseline matchers.

  Tip: clonio.pii-matchers.yaml contains no credentials and is safe to commit.
       Make sure clonio.json is in your .gitignore.
```

### Notes

- Run this once per project to get started.
- After editing `clonio.pii-matchers.yaml`, the file is the sole source of truth. The baseline is only used when the file is absent.
- After upgrading Clonio, run `matchers update` to pick up any new baseline matchers.

---

## matchers update

Sync an existing `clonio.pii-matchers.yaml` with the current binary baseline by adding any new matchers that are in the baseline but not in your file.

```bash
php clonio matchers update
```

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be added without writing anything |
| `--path=<path>` | Path to `clonio.pii-matchers.yaml` (default: `clonio.pii-matchers.yaml` in cwd) |

### Behaviour

1. Reads the existing `clonio.pii-matchers.yaml`.
2. Compares it against the baseline shipped in the current binary.
3. Reports any new baseline matchers (additions) and any matchers in your file that no longer exist in the baseline (orphans).
4. If `--dry-run` is not set, writes the additions to the file. Orphans and your customisations are left untouched.

### Example output — new matchers found

```
  Checking clonio.pii-matchers.yaml against binary baseline ...

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
  Checking clonio.pii-matchers.yaml against binary baseline ...

  clonio.pii-matchers.yaml is up to date. No changes needed.
```

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | Success |
| `5` | `clonio.pii-matchers.yaml` not found — run `matchers init` first |

---

## matchers list

Show the full effective matcher set — from `clonio.pii-matchers.yaml` if it exists, otherwise from the binary baseline.

```bash
php clonio matchers list
```

### Options

| Option | Description |
|--------|-------------|
| `--path=<path>` | Path to `clonio.pii-matchers.yaml` (default: `clonio.pii-matchers.yaml` in cwd) |

### Example output

The table includes a **Sensitivity** column indicating the risk level of each matcher:

```
  Effective PII matchers  (source: binary baseline)

  +---+-----------------------------+----------------+---------------------------------+-----------+----------------------+----------+
  |   | Group                       | Key            | Name                            | Sensitivity | Transformation     | Source   |
  +---+-----------------------------+----------------+---------------------------------+-----------+----------------------+----------+
  | ✓ | Government-Issued Identif…  | national_id    | National ID / SSN               | critical  | hash → sha256        | baseline |
  | ✓ | Government-Issued Identif…  | passport_number| Passport Number                 | critical  | hash → sha256        | baseline |
  | ✓ | Personal Identity           | first_name     | First Name                      | high      | fake → firstName     | baseline |
  | ✓ | Contact Information         | email_address  | Email Address                   | high      | fake → safeEmail     | baseline |
  | ✓ | Financial Data              | credit_card    | Credit Card Number              | critical  | mask                 | baseline |
  | ✓ | Medical & Health            | medical_record_id | Medical Record Number        | critical  | hash → sha256        | baseline |
  | — | Authentication & Secrets    | api_token      | API Token / Key                 | critical  |                      | baseline, disabled |
  +---+-----------------------------+----------------+---------------------------------+-----------+----------------------+----------+

  Total: 34 active matchers across 10 groups  (13 disabled)
  Source: binary baseline — run matchers:init to customise
```

### Source annotation

Each row is annotated with its source:
- `baseline` — from the binary baseline (when no file exists)
- `file` — defined in `clonio.pii-matchers.yaml`
- `baseline, disabled` / `file, disabled` — matcher is present but `enabled: false`

### Sensitivity levels

| Level | Meaning |
|-------|---------|
| `critical` | Direct disclosure causes substantial harm — identity theft, financial fraud, privacy violation (SSN, credit card, password, medical record, biometrics) |
| `high` | Direct personal identifiers — name, email, phone, DOB, street address, session token |
| `medium` | Indirect identifiers — IP address, postal code, device ID, gender, nationality |
| `low` | Contextual data with limited direct harm alone — city, country, job title, employer |

---

## matchers check

Test a column name against the active matcher set and show the result, including a live example of the transformation applied to a real input value.

```bash
php clonio matchers check <column> [value]
```

### Arguments

| Argument | Description |
|----------|-------------|
| `column` | Column name to test |
| `value` | *(optional)* A value to run through the transformation. Omit to use the built-in example for baseline matchers. |

### Options

| Option | Description |
|--------|-------------|
| `--path=<path>` | Path to `clonio.pii-matchers.yaml` (default: `clonio.pii-matchers.yaml` in cwd) |

### Example — match found (built-in example)

```bash
php clonio matchers check credit_card
```

```
  Column "credit_card" matched:

    Matcher:        credit_card
    Group:          financial
    PII category:   "Credit Card Number"
    Sensitivity:    critical
    Source:         binary baseline
    Matched by:     /^(credit[-_]?card|card[-_]?number|cc[-_]?number|payment[-_]?card|pan)$/i  (regex)

    Transformation:
      strategy:       mask
      visible_chars:  4
      mask_char:      "*"
      preserve_format: false

    Example:
      Input:   4242424242424242
      Output:  4242************
```

### Example — match found with custom value

```bash
php clonio matchers check credit_card 5555555555554444
```

```
    Example:
      Input:   5555555555554444
      Output:  5555************
```

### Example — fake strategy (input is not used)

```bash
php clonio matchers check email
```

```
    Example:
      Input:   john.doe@example.com
      Output:  emily.smith@example.net  (faker generates fresh data — input value is not used)
```

### Example — no match

```bash
php clonio matchers check created_at
```

```
  Column "created_at" — no matcher found

  This column will be treated as strategy: keep by cloning:dump.
```

### Example section behaviour

| Situation | What is shown |
|-----------|---------------|
| Baseline matcher, no `value` arg | Built-in example value transformed live |
| Any matcher, `value` arg provided | User-provided value transformed live |
| YAML matcher, no `value` arg | Hint to pass a value as the 2nd argument |

For `fake` strategy the output is always freshly generated by Faker regardless of the input value — the note is shown to make this clear.

### Value validation

The following are rejected with an error message (exit `0`):

- Empty or whitespace-only string
- Strings longer than 10,000 characters
- Strings containing binary or control characters

### Exit codes

Always exits `0`, regardless of whether a match is found or validation fails.

---

## The clonio.pii-matchers.yaml file

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
php clonio matchers init

# 2. Review and edit clonio.pii-matchers.yaml
#    Enable any disabled matchers relevant to your data
#    (e.g. medical, biometric, salary — disabled by default)
# 3. Commit clonio.pii-matchers.yaml to version control

# 4. Test that a column is classified correctly
php clonio matchers check user_email
php clonio matchers check ssn 123-45-6789   # test with a real value

# 5. After upgrading Clonio, sync new baseline matchers
php clonio matchers update
```

### Built-in matcher groups

The binary ships **10 matcher groups** covering all major PII categories:

| Group | Description | Sensitivity |
|-------|-------------|-------------|
| `government_ids` | SSN, passport, driver's license, tax ID | critical |
| `personal_identity` | Name, DOB, gender, nationality, religion¹ | high / medium |
| `contact` | Email, phone, username | high / medium |
| `location` | Address, city, postal code, lat/lon | high–low |
| `financial` | Credit card, IBAN, routing number, salary¹ | critical / high |
| `medical` | Medical record ID, insurance ID, diagnosis¹ | critical / high |
| `biometric` | Fingerprint, face encoding, DNA¹ | critical |
| `professional` | Company name, job title, employee ID | low / medium |
| `digital_identity` | IP address, device ID, session ID, MAC address | high / medium |
| `authentication` | Password, OAuth token, API key¹, private key¹ | critical |

¹ Disabled by default — enable in `clonio.pii-matchers.yaml` when relevant to your schema.
