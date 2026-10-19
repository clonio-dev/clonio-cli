# Audit Channel Commands

Manage audit delivery channels in `clonio.json`. Channels define where Clonio sends audit logs and process logs after each operation.

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
3. **Type-specific fields** — Prompted based on the chosen type (see below). Network channels (`s3`, `email`, `ms_teams`, `slack`, `ntfy`) finish with "Override retry settings?" (default: no).
4. **Summary** — A table of the collected values (secrets masked).
5. **Save this channel?** — Confirm to write the channel to `clonio.json`; answering no cancels without changes.

Every type-specific field has a matching option. Values passed as options are not prompted again.

### Channel types

| Type | Value | Description |
|---|---|---|
| Local filesystem | `local` | Write log files to a local directory |
| S3-compatible storage | `s3` | Upload logs to any S3-compatible object store |
| Email (SMTP) | `email` | Send logs via email over SMTP |
| Microsoft Teams | `ms_teams` | Post notifications via an incoming webhook |
| Slack | `slack` | Post notifications via an incoming webhook |
| ntfy.sh / self-hosted ntfy | `ntfy` | Send push notifications via ntfy |
| Standard output | `stdout` | Print to standard output (no fields) |
| Standard error | `stderr` | Print to standard error (no fields) |
| Stack | `stack` | Fan out delivery to multiple existing channels |

### Options

#### Common

| Option | Description |
|---|---|
| `name` | Channel name (argument, optional) |
| `--type=` | Channel type: `local`, `s3`, `email`, `ms_teams`, `slack`, `ntfy`, `stdout`, `stderr`, `stack` |
| `--enable` | Also add this channel to `audit.use` in `clonio.json` (active delivery list) |

#### Local (`--type=local`)

| Option | Description |
|---|---|
| `--local-path=` | Directory path template for audit and process logs (default: `./clonio-logs/{year}/{month}`) |

#### S3 (`--type=s3`)

| Option | Description |
|---|---|
| `--s3-endpoint=` | S3-compatible endpoint URL |
| `--s3-bucket=` | Bucket name |
| `--s3-region=` | Region string |
| `--s3-access-key=` | Access key ID |
| `--s3-secret-key=` | Secret access key (stored encrypted) |
| `--s3-path-prefix=` | Key prefix template (default: `clonio/{year}/{month}/{source}/`) |

Default retry settings: 5 attempts, 1000 ms initial delay, multiplier 2, max delay 30000 ms.

#### Email (`--type=email`)

| Option | Description |
|---|---|
| `--mail-host=` | SMTP host |
| `--mail-port=` | SMTP port (default: `587`) |
| `--mail-encryption=` | Encryption: `tls` (default), `ssl`, `none` |
| `--mail-username=` | SMTP username |
| `--mail-password=` | SMTP password (stored encrypted) |
| `--mail-from-address=` | Sender email address |
| `--mail-from-name=` | Sender display name |
| `--mail-to=` | Comma-separated recipient addresses |

Default retry settings: 3 attempts, 500 ms initial delay, multiplier 2, max delay 10000 ms.

#### Microsoft Teams / Slack (`--type=ms_teams` or `--type=slack`)

| Option | Description |
|---|---|
| `--webhook-url=` | Incoming webhook URL (stored encrypted) |

Default retry settings: 3 attempts, 500 ms initial delay, multiplier 2, max delay 10000 ms.

#### ntfy (`--type=ntfy`)

| Option | Description |
|---|---|
| `--ntfy-url=` | ntfy server base URL (default: `https://ntfy.sh`) |
| `--ntfy-topic=` | ntfy topic name |
| `--ntfy-priority=` | Notification priority: `min`, `low`, `default` (default), `high`, `max` |
| `--ntfy-tags=` | Comma-separated tag strings (optional) |
| `--ntfy-token=` | Bearer token for authenticated servers (stored encrypted, optional) |

Topics on the public `ntfy.sh` server are readable by anyone who knows the name. Use a hard-to-guess topic or a self-hosted server for sensitive environments.

Default retry settings: 3 attempts, 500 ms initial delay, multiplier 2, max delay 10000 ms.

#### Stack (`--type=stack`)

A stack has no options. The command lists the existing channels and asks for "Channels to include (comma-separated)". At least one other channel must already exist; otherwise the command fails with a config error.

### Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Channel added successfully, or save was cancelled by the user |
| `2` | `ConfigError` | `clonio.json` not found, encryption failed, or a stack was requested before any other channel exists |
| `4` | `ValidationError` | Invalid channel name, duplicate name, or unknown `--type` |
| `5` | `IoError` | Could not write to `clonio.json` |

### Examples

