# PRD — `audit:add`, `audit:update`, `audit:delete`, `audit:list` Commands

**Version:** 0.2
**Status:** Draft
**Date:** 2026-04-02

---

## 1. Goal

Provide four CRUD commands so users can manage audit delivery channels in `clonio.json` without hand-editing the file. The commands mirror the `connection:add`, `connection:update`, `connection:delete` pattern and manage the `audit.channels` registry along with the `audit_log.deliver_to` and `run_log.deliver_to` routing arrays.

---

## 2. Background

`cloning:run` delivers audit logs and run logs via named channels defined in `clonio.json`. The channel structure (types `local`, `s3`, `email`, `ms_teams`, `slack`, `ntfy`) is defined in **PRD-audit-delivery.md §5**. Until now users had to configure channels by hand. These commands provide a safe, guided interface with validation, encryption of secrets, and consistency checks against the routing arrays.

---

## 3. `audit.channels` Structure in `clonio.json`

The full `audit` block is described in **PRD-audit-delivery.md §5.2**. The relevant shape for these commands is:

```json
"audit": {
  "channels": {
    "local-storage": {
      "type": "local",
      "audit_log": { "path": "./audits/{year}/{month}" },
      "run_log":   { "path": "./run-logs/{year}/{month}" }
    },
    "s3-compliance": {
      "type": "s3",
      "endpoint":    "https://s3.eu-central-1.amazonaws.com",
      "bucket":      "my-clonio-audits",
      "access_key":  "AKIAIOSFODNN7EXAMPLE",
      "secret_key":  "encrypted:eyJpdiI6...",
      "region":      "eu-central-1",
      "path_prefix": "clonio/{year}/{month}/{source}/",
      "retry": { "max_attempts": 5, "initial_delay_ms": 1000, "backoff_multiplier": 2, "max_delay_ms": 30000 }
    },
    "email-auditors": {
      "type": "email",
      "host":         "smtp.example.com",
      "port":         587,
      "encryption":   "tls",
      "username":     "clonio@example.com",
      "password":     "encrypted:eyJpdiI6...",
      "from_address": "clonio@example.com",
      "from_name":    "Clonio Audit",
      "to":           ["auditor@company.com", "compliance@company.com"]
    },
    "teams-ops": {
      "type": "ms_teams",
      "webhook_url": "encrypted:eyJpdiI6..."
    },
    "slack-compliance": {
      "type": "slack",
      "webhook_url": "encrypted:eyJpdiI6..."
    },
    "ntfy-alerts": {
      "type": "ntfy",
      "url":      "https://ntfy.sh",
      "topic":    "clonio-audits",
      "token":    "encrypted:eyJpdiI6...",
      "priority": "default",
      "tags":     ["file_cabinet", "clonio"]
    }
  },
  "audit_log": { "deliver_to": ["local-storage", "s3-compliance", "email-auditors"] },
  "run_log":   { "deliver_to": ["local-storage", "s3-compliance"] },
  "retry": { "max_attempts": 3, "initial_delay_ms": 500, "backoff_multiplier": 2, "max_delay_ms": 10000 }
}
```

---

## 4. Channel Types and Fields

### 4.1 `local`

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | `"local"` |
| `audit_log.path` | yes | Directory path template for audit HTML + `.sig` files |
| `run_log.path` | yes | Directory path template for run `.jsonl` files |

### 4.2 `s3`

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | `"s3"` |
| `endpoint` | yes | S3-compatible endpoint URL |
| `bucket` | yes | Bucket name |
| `access_key` | yes | Access key ID |
| `secret_key` | yes | Secret access key — encrypted before storage |
| `region` | yes | AWS / S3 region string (e.g. `eu-central-1`) |
| `path_prefix` | yes | Key prefix template inside the bucket |
| `retry` | no | Per-channel retry override (see §4.4) |

### 4.3 `email`

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | `"email"` |
| `host` | yes | SMTP hostname |
| `port` | yes | SMTP port (integer) |
| `encryption` | yes | `tls` \| `ssl` \| `none` |
| `username` | yes | SMTP auth username |
| `password` | yes | SMTP password — encrypted before storage |
| `from_address` | yes | Sender email address |
| `from_name` | yes | Sender display name |
| `to` | yes | Array of recipient addresses (at least one) |
| `retry` | no | Per-channel retry override (see §4.4) |

