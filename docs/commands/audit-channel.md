# Audit Channel Commands

Manage audit delivery channels in `clonio.json`. Channels define where Clonio sends audit logs and run logs after each operation.

## Commands

| Command | Description |
|---|---|
| `audit:add` | Add a new audit delivery channel |
| `audit:update` | Update an existing audit delivery channel |
| `audit:delete` | Delete an audit delivery channel |
| `audit:list` | List all configured audit delivery channels |

---

## `audit:add`

Adds a new audit delivery channel to the `clonio.json` configuration file in the current directory.

### Usage

```bash
clonio audit:add [<name>] [options]
```

All arguments and options are optional. Any value not supplied via a flag will be collected interactively.

### Interactive flow

When run without flags the command walks through each field in order:

1. **Name** — A unique identifier for the channel. Must match `[a-z0-9_-]+` (max 64 chars).
2. **Type** — Selected from a list of supported channel types.
3. **Type-specific fields** — Prompted based on the chosen type (see below).
4. **Deliver audit log** — Whether to add this channel to `audit_log.deliver_to` (default: yes).
5. **Deliver run log** — Whether to add this channel to `run_log.deliver_to` (default depends on type).

A summary table is displayed before writing. The operation can be cancelled at the final confirmation prompt.

### Channel types

| Type | Value | Description |
|---|---|---|
| Local filesystem | `local` | Write log files to a local directory |
| S3-compatible storage | `s3` | Upload logs to any S3-compatible object store |
| Email (SMTP) | `email` | Send logs via email over SMTP |
| Microsoft Teams | `ms_teams` | Post notifications via an incoming webhook |
| Slack | `slack` | Post notifications via an incoming webhook |
| ntfy.sh / self-hosted ntfy | `ntfy` | Send push notifications via ntfy |

### Options

#### Common

| Option | Description |
|---|---|
| `name` | Channel name (argument, optional) |
| `--type=` | Channel type: `local`, `s3`, `email`, `ms_teams`, `slack`, `ntfy` |
| `--deliver-audit-log` | Add channel to `audit_log.deliver_to` |
| `--no-deliver-audit-log` | Do not add channel to `audit_log.deliver_to` |
| `--deliver-run-log` | Add channel to `run_log.deliver_to` |
| `--no-deliver-run-log` | Do not add channel to `run_log.deliver_to` |

#### Local (`--type=local`)

| Option | Description |
|---|---|
| `--audit-log-path=` | Directory path template for audit logs (default: `./audit-logs/{year}/{month}`) |
| `--run-log-path=` | Directory path template for run logs (default: `./run-logs/{year}/{month}`) |

Path templates support: `{year}`, `{month}`, `{day}`, `{source}`, `{target}`, `{timestamp}`.

#### S3 (`--type=s3`)

| Option | Description |
|---|---|
| `--endpoint=` | S3-compatible endpoint URL |
| `--bucket=` | Bucket name |
| `--region=` | Region string |
| `--access-key=` | Access key ID |
| `--secret-key=` | Secret access key (stored encrypted) |
| `--path-prefix=` | Key prefix template (default: `clonio/{year}/{month}/{source}/`) |

#### Email (`--type=email`)

| Option | Description |
|---|---|
| `--host=` | SMTP host |
| `--port=` | SMTP port (default: `587`) |
| `--encryption=` | Encryption: `tls`, `ssl`, `none` |
| `--username=` | SMTP username |
| `--password=` | SMTP password (stored encrypted) |
| `--from-address=` | Sender email address |
| `--from-name=` | Sender display name |
| `--to=` | Comma-separated recipient addresses |

#### Microsoft Teams / Slack (`--type=ms_teams` or `--type=slack`)

| Option | Description |
|---|---|
| `--webhook-url=` | Incoming webhook URL (stored encrypted) |

#### ntfy (`--type=ntfy`)

| Option | Description |
|---|---|
| `--url=` | ntfy server base URL (default: `https://ntfy.sh`) |
| `--topic=` | ntfy topic name |
| `--priority=` | Notification priority: `min`, `low`, `default`, `high`, `max` |
| `--tags=` | Comma-separated tag strings (optional) |
| `--token=` | Bearer token for authenticated servers (stored encrypted, optional) |

### Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Channel added successfully, or save was cancelled by the user |
| `2` | `ConfigError` | `clonio.json` not found, or encryption failed |
| `4` | `ValidationError` | Invalid channel name or duplicate name |
| `5` | `IoError` | Could not write to `clonio.json` |

### Examples