```bash
# Interactive
clonio audit:add

# Non-interactive: local channel, enabled immediately
clonio audit:add logs --type=local --local-path='./clonio-logs/{year}/{month}' --enable

# S3 channel (the secret key is prompted, so it stays out of shell history)
clonio audit:add s3-backup --type=s3 --s3-endpoint=https://s3.amazonaws.com \
  --s3-bucket=my-bucket --s3-region=eu-west-1 --s3-access-key=AKIA... \
  --s3-path-prefix='clonio/{year}/{month}/'

# Slack channel (the webhook URL is prompted)
clonio audit:add slack-alerts --type=slack

# Email with multiple recipients
clonio audit:add email-report --type=email --mail-host=smtp.example.com --mail-port=587 \
  --mail-encryption=tls --mail-username=bot@example.com --mail-from-address=bot@example.com \
  --mail-from-name="Clonio" --mail-to="alice@example.com,bob@example.com"

# Fan out to existing channels (channel names are prompted)
clonio audit:add production --type=stack
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

Deletes an audit delivery channel from `clonio.json`. If the channel is referenced by `audit.use`, it is automatically removed from that list as well.

### Usage

```bash
clonio audit:delete [<name>] [--force]
```

If `name` is omitted and only one channel exists, it is selected automatically. With multiple channels, an interactive selection prompt is shown.

A warning is displayed if the channel is currently in `audit.use`. Stack channels that reference the deleted channel are not changed; remove the name from their `channels` list with `audit:update`.

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

Lists all configured audit delivery channels.

### Usage

```bash
clonio audit:list
```

Outputs a table with columns: **Name**, **Type**, **Active** (★ if the channel is listed in `audit.use`), **Details**.

The Details column shows a summary appropriate to the channel type:
- **Local**: audit log path
- **S3**: `s3://bucket/path-prefix`
- **Email**: first recipient address
- **Teams / Slack**: `(webhook — encrypted)`
- **ntfy**: `server-url / topic`
- **Stack**: comma-separated child channel names

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

### Stack Channel

The `stack` type fans out delivery to multiple child channels. Use it when you want to deliver audit artefacts to several destinations at once.

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | Must be `"stack"` |
| `channels` | yes | Array of channel names to deliver to |

**Example:**
```json
"production": {
    "type": "stack",
    "channels": ["local", "s3-backup", "slack-notify"]
}
```

### Active Channels (`audit.use`)

The `audit.use` array selects which channels actively deliver audit artefacts:

```json
"audit": {
    "use": ["local"],
    "channels": { ... }
}
```

Channels configured in `audit.channels` but not listed in `audit.use` are inert — they will not deliver.
To deliver to multiple channels, list them in `use` or define a `stack` channel and reference it.
The `--audit-channel` CLI flag overrides `audit.use` for a single run.

Use `--enable` when adding a channel to add it to `audit.use` in one step:

```bash
clonio audit:add logs --type=local --local-path=./ --enable
```

### Secret encryption

All secrets (S3 secret keys, SMTP passwords, webhook URLs, ntfy tokens) are stored encrypted using Laravel's `Crypt` facade:

```
encrypted:<base64-encoded-ciphertext>
```

This requires `APP_KEY` to be set in the environment (`.env` or exported). Passing secrets via CLI flags (`--s3-secret-key=`, `--mail-password=`, `--webhook-url=`, `--ntfy-token=`) is supported but prints a warning, because the value may end up in shell history. Prefer the interactive prompt.

### What each channel delivers

Each channel has independent control over whether it delivers the audit artefacts and the process log:

| Channel type | Audit log (default) | Process log (default) |
|---|---|---|
| `local` | Yes | Yes |
| `s3` | Yes | Yes |
| `email` | Yes | No |
| `ms_teams` | Yes | No |
| `slack` | Yes | No |
| `ntfy` | Yes | No |
| `stdout` / `stderr` | Yes | No |

`audit:add` always uses these defaults. To change them, set the `delivers_audit` and `delivers_process_log` boolean keys directly in `clonio.json`:

```json
{
  "audit": {
    "use": ["local"],
    "channels": {
      "local": {
        "type": "local",
        "path": "./",
        "delivers_audit": true,
        "delivers_process_log": false
      },
      "slack-alerts": {
        "type": "slack",
        "webhook_url": "encrypted:...",
        "delivers_audit": true,
        "delivers_process_log": true
      }
    }
  }
}
```

`audit:update` does not prompt for these keys; it keeps whatever values are set in `clonio.json`.

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

S3, email, Teams, Slack, and ntfy channels support optional retry configuration. During `audit:add`, you are prompted with "Override retry settings?" (default: no). If yes, four values are collected, pre-filled with the type defaults listed above:

| Field | Description |
|---|---|
| `max_attempts` | Maximum delivery attempts |
| `initial_delay_ms` | Milliseconds to wait before the first retry |
| `backoff_multiplier` | Multiplier applied to the delay on each attempt |
| `max_delay_ms` | Maximum delay cap in milliseconds |

`audit:update` preserves existing retry settings automatically — edit `clonio.json` directly to change retry values after the channel is created.