### 4.4 `ms_teams`

Delivers a formatted Adaptive Card notification to a Microsoft Teams channel via an incoming webhook. The card includes the run summary (source → target, tables transferred, rows, duration, status). The full audit HTML is **not** attached — only a notification is sent.

| Field | Required | Description |
| --- | :---: | --- |
| `type` | yes | `"ms_teams"` |
| `webhook_url` | yes | Teams incoming webhook URL — encrypted before storage |
| `retry` | no | Per-channel retry override (see §4.7) |

### 4.5 `slack`

Delivers a formatted Block Kit message to a Slack channel via an incoming webhook. The message includes the run summary. The full audit HTML is **not** attached.

| Field | Required | Description |
| --- | :---: | --- |
| `type` | yes | `"slack"` |
| `webhook_url` | yes | Slack incoming webhook URL — encrypted before storage |
| `retry` | no | Per-channel retry override (see §4.7) |

### 4.6 `ntfy`

Delivers a push notification via [ntfy.sh](https://ntfy.sh) (cloud or self-hosted). The notification body contains the run summary (status, tables, rows). The full audit HTML is **not** attached.

| Field | Required | Description |
| --- | :---: | --- |
| `type` | yes | `"ntfy"` |
| `url` | yes | ntfy server base URL (e.g. `https://ntfy.sh` or self-hosted) |
| `topic` | yes | Notification topic (e.g. `clonio-audits`) |
| `token` | no | Bearer token for authenticated topics — encrypted before storage |
| `priority` | no | `min` \| `low` \| `default` \| `high` \| `max` (default: `default`) |
| `tags` | no | Array of tag/emoji strings shown in notification (e.g. `["file_cabinet"]`) |
| `retry` | no | Per-channel retry override (see §4.7) |

### 4.7 Per-channel `retry` block (optional, `s3`, `email`, `ms_teams`, `slack`, `ntfy`)

When present, overrides the top-level `audit.retry` defaults for that channel only. Resolution order: channel retry → global `audit.retry` → built-in defaults (see **PRD-audit-delivery.md §10.1**).

| Field | Type | Description |
|-------|------|-------------|
| `max_attempts` | integer | Total attempts including the first try |
| `initial_delay_ms` | integer | Delay before the second attempt |
| `backoff_multiplier` | number | Multiplier applied after each failure |
| `max_delay_ms` | integer | Cap on computed delay |

### 4.8 Path Template Variables

`local.audit_log.path`, `local.run_log.path`, and `s3.path_prefix` support these template variables (resolved at delivery time):

| Variable | Example | Description |
|----------|---------|-------------|
| `{year}` | `2026` | 4-digit UTC year of run start |
| `{month}` | `04` | 2-digit UTC month (zero-padded) |
| `{day}` | `01` | 2-digit UTC day (zero-padded) |
| `{source}` | `production-db` | Source connection name |
| `{target}` | `staging` | Target connection name |
| `{timestamp}` | `2026-04-01T14-32-00Z` | ISO 8601 run start, colons replaced with hyphens |

---

## 5. `audit:add` Command

### 5.1 Command Signature

```
audit:add
    {name?              : Unique name for this channel}
    {--type=            : Channel type — local|s3|email|ms_teams|slack|ntfy}
    {--audit-log-path=  : (local) Audit log path template}
    {--run-log-path=    : (local) Run log path template}
    {--endpoint=        : (s3) S3-compatible endpoint URL}
    {--bucket=          : (s3) Bucket name}
    {--access-key=      : (s3) Access key ID}
    {--secret-key=      : (s3) Secret access key (stored encrypted; flag discouraged)}
    {--region=          : (s3) Region string}
    {--path-prefix=     : (s3) Key prefix template}
    {--host=            : (email) SMTP host}
    {--port=            : (email) SMTP port}
    {--encryption=      : (email) Encryption — tls|ssl|none}
    {--username=        : (email) SMTP username}
    {--password=        : (email) SMTP password (stored encrypted; flag discouraged)}
    {--from-address=    : (email) Sender address}
    {--from-name=       : (email) Sender display name}
    {--to=              : (email) Comma-separated recipient addresses}
    {--webhook-url=     : (ms_teams|slack) Incoming webhook URL (stored encrypted; flag discouraged)}
    {--url=             : (ntfy) ntfy server base URL}
    {--topic=           : (ntfy) ntfy topic}
    {--token=           : (ntfy) ntfy bearer token (stored encrypted; flag discouraged)}
    {--priority=        : (ntfy) Notification priority — min|low|default|high|max}
    {--tags=            : (ntfy) Comma-separated tag strings}
    {--deliver-audit-log : Add channel to audit_log.deliver_to}
    {--no-deliver-audit-log : Do not add channel to audit_log.deliver_to}
    {--deliver-run-log  : Add channel to run_log.deliver_to}
    {--no-deliver-run-log : Do not add channel to run_log.deliver_to}
```

### 5.2 Interactive Flow

Prompts are shown in this fixed order. A prompt is skipped when the corresponding option flag is supplied.

1. **Name** — free-form text; pre-filled from `{name?}` argument if provided. Validated as `^[a-z0-9_-]+$`, max 64 chars, unique among existing channels.
2. **Type** — choice list: `local`, `s3`, `email`, `ms_teams`, `slack`, `ntfy`.
3. **Type-specific fields** (see §5.3).
4. **Deliver audit log?** — yes / no (default `yes` for all types). If yes, the channel name is appended to `audit.audit_log.deliver_to`.
5. **Deliver run log?** — yes / no (default `yes` for `local` and `s3`; default `no` for `email`, `ms_teams`, `slack`, `ntfy` — run logs are large and notification channels carry only summaries). If yes, the channel name is appended to `audit.run_log.deliver_to`.
6. Summary table of all entered values. Secrets (`secret_key`, `password`) shown as `••••••••`.
7. **"Save channel? [yes/no]"** — defaults to `yes`. On `no`, exit cleanly without writing.

### 5.3 Type-specific Fields

#### `local`

| Prompt | Default | Notes |
|--------|---------|-------|
| Audit log path | `./audit-logs/{year}/{month}` | Hint text lists supported template variables (§4.5) |
| Run log path | `./run-logs/{year}/{month}` | Same hint |

#### `s3`

| Prompt | Default | Notes |
|--------|---------|-------|
| Endpoint | — | Must be a valid HTTPS URL |
| Bucket | — | Non-empty string |
| Region | — | Non-empty string |
| Access key | — | Non-empty string |
| Secret key | — | Masked input; encrypted with `Crypt::encryptString()` on save; stored with `encrypted:` prefix |
| Path prefix | `clonio/{year}/{month}/{source}/` | Hint text lists template variables |
| Override retry? (y/N) | `no` | If yes, prompt: Max attempts (default `5`), Initial delay ms (default `1000`), Backoff multiplier (default `2`), Max delay ms (default `30000`) |

#### `email`

| Prompt | Default | Notes |
|--------|---------|-------|
| SMTP host | — | Non-empty string |
| SMTP port | `587` | Integer, 1–65535 |
| Encryption | `tls` | Choice: `tls`, `ssl`, `none` |
| Username | — | Non-empty string |
| Password | — | Masked input; encrypted on save |
| From address | — | Valid email format |
| From name | — | Non-empty string |
| Recipients | — | Comma-separated; split and stored as array; at least one valid email required |
| Override retry? (y/N) | `no` | If yes, prompt: Max attempts (default `3`), Initial delay ms (default `500`), Backoff multiplier (default `2.0`), Max delay ms (default `10000`) |

#### `ms_teams`

| Prompt | Default | Notes |
|--------|---------|-------|
| Webhook URL | — | Masked input; must be a valid HTTPS URL; encrypted on save |
| Override retry? (y/N) | `no` | If yes, prompt: Max attempts (default `3`), Initial delay ms (default `500`), Backoff multiplier (default `2.0`), Max delay ms (default `10000`) |

Hint text: "Create an incoming webhook in Teams under Apps → Incoming Webhook, or via a Power Automate / Workflows connector."

#### `slack`

| Prompt | Default | Notes |
|--------|---------|-------|
| Webhook URL | — | Masked input; must be a valid HTTPS URL starting with `https://hooks.slack.com/`; encrypted on save |
| Override retry? (y/N) | `no` | If yes, prompt: Max attempts (default `3`), Initial delay ms (default `500`), Backoff multiplier (default `2.0`), Max delay ms (default `10000`) |

Hint text: "Create an incoming webhook at api.slack.com → Your Apps → Incoming Webhooks."

#### `ntfy`

| Prompt | Default | Notes |
|--------|---------|-------|
| Server URL | `https://ntfy.sh` | Must be a valid HTTPS URL |
| Topic | — | Non-empty string; avoid spaces |
| Token (optional) | — | Masked input; press Enter to skip; if provided, encrypted on save |
| Priority | `default` | Choice: `min`, `low`, `default`, `high`, `max` |
| Tags (optional) | — | Comma-separated tag strings; press Enter to skip; stored as array |
| Override retry? (y/N) | `no` | If yes, prompt: Max attempts (default `3`), Initial delay ms (default `500`), Backoff multiplier (default `2.0`), Max delay ms (default `10000`) |

Hint text: "Topics are public by default on ntfy.sh. Use a hard-to-guess topic name or a self-hosted server with a token for sensitive environments."

### 5.4 Secret Flags Warning

When `--secret-key=`, `--password=`, `--webhook-url=`, or `--token=` is supplied via flag, the command prints a yellow warning before continuing:

```
[WARNING] Passing secrets via CLI flags may expose them in shell history and process
          listings. Consider omitting the flag and entering the value interactively.
```

---

## 6. `audit:update` Command

### 6.1 Command Signature

```
audit:update
    {name?  : Name of the channel to update}
```

No option flags — all editing is interactive with pre-filled values.

### 6.2 Interactive Flow

1. **Name selection** — if `name` argument is omitted, the user selects from a list of existing channels; if only one channel exists it is selected automatically.
2. Re-prompt all fields for the selected channel type **in the same order as `audit:add` §5.2–5.3**, with current values pre-filled:
   - Text fields: current value shown as default (user can press Enter to keep).
   - Secret fields (`secret_key`, `password`): shown as `••••••••`; press Enter to keep the existing encrypted value unchanged; type a new value to replace it.
   - Choice fields: current value highlighted.
   - Retry block: "Override retry?" shown as `yes` if an override is already set; current retry values pre-filled.
3. Re-prompt **"Deliver audit log?"** and **"Deliver run log?"** with current membership pre-selected.
4. Display a diff of changed fields only — one line per change in the format `field: old-value → new-value`. Secret fields shown as `•••` → `•••` if changed, unchanged secrets produce no diff line.
5. **"Save changes? [yes/no]"** — defaults to `yes`. On `no`, exit cleanly without writing.

### 6.3 Type Lock

Channel type cannot be changed after creation. The type field is displayed for information but the choice list is not shown. To change a channel's type, delete the channel and re-add it.

### 6.4 Secret Handling

- Existing encrypted values are **never decrypted** for display.
- If the user presses Enter on a secret field, the stored `encrypted:...` value is preserved as-is.
- If the user types a new value, it is encrypted with `Crypt::encryptString()` and the stored value is replaced.

---

## 7. `audit:delete` Command

### 7.1 Command Signature

```
audit:delete
    {name?   : Name of the channel to delete}
    {--force : Skip confirmation prompt}
```

### 7.2 Interactive Flow

1. **Name selection** — if `name` argument is omitted, the user selects from a list of existing channels; if only one channel exists it is selected automatically.
2. Display a summary table of the channel to be deleted (secrets shown as `••••••••`).
3. If the channel is currently referenced in `audit_log.deliver_to` or `run_log.deliver_to`, display a yellow warning:

   ```
   [WARNING] This channel is active on N deliver_to list(s). Deleting it will remove
             it from all audit_log.deliver_to and run_log.deliver_to entries.
   ```

   The warning is shown even when `--force` is passed.
4. **"Delete channel '<name>'? This cannot be undone. [yes/no]"** — defaults to `no`.
5. On confirmation: remove the key from `audit.channels`; remove the channel name from `audit.audit_log.deliver_to` and `audit.run_log.deliver_to` (if present in either); write `clonio.json`.

If `--force` is passed, step 4 is skipped and deletion proceeds immediately after the warning.

---

## 8. `audit:list` Command

### 8.1 Command Signature

```
audit:list
```

No arguments or options.

### 8.2 Output

Prints a summary table of all configured channels. The **Details** column shows the most useful identifier for each type: the path for `local`, the bucket URL for `s3`, the first recipient for `email`, the webhook host for `ms_teams` and `slack`, and the server + topic for `ntfy`.

| Name | Type | Audit Log | Run Log | Details |
|------|------|:---------:|:-------:|---------|
| local-storage | local | ✓ | ✓ | `./audits/{year}/{month}` |
| s3-compliance | s3 | ✓ | ✗ | `s3://my-bucket/clonio/...` |
| email-auditors | email | ✓ | ✗ | `auditor@company.com` |
| teams-ops | ms_teams | ✓ | ✗ | `outlook.office.com/...` |
| slack-compliance | slack | ✓ | ✗ | `hooks.slack.com/...` |
| ntfy-alerts | ntfy | ✓ | ✗ | `ntfy.sh / clonio-audits` |

- **Audit Log** and **Run Log** columns reflect membership in `audit_log.deliver_to` and `run_log.deliver_to` respectively.
- If no channels are configured, print: `No audit channels configured. Run \`audit:add\` to add one.`

---

## 9. Storage

### 9.1 Config File Location

Channels are stored in **`clonio.json`** in the current working directory. The `filesystems.php` disk `local` uses `getcwd()` as root; read/write via `Storage::disk('local')`. See **PRD-clonio-json.md** for the full file structure and JSON Schema.

### 9.2 Prerequisite: File Must Exist

Unlike `connection:add`, these commands do **not** create `clonio.json` from scratch. The `audit` section is part of an existing project config. If `clonio.json` is absent, exit with `ConfigError (2)`.

If `clonio.json` exists but the `audit` key is absent, `audit:add` creates the `audit` block with an empty `channels` object, an empty `audit_log.deliver_to` array, and an empty `run_log.deliver_to` array. All other audit commands require the `audit` key to already exist.

### 9.3 File Permissions

`clonio.json` is written with `0600` permissions (owner read/write only) on first write. If the file already exists with different permissions, the permissions are not changed.

### 9.4 Encryption

- `secret_key` (s3), `password` (email), `webhook_url` (ms_teams, slack), and `token` (ntfy, if provided) are encrypted using `Crypt::encryptString()`.
- The encrypted value is stored with the `encrypted:` prefix.
- `APP_KEY` must be set (via env var or `.env` — see **PRD-init.md**). If absent, exit with `ConfigError (2)` and instruct the user to run `clonio init`.
- Secrets are **never decrypted** for display; they are always shown as `••••••••`.

---

## 10. Validation Rules

| Field | Rule |
|-------|------|
| `name` | Required; `^[a-z0-9_-]+$`; max 64 chars; unique among existing channels |
| `type` | Must be one of `local`, `s3`, `email`, `ms_teams`, `slack`, `ntfy` |
| `audit_log.path` (local) | Required; non-empty string |
| `run_log.path` (local) | Required; non-empty string |
| `endpoint` (s3) | Required; valid HTTPS URL |
| `bucket` (s3) | Required; non-empty string |
| `access_key` (s3) | Required; non-empty string |
| `secret_key` (s3) | Required; non-empty string; encrypted on save |
| `region` (s3) | Required; non-empty string |
| `path_prefix` (s3) | Required; string (may be empty `""` for bucket root) |
| `host` (email) | Required; non-empty string |
| `port` (email) | Required; integer, 1–65535 |
| `encryption` (email) | Must be `tls`, `ssl`, or `none` |
| `username` (email) | Required; non-empty string |
| `password` (email) | Required; non-empty string; encrypted on save |
| `from_address` (email) | Required; valid email format |
| `from_name` (email) | Required; non-empty string |
| `to` (email) | Required; at least one valid email address |
| `webhook_url` (ms_teams) | Required; valid HTTPS URL; encrypted on save |
| `webhook_url` (slack) | Required; valid HTTPS URL starting with `https://hooks.slack.com/`; encrypted on save |
| `url` (ntfy) | Required; valid HTTPS URL |
| `topic` (ntfy) | Required; non-empty string, no spaces |
| `token` (ntfy) | Optional; encrypted on save if provided |
| `priority` (ntfy) | Must be one of `min`, `low`, `default`, `high`, `max` |
| `tags` (ntfy) | Optional; array of non-empty strings |
| `retry.max_attempts` | Integer ≥ 1 |
| `retry.initial_delay_ms` | Integer ≥ 0 |
| `retry.backoff_multiplier` | Number ≥ 1 |
| `retry.max_delay_ms` | Integer ≥ 0 |

Validation is applied after the user completes all prompts, before the confirmation step. Validation errors cause a re-prompt for the offending field (interactive mode) or exit `ValidationError (4)` (flag-only mode).

---

## 11. Error Cases

| Situation | Behaviour |
|-----------|-----------|
| `clonio.json` missing | Exit `ConfigError (2)`; suggest running `clonio init` |
| `clonio.json` invalid JSON | Exit `ConfigError (2)`; show parse error and path |
| `APP_KEY` missing | Exit `ConfigError (2)`; instruct user to run `clonio init` |
| `name` already exists (`audit:add`) | Exit `ValidationError (4)`; suggest `audit:update <name>` |
| `name` not found (`audit:update`, `audit:delete`) | Exit `ValidationError (4)`; suggest `audit:list` |
| No channels exist (`audit:update`, `audit:delete`) | Exit `ValidationError (4)`; suggest `audit:add` |
| `to` array empty after parsing (`email`) | Exit `ValidationError (4)`; re-prompt |
| `port` out of range | Exit `ValidationError (4)`; re-prompt in interactive mode |
| No write permission in cwd | Exit `IOError (5)`; show path and permission hint |
| User answers `no` at confirmation | Exit `Success (0)` without writing; display "Cancelled." |

Exit codes follow the conventions in **PRD-command-behaviour.md §6**.

---

## 12. UX Details

- **Secret masking:** `secret_key` and `password` fields use a masked prompt (input not echoed to terminal) and are always displayed as `••••••••` in tables and diffs.
- **Summary table:** Shown after all prompts (step 6 in `audit:add`, step 6 in `audit:update`). Columns: Field, Value.
- **Diff view (`audit:update`):** Only changed fields appear. Format: `field: "old" → "new"`. Unchanged fields are not listed.
- **Cancellation:** If the user answers `no` at the final confirmation, exit cleanly with code `0` and print `Cancelled.` No file is written.
- **`audit` block creation:** If `clonio.json` exists but contains no `audit` key, `audit:add` creates the minimal `audit` block and prints an info note: `No audit section found in clonio.json — creating it now.`

---

## 13. Out of Scope

- Creating `clonio.json` from scratch — use `clonio init` and `connection:add` first
- Changing a channel's type after creation — delete and re-add
- Batch-adding or batch-deleting channels
- Testing channel connectivity (future: `audit:test <name>`)
- Importing channel config from environment variables or DSN strings
- Managing the top-level `audit.retry` defaults — edit `clonio.json` manually
- Managing the `audit_log.deliver_to` and `run_log.deliver_to` arrays independently — use `audit:add` / `audit:update`

---

## 14. Open Questions

- [ ] Should `audit:update` allow renaming a channel? If so, both `deliver_to` arrays must be updated atomically. Currently not supported — same approach as `connection:update` which does allow renames.
- [ ] Should `audit:test <name>` be a separate command (e.g. send a test file to the channel)? Deferred to a future PRD.
- [ ] Local channel: should `audit_log.path` and `run_log.path` be separate top-level fields on the channel, or nested under `audit_log` / `run_log` sub-objects? The `clonio.json` structure in the user request uses nested objects; this PRD follows that structure.
