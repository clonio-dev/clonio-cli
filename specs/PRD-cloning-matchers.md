# PRD — `matchers` Commands

**Version:** 0.1
**Status:** Draft
**Date:** 2026-04-01

---

## 1. Goal

Provide four commands for managing and inspecting the `pii-matchers.yaml` file:

- **`matchers init`** — write the full binary baseline to `pii-matchers.yaml` so the team can inspect, customise, and commit it.
- **`matchers update`** — after upgrading the Clonio binary, add any new baseline matchers to an existing `pii-matchers.yaml` without touching entries the user has already customised.
- **`matchers list`** — display the effective matcher set (file if present, baseline otherwise) annotated with source.
- **`matchers check <column-name>`** — test which matcher fires for a given column name.

See **PRD-pii-matchers.md** for the full `pii-matchers.yaml` schema and matcher semantics.

---

## 2. Background

When `pii-matchers.yaml` is absent, Clonio uses hidden binary defaults. Teams who want control over PII detection — either to add project-specific matchers, to disable false positives, or to review exactly what rules are active — must materialise those defaults into an editable file. `matchers init` is the entry point for that.

When the Clonio binary is upgraded, the baseline may contain new matchers for newly recognised PII categories. `matchers update` merges those additions into the existing file without overwriting the team's customisations.

---

## 3. `matchers init`

### 3.1 Command Signature

```
matchers init
    {--force  : Overwrite an existing pii-matchers.yaml without confirmation}
    {--path=  : Output path (default: pii-matchers.yaml in cwd)}
```

### 3.2 Behaviour

1. Resolve the output path (default: `pii-matchers.yaml` in `cwd`).
2. If the file already exists and `--force` is not set:
   - Prompt: `pii-matchers.yaml already exists. Overwrite? [y/N]`
   - Declined → exit `Success (0)`.
3. Load the full baseline from `PiiMatcherBaselineProvider`.
4. Serialise all groups and matchers to YAML via `PiiMatcherYamlWriter`.
5. Write to the resolved path via `Storage::disk('local')`.
6. Print a summary.

### 3.3 Output (normal mode)

```
  Writing PII matcher baseline to pii-matchers.yaml ...

  Groups written:
    personal_identity   5 matchers
    contact             3 matchers
    location            6 matchers
    financial           3 matchers
    authentication      2 matchers
    network             1 matcher

  Total: 20 matchers across 6 groups

  Edit pii-matchers.yaml to customise detection, then commit it to your repository.
  Run matchers update after upgrading Clonio to add new baseline matchers.
```

### 3.4 Effect on `cloning:dump`

After `matchers init` runs, `cloning:dump` uses the file instead of the binary defaults. Any edit the user makes — changing a transformation, adding patterns, disabling a matcher, adding a custom group — is immediately reflected on the next `cloning:dump` run.

---

## 4. `matchers update`

### 4.1 Command Signature

```
matchers update
    {--dry-run  : Show what would be added without writing anything}
    {--path=    : Path to the pii-matchers.yaml file (default: pii-matchers.yaml in cwd)}
```

### 4.2 Behaviour

1. Resolve the file path.
2. If the file does not exist → exit `IOError (5)` and suggest `matchers init`.
3. Parse the existing `pii-matchers.yaml` into the current group/matcher structure.
4. Load the full baseline from `PiiMatcherBaselineProvider`.
5. **Compute the diff** (see §4.3).
6. If `--dry-run` → print the diff and exit `Success (0)` without writing.
7. Apply additions to the file and write it back.
8. Print a summary of changes made.

### 4.3 Diff Rules

The update command never modifies or removes entries that already exist in the file. It adds new baseline matchers and reports orphaned ones.

| Scenario | Action |
|----------|--------|
| Baseline matcher key exists in file | **Leave untouched** — user owns it |
| Baseline matcher key not in file | **Add** it to its baseline group; if the group is also new, append it at the end of the file |
| File matcher key not in baseline (user-defined custom entry) | **Leave untouched** — report as custom in output |
| File matcher key not in baseline (was in an older baseline, now removed) | **Leave untouched** — report as **orphaned** (see §4.5) |
| Baseline group key exists in file | Add new matchers into that existing group |
| Baseline group key not in file | Create the group using its baseline key and name; append it **at the end** of the file |

The invariant: after `matchers update`, every matcher that was already in the file is exactly as the user left it.

### 4.4 Detecting new matchers