```bash
# Interactive
clonio audit:add

# Non-interactive: local channel
clonio audit:add logs --type=local --audit-log-path=./audit/{year}/{month} --run-log-path=./runs/{year}/{month}

# Non-interactive: S3 channel
clonio audit:add s3-backup --type=s3 --endpoint=https://s3.amazonaws.com --bucket=my-bucket \
  --region=eu-west-1 --access-key=AKIA... --path-prefix=clonio/{year}/{month}/

# Non-interactive: Slack channel, audit log only
clonio audit:add slack-alerts --type=slack --deliver-audit-log --no-deliver-run-log

# Non-interactive: email with multiple recipients
clonio audit:add email-report --type=email --host=smtp.example.com --port=587 \
  --encryption=tls --username=bot@example.com --from-address=bot@example.com \
  --from-name="Clonio" --to="alice@example.com,bob@example.com"
```

---

## `audit:update`

Updates an existing audit delivery channel. The channel type cannot be changed — delete and re-add the channel to switch types.

### Usage

```bash
clonio audit:update [<name>]
```

If `name` is omitted and only one channel exists, it is selected automatically. With multiple channels, an interactive selection prompt is shown.

All type-specific fields are re-prompted with the current values as defaults. Secrets display as `••••••••` — press Enter to keep the existing value, or enter a new one to replace it.

A diff table is shown before the final confirmation so you can review exactly what will change.

### Options

| Option | Description |
|---|---|
| `name` | Name of the channel to update (argument, optional) |

### Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Channel updated successfully, or save was cancelled by the user |
| `2` | `ConfigError` | No channels configured, or unknown type in stored config |
| `4` | `ValidationError` | Named channel not found |
| `5` | `IoError` | Could not write to `clonio.json` |

### Examples

```bash
# Interactive selection
clonio audit:update

# Update specific channel
clonio audit:update s3-backup
```

---

## `audit:delete`

Deletes an audit delivery channel from `clonio.json`. The channel is also automatically removed from any `deliver_to` lists it belongs to.

### Usage

```bash
clonio audit:delete [<name>] [--force]
```

If `name` is omitted and only one channel exists, it is selected automatically. With multiple channels, an interactive selection prompt is shown.

A warning is displayed if the channel is currently active in one or more `deliver_to` lists.

### Options

| Option | Description |
|---|---|
| `name` | Name of the channel to delete (argument, optional) |
| `--force` | Skip the confirmation prompt |

### Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Channel deleted successfully, or deletion was cancelled by the user |
| `2` | `ConfigError` | No channels configured |
| `4` | `ValidationError` | Named channel not found |
| `5` | `IoError` | Could not write to `clonio.json` |

### Examples

```bash
# Interactive selection
clonio audit:delete

# Delete specific channel
clonio audit:delete logs

# Delete without confirmation (e.g. in scripts)
clonio audit:delete logs --force
```

---

## `audit:list`

Lists all configured audit delivery channels and their delivery assignments.

### Usage

```bash
clonio audit:list
```

Outputs a table with columns: **Name**, **Type**, **Audit Log** (✓/✗), **Run Log** (✓/✗), **Details**.

The Details column shows a summary appropriate to the channel type:
- **Local**: audit log path
- **S3**: `s3://bucket/path-prefix`
- **Email**: first recipient address
- **Teams / Slack**: `(webhook — encrypted)`
- **ntfy**: `server-url / topic`

### Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Always (outputs an empty-state message if no channels are configured) |

### Examples

```bash
clonio audit:list
```

---

## Notes

### Secret encryption

All secrets (S3 secret keys, SMTP passwords, webhook URLs, ntfy tokens) are stored encrypted using Laravel's `Crypt` facade:

```
encrypted:<base64-encoded-ciphertext>
```

This requires `APP_KEY` to be set in the environment (`.env` or exported). Passing secrets via CLI flags (`--secret-key=`, `--password=`, `--webhook-url=`, `--token=`) is supported but may expose them in shell history — use the interactive prompt instead.

### clonio.json location

All commands read from and write to `clonio.json` in the **current working directory**. Run commands from the root of the project whose configuration you want to manage.

### Path templates

Local and S3 path fields support the following placeholders:

| Placeholder | Description |
|---|---|
| `{year}` | Four-digit year (e.g. `2025`) |
| `{month}` | Two-digit month (e.g. `04`) |
| `{day}` | Two-digit day (e.g. `15`) |
| `{source}` | Source connection name |
| `{target}` | Target connection name |
| `{timestamp}` | Unix timestamp at run start |

### Retry settings

S3, email, Teams, Slack, and ntfy channels support optional retry configuration. During `audit:add`, you are prompted with "Override retry settings?" (default: no). If yes, four values are collected:

| Field | Description |
|---|---|
| `max_attempts` | Maximum delivery attempts |
| `initial_delay_ms` | Milliseconds to wait before the first retry |
| `backoff_multiplier` | Multiplier applied to the delay on each attempt |
| `max_delay_ms` | Maximum delay cap in milliseconds |

`audit:update` preserves existing retry settings automatically — edit `clonio.json` directly to change retry values after the channel is created.