The update command compares matcher **keys** (e.g. `email_address`, `national_id`). Key-based identity is stable across binary versions. If the baseline adds a new key, it is considered new. If the baseline changes the default patterns or transformation for an existing key, that change is **not** propagated — the user's version of that matcher is preserved.

### 4.5 Output (normal mode)

The output always shows three sections: additions, orphaned matchers, and a summary. Sections with no entries are omitted.

```
  Checking pii-matchers.yaml against binary baseline ...

  New matchers added:
    financial    →  crypto_wallet_address  "Crypto Wallet Address"
    network      →  mac_address            "MAC Address"   (new group)

  Orphaned matchers (in file but no longer in baseline):
    personal_identity  →  maiden_name  "Maiden Name"
    ⚠  These are no longer recognised by the current Clonio baseline.
       They may be custom entries or from an older version.
       Remove them manually if they are no longer needed.

  2 matchers added. 1 orphaned matcher reported. Your existing customisations were not changed.
```

When the file is already up to date with no orphans:
```
  Checking pii-matchers.yaml against binary baseline ...

  pii-matchers.yaml is up to date. No changes needed.
```

### 4.6 Output (`--dry-run`)

Same as normal mode output but ends with:
```
  Dry run — no changes written.
```

---

## 5. `matchers list`

### 5.1 Command Signature

```
matchers list
    {--path=  : Path to pii-matchers.yaml (default: pii-matchers.yaml in cwd)}
```

### 5.2 Behaviour

1. Load the effective matcher set via `PiiMatcherLoader`:
   - If `pii-matchers.yaml` exists at the resolved path, use it.
   - Otherwise fall back to the binary baseline.
2. Print all groups and matchers in evaluation order.

### 5.3 Output

One row per matcher, grouped by their group name. The source column shows whether the matcher came from the file or the baseline.

```
  Effective PII matchers  (source: pii-matchers.yaml)

  Personal Identity
    ✓  first_name       "First Name"          fake → firstName       [file]
    ✓  last_name        "Last Name"           fake → lastName        [file]
    ✓  date_of_birth    "Date of Birth"       fake → date            [file]
    ✓  national_id      "National ID / SSN"   hash → sha256          [file]
    —  full_name        "Person Name"                                [file, disabled]

  Contact Information
    ✓  email_address    "Email Address"       fake → safeEmail       [file]
    ✓  phone_number     "Phone Number"        fake → phoneNumber     [file]

  ...

  Total: 18 active matchers across 6 groups  (2 disabled)
  Source: pii-matchers.yaml
```

When falling back to the baseline:
```
  Effective PII matchers  (source: binary baseline — run matchers init to customise)
  ...
```

In `--ci` mode: no output; exit `Success (0)` always (the command is informational only).

---

## 6. `matchers check`

### 6.1 Command Signature

```
matchers check
    {column  : Column name to test against the active matcher set}
    {--path= : Path to pii-matchers.yaml (default: pii-matchers.yaml in cwd)}
```

### 6.2 Behaviour

1. Load the effective matcher set (same as `list`).
2. Run `PiiMatcherSetData::match($column)`.
3. Print the result.

### 6.3 Output

**Match found:**
```
  Column "email" matched:

    Matcher:        email_address
    Group:          contact
    PII category:   "Email Address"
    Source:         pii-matchers.yaml
    Matched by:     /^(e[-_]?mail|email[-_]?addr(ess)?|...)$/i  (regex)

    Transformation:
      strategy:       fake
      faker_method:   safeEmail
      faker_arguments: []
```

**No match:**
```
  Column "created_at" — no matcher found

  This column will be treated as strategy: keep by cloning:dump.
```

**Disabled matcher would have matched:**
```
  Column "api_key" — no active matcher found

  Note: matcher "api_token" (authentication) would match but is disabled.
        Set enabled: true in pii-matchers.yaml to activate it.
```

Exit code is always `Success (0)` — the command is a diagnostic tool, not a validator.

---

## 8. Shared UX Details

### 5.1 `--ci` mode

Both commands honour the global `--ci` flag (defined in **PRD-command-behaviour.md**):
- No stdout output.
- Errors go to stderr with `[ERROR]` prefix.
- Exit codes are the only signal.
- `--dry-run` is compatible with `--ci`: prints the diff to stdout; still no write.

### 5.2 `--path` option

Allows targeting a `pii-matchers.yaml` file in a non-standard location. Useful in monorepos with multiple Clonio projects in subdirectories:

```bash
clonio matchers init --path services/auth/pii-matchers.yaml
clonio matchers update --path services/auth/pii-matchers.yaml
```

---

## 9. DTOs and Architecture

### 6.1 Init options DTO

```php
// app/Data/Cloning/MatchersInitOptionsData.php
final readonly class MatchersInitOptionsData
{
    public function __construct(
        public string $outputPath,
        public bool $force,
    ) {}
}
```

### 6.2 Update options DTO

```php
// app/Data/Cloning/MatchersUpdateOptionsData.php
final readonly class MatchersUpdateOptionsData
{
    public function __construct(
        public string $filePath,
        public bool $dryRun,
    ) {}
}
```

### 6.3 Update diff DTO

```php
// app/Data/Cloning/MatchersUpdateDiffData.php
final readonly class MatchersUpdateDiffData
{
    /**
     * @param list<NewMatcherEntryData>      $additions
     * @param list<OrphanedMatcherEntryData> $orphans
     */
    public function __construct(
        public array $additions,
        public array $orphans,
        public bool $hasChanges,    // true when additions is non-empty
    ) {}
}
```

```php
// app/Data/Cloning/NewMatcherEntryData.php
final readonly class NewMatcherEntryData
{
    public function __construct(
        public string $groupKey,
        public string $groupName,
        public string $matcherKey,
        public string $matcherName,
        public bool $groupIsNew,   // true = group did not exist in the file before this update
    ) {}
}
```

```php
// app/Data/Cloning/OrphanedMatcherEntryData.php
final readonly class OrphanedMatcherEntryData
{
    public function __construct(
        public string $groupKey,
        public string $matcherKey,
        public string $matcherName,
    ) {}
}
```

---

## 10. Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `PiiMatcherBaselineProvider` | Returns the hardcoded baseline as `list<PiiMatcherGroupData>` |
| `PiiMatcherLoader` | Loads `pii-matchers.yaml` if present, otherwise returns baseline; used by `list` and `check` |
| `PiiMatcherYamlWriter` | Serialises `list<PiiMatcherGroupData>` → YAML; writes via `Storage::disk('local')` |
| `PiiMatcherYamlReader` | Parses `pii-matchers.yaml` → `list<PiiMatcherGroupData>` |
| `PiiMatcherUpdateService` | Diffs existing groups against baseline; returns `MatchersUpdateDiffData`; applies additions |

---

## 11. Error Cases

| Situation | Command | Exit Code | Behaviour |
|-----------|---------|-----------|-----------|
| Output path not writable | `init` | IOError (5) | Show path and permission hint |
| File exists, no `--force`, user declines | `init` | Success (0) | Exit silently |
| `pii-matchers.yaml` not found | `update` | IOError (5) | Suggest `matchers init` |
| `pii-matchers.yaml` invalid YAML | `update` | ValidationError (4) | Show parse error with line/column |
| `pii-matchers.yaml` fails schema validation | `update` | ValidationError (4) | List all validation errors |
| File already up to date | `update` | Success (0) | Print "up to date" message |
| `pii-matchers.yaml` not found | `list` | Success (0) | Show baseline with note that no file exists |
| `pii-matchers.yaml` not found | `check` | Success (0) | Check against baseline; note that no file exists |
| `pii-matchers.yaml` invalid YAML | `list`, `check` | ValidationError (4) | Show parse error with line/column |

---

## 12. `.gitignore` Guidance

After running `matchers init`, Clonio prints:

```
  Tip: pii-matchers.yaml contains no credentials and is safe to commit.
       Make sure clonio.json is in your .gitignore.
```

---

## 13. Out of Scope

- `matchers remove <key>` — users set `enabled: false` or delete entries manually
- `matchers validate` — validation happens implicitly on any command that reads the file
- Interactive matcher editor (TUI)
- Pushing matcher updates to a remote config service

---

## 14. Decisions

- **Orphaned matchers are reported, not removed.** `matchers update` reports matchers in the file that are no longer in the baseline so the user can decide whether to remove them. The file is never modified to remove them automatically.
- **`matchers init --merge` is not implemented.** `init` always writes the full baseline (replacing any existing file after confirmation). Users who want to merge use `matchers update` instead.
- **New groups are appended at the end of the file.** There is no defined sort order for groups; new baseline groups are simply added after all existing groups.
