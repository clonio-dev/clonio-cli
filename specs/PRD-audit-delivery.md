# PRD — Audit Log, Run Log & Delivery Channels

**Version:** 0.1
**Status:** Draft
**Date:** 2026-04-01

---

## 1. Goal

Define two artefacts produced at the end of every `cloning:run`:

1. **Audit log** — a signed, human-readable document certifying what was transferred, which transformations were applied, and that the run completed without tampering. Intended for compliance auditors.
2. **Run log** — a structured, machine-readable full execution log capturing every table, chunk, skipped row, and timing event. Intended for engineers debugging or monitoring runs.

Both artefacts are delivered via configurable **channels** (local filesystem, S3-compatible storage, email). The channel configuration lives in `clonio.json`. Multiple channels can be active simultaneously via a `stack` pattern.

---

## 2. Document Formats

### 2.1 Audit Log — HTML + Signature (v1)

**Format**: HTML file generated from a Blade/Twig template bundled in the binary.

HTML is chosen for v1 because:
- It requires no external binary (no Puppeteer, no wkhtmltopdf).
- Pure-PHP PDF libraries (`dompdf`) work inside SPC binaries and will be added in v1.1.
- HTML is universally readable, diffable in VCS, and accepted by most compliance workflows.
- The signing mechanism is independent of the file format.

**v1.1 upgrade path**: Add `dompdf/dompdf` to produce a `.pdf` alongside or instead of the `.html`. The signing mechanism and delivery channels remain unchanged.

### 2.2 Audit Log — Signing (v1)

The HTML file is signed using HMAC-SHA256, keyed with `APP_KEY` — the same algorithm used in the companion `AuditService`. A sidecar `.sig` file contains the signature.

**Signing process:**
1. Serialise the audit record to a canonical JSON string (UTF-8, no Unicode escaping, no slash escaping — matching the companion repo's approach).
2. Compute `$hash = hash('sha256', $canonicalJson)`.
3. Compute `$signature = hash_hmac('sha256', $hash, config('app.key'))`.
4. Write the signature file: one line, format `sha256:<signature>`.

**Verification**: any holder of `APP_KEY` can re-derive the canonical JSON from the document and verify `hash_equals()`.

The `.sig` file is always delivered alongside the audit log on the same channel.

### 2.3 Run Log — JSONL (v1)

**Format**: newline-delimited JSON (`.jsonl`). One JSON object per log event. Machine-readable, streamable, importable into log aggregators (Datadog, Loki, etc.).

Each line:
```json
{"ts":"2026-04-01T14:32:00.123Z","level":"info","event":"table_start","table":"users","estimated_rows":12340}
{"ts":"2026-04-01T14:32:01.456Z","level":"info","event":"table_done","table":"users","rows":12340,"skipped":0,"duration_ms":1333}
{"ts":"2026-04-01T14:32:01.789Z","level":"warn","event":"row_skipped","table":"orders","reason":"fk_violation","row_pk":9901}
```

Standard `level` values: `debug`, `info`, `warn`, `error`.

---

## 3. File Naming Convention

Both artefacts use the same naming pattern:

```
{source}_{target}_{timestamp}_{type}.{ext}
```

| Placeholder | Example | Notes |
|-------------|---------|-------|
| `{source}` | `production-db` | Source connection name from YAML |
| `{target}` | `staging` | Target connection name |
| `{timestamp}` | `2026-04-01T14-32-00Z` | ISO 8601, colons replaced with hyphens for filesystem compatibility |
| `{type}` | `audit` or `run` | |
| `{ext}` | `html`, `sig`, `jsonl` | |

**Example set of files for one run:**
```
production-db_staging_2026-04-01T14-32-00Z_audit.html
production-db_staging_2026-04-01T14-32-00Z_audit.sig
production-db_staging_2026-04-01T14-32-00Z_run.jsonl
```

---

## 4. Audit Log Content

The audit log HTML document contains the following sections:

### 4.1 Header
- Clonio version and binary hash
- Run timestamp (start and end, UTC)
- Source connection name and driver type (no host/credentials)
- Target connection name and driver type
- YAML file name (not full path — avoid leaking filesystem structure)
- Run outcome: Success / Partial failure / Failed

### 4.2 Configuration Snapshot
- All `options` from the YAML (chunk_size, FK checks, etc.)
- For each table: row selection strategy + limit; list of columns with their transformation strategy
- `keep` columns are listed as a count only ("43 columns transferred without transformation") to keep the document readable

### 4.3 Transfer Summary
- Per-table: rows transferred, rows skipped, duration, skip reasons (FK violation, unique conflict)
- Totals: tables, rows, skipped rows, overall duration

### 4.4 PII Compliance Summary
- Count of columns with anonymization applied, grouped by strategy (fake / hash / mask / null / static)
- List of tables with at least one transformation
- Statement: "All PII columns configured for transformation in this run were processed."

### 4.5 Integrity Section
- Document hash (SHA-256 of canonical JSON)
- HMAC-SHA256 signature (truncated to first 16 chars for display; full value in `.sig` file)
- Verification instruction: `clonio cloning:verify-audit <file>`

---

## 5. Delivery Channels

### 5.1 Design: Named Channel Registry + Separate Routing

Channels are defined once by name in a `channels` registry and then referenced independently by the audit log and the run log. This means:
- The same channel (e.g. `local-storage`) can receive both artefacts.
- Different channels can be assigned to each artefact (e.g. audit log → email + S3; run log → S3 only).
- Adding a new channel definition does not automatically route anything to it.

### 5.2 Configuration in `clonio.json`

The full `audit` block:

```json
"audit": {
  "channels": {
    "local-storage": {
      "type": "local",
      "path": "./audits/{year}/{month}"
    },
    "s3-compliance": {
      "type": "s3",
      "endpoint": "https://s3.eu-central-1.amazonaws.com",
      "bucket": "my-clonio-audits",
      "access_key": "AKIAIOSFODNN7EXAMPLE",
      "secret_key": "encrypted:eyJpdiI6...",
      "region": "eu-central-1",
      "path_prefix": "clonio/{year}/{month}/{source}/",
      "retry": {
        "max_attempts": 5,
        "initial_delay_ms": 1000,
        "backoff_multiplier": 2,
        "max_delay_ms": 30000
      }
    },
    "email-auditors": {
      "type": "email",
      "host": "smtp.example.com",
      "port": 587,
      "encryption": "tls",
      "username": "clonio@example.com",
      "password": "encrypted:eyJpdiI6...",
      "from_address": "clonio@example.com",
      "from_name": "Clonio Audit",
      "to": ["auditor@company.com", "compliance@company.com"],
      "retry": {
        "max_attempts": 10,
        "initial_delay_ms": 2000,
        "backoff_multiplier": 3,
        "max_delay_ms": 60000
      }
    }
  },
  "audit_log": {
    "deliver_to": ["local-storage", "s3-compliance", "email-auditors"]
  },
  "run_log": {
    "deliver_to": ["local-storage", "s3-compliance"]
  },
  "retry": {
    "max_attempts": 3,
    "initial_delay_ms": 500,
    "backoff_multiplier": 2,
    "max_delay_ms": 10000
  }
}
```

`audit_log.deliver_to` and `run_log.deliver_to` reference keys from the `channels` map. Each listed channel receives its respective artefacts independently. If `audit` is absent from `clonio.json`, all delivery is silently skipped.

**Per-channel retry override**: Each channel definition may include its own `retry` block. When present, it takes precedence over the top-level `audit.retry` block for that channel only. This allows tuning retry behaviour independently — e.g. more attempts for email (which has longer transient failures) versus local storage (which fails fast). Channels without a `retry` block fall back to the top-level `audit.retry` defaults.

### 5.3 Path Template Variables

Both `local.path` and `s3.path_prefix` support template variables that are resolved at delivery time. Variables are wrapped in `{` `}`.

| Variable | Example output | Description |
|----------|---------------|-------------|
| `{year}` | `2026` | 4-digit UTC year of the run start |
| `{month}` | `04` | 2-digit UTC month (zero-padded) |
| `{day}` | `01` | 2-digit UTC day (zero-padded) |
| `{source}` | `production-db` | Source connection name (from YAML) |
| `{target}` | `staging` | Target connection name |

**Examples:**
```
path_prefix: "clonio/{year}/{month}/{source}/"
→  clonio/2026/04/production-db/

path: "./audits/{year}/{month}"
→  ./audits/2026/04/
```

Unknown variable names (e.g. `{foo}`) are left as-is and a warning is logged.

### 5.4 Channel Type: `local`

Writes files to the local filesystem via `Storage::disk('local')`.

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | Must be `"local"` |
| `path` | yes | Target directory. Supports template variables (§5.3). Relative paths resolved from cwd. |

### 5.5 Channel Type: `s3`

Uploads to any S3-compatible storage (AWS S3, Cloudflare R2, MinIO, Hetzner Object Storage, etc.).

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | Must be `"s3"` |
| `endpoint` | yes | Full HTTPS URL of the S3 endpoint |
| `bucket` | yes | Target bucket name |
| `access_key` | yes | S3 access key ID |
| `secret_key` | yes | S3 secret (store as `encrypted:` prefixed value) |
| `region` | yes | Region string (e.g. `eu-central-1`) |
| `path_prefix` | yes | Key prefix inside the bucket. Supports template variables (§5.3). Use `""` for root. |

Object key: `{resolved_path_prefix}{filename}`

S3 client: `AsyncAws/S3` — pure PHP, SPC-compatible, no ext-curl required.

### 5.6 Channel Type: `email`

Sends an email with the audit log HTML and `.sig` as attachments. The run log is **not** attached (too large); the email body notes where to find it on other configured channels.

| Field | Required | Description |
|-------|:--------:|-------------|
| `type` | yes | Must be `"email"` |
| `host` | yes | SMTP hostname |
| `port` | yes | SMTP port (587 = STARTTLS, 465 = implicit TLS) |
| `encryption` | yes | `tls`, `ssl`, or `none` |
| `username` | yes | SMTP auth username |
| `password` | yes | SMTP password (store as `encrypted:` prefixed value) |
| `from_address` | yes | Sender address |
| `from_name` | yes | Sender display name (e.g. `"Clonio Audit"`) |
| `to` | yes | Array of recipient addresses (at least one) |

SMTP client: `symfony/mailer`.

### 5.7 Future Channel Types (not in v1)

The channel system is designed for extension. Future types are added by implementing `DeliveryChannelInterface` and registering the type key.

| Type key | Description |
|----------|-------------|
| `teams` | Post a card to a Microsoft Teams incoming webhook |
| `ntfy` | Push a notification to ntfy.sh with audit log attached |
| `slack` | Post to a Slack channel via webhook |
| `webhook` | HTTP POST the audit log to a configurable HTTPS endpoint |

---

## 6. `clonio.json` Schema Extension

The `audit` key is added to `clonio.schema.json`.

```json
"audit": {
  "type": "object",
  "required": ["channels", "audit_log", "run_log"],
  "additionalProperties": false,
  "properties": {
    "channels": {
      "type": "object",
      "description": "Named channel definitions. Keys are user-defined channel names.",
      "minProperties": 1,
      "additionalProperties": { "$ref": "#/$defs/AuditChannel" }
    },
    "audit_log": {
      "type": "object",
      "required": ["deliver_to"],
      "additionalProperties": false,
      "properties": {
        "deliver_to": {
          "type": "array",
          "minItems": 1,
          "items": { "type": "string" },
          "description": "Names of channels (from the channels map) that receive the audit log + .sig file."
        }
      }
    },
    "run_log": {
      "type": "object",
      "required": ["deliver_to"],
      "additionalProperties": false,
      "properties": {
        "deliver_to": {
          "type": "array",
          "minItems": 0,
          "items": { "type": "string" },
          "description": "Names of channels that receive the run log. May be empty to disable run log delivery."
        }
      }
    },
    "retry": {
      "description": "Default retry settings. Overridden per-channel via the channel's own retry block.",
      "$ref": "#/$defs/RetryConfig"
    }
  }
},
"$defs": {
  "RetryConfig": {
    "type": "object",
    "required": ["max_attempts", "initial_delay_ms", "backoff_multiplier", "max_delay_ms"],
    "additionalProperties": false,
    "properties": {
      "max_attempts":       { "type": "integer", "minimum": 1 },
      "initial_delay_ms":   { "type": "integer", "minimum": 0 },
      "backoff_multiplier": { "type": "number",  "minimum": 1 },
      "max_delay_ms":       { "type": "integer", "minimum": 0 }
    }
  },
  "AuditChannel": {
    "type": "object",
    "required": ["type"],
    "properties": {
      "type": { "type": "string", "enum": ["local", "s3", "email"] }
    },
    "discriminator": { "propertyName": "type" },
    "oneOf": [
      { "$ref": "#/$defs/LocalChannel" },
      { "$ref": "#/$defs/S3Channel" },
      { "$ref": "#/$defs/EmailChannel" }
    ]
  },
  "LocalChannel": {
    "type": "object",
    "required": ["type", "path"],
    "additionalProperties": false,
    "properties": {
      "type":  { "const": "local" },
      "path":  { "type": "string", "minLength": 1 },
      "retry": { "$ref": "#/$defs/RetryConfig" }
    }
  },
  "S3Channel": {
    "type": "object",
    "required": ["type", "endpoint", "bucket", "access_key", "secret_key", "region", "path_prefix"],
    "additionalProperties": false,
    "properties": {
      "type":        { "const": "s3" },
      "endpoint":    { "type": "string" },
      "bucket":      { "type": "string" },
      "access_key":  { "type": "string" },
      "secret_key":  { "type": "string", "pattern": "^encrypted:.+" },
      "region":      { "type": "string" },
      "path_prefix": { "type": "string" },
      "retry":       { "$ref": "#/$defs/RetryConfig" }
    }
  },
  "EmailChannel": {
    "type": "object",
    "required": ["type", "host", "port", "encryption", "username", "password", "from_address", "from_name", "to"],
    "additionalProperties": false,
    "properties": {
      "type":         { "const": "email" },
      "host":         { "type": "string" },
      "port":         { "type": "integer", "minimum": 1, "maximum": 65535 },
      "encryption":   { "type": "string", "enum": ["tls", "ssl", "none"] },
      "username":     { "type": "string" },
      "password":     { "type": "string", "pattern": "^encrypted:.+" },
      "from_address": { "type": "string", "format": "email" },
      "from_name":    { "type": "string", "minLength": 1 },
      "to": {
        "type": "array",
        "minItems": 1,
        "items": { "type": "string", "format": "email" }
      },
      "retry": { "$ref": "#/$defs/RetryConfig" }
    }
  }
}
```

If `audit` is absent from `clonio.json`, no artefacts are written and no delivery is attempted.

---

## 7. Audit Record DTO

```php
// app/Data/Audit/AuditRecordData.php
final readonly class AuditRecordData
{
    /**
     * @param list<AuditTableRecordData> $tables
     * @param list<string>               $channels
     */
    public function __construct(
        public string $clonioVersion,
        public string $sourceConnection,
        public string $targetConnection,
        public string $yamlFileName,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
        public bool $success,
        public CloningOptionsData $options,
        public array $tables,
        public int $totalRowsTransferred,
        public int $totalRowsSkipped,
        public array $channels,
        public string $contentHash,       // SHA-256 of canonical JSON
        public string $hmacSignature,     // HMAC-SHA256(contentHash, APP_KEY)
    ) {}
}
```

```php
// app/Data/Audit/AuditTableRecordData.php
final readonly class AuditTableRecordData
{
    /**
     * @param list<AuditColumnRecordData> $transformedColumns
     */
    public function __construct(
        public string $tableName,
        public bool $existed,              // false = table was in YAML but not in source
        public bool $skippedByFlag,        // true = excluded via --skip-tables
        public string $rowStrategy,
        public ?int $rowLimit,
        public int $rowsTransferred,
        public int $rowsSkipped,
        public float $durationSeconds,
        public array $transformedColumns,  // only non-keep columns
        public int $keptColumnCount,
    ) {}
}
```

```php
// app/Data/Audit/AuditColumnRecordData.php
final readonly class AuditColumnRecordData
{
    public function __construct(
        public string $columnName,
        public string $strategy,   // fake | hash | mask | null | static
    ) {}
}
```

---

## 8. Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `AuditLogBuilder` | Build `AuditRecordData` from `RunResultData` + config snapshot |
| `AuditLogSigner` | Serialise to canonical JSON; compute SHA-256 hash and HMAC-SHA256 signature |
| `AuditLogRenderer` | Render `AuditRecordData` to HTML using a Blade template |
| `RunLogWriter` | Accumulate JSONL log events during the run; flush to bytes at end |
| `PathTemplateResolver` | Resolve `{year}`, `{month}`, `{day}`, `{source}`, `{target}` in path strings |
| `AuditDeliveryService` | Read channel config; route artefacts to the correct adapters; orchestrate retries |
| `LocalDeliveryAdapter` | Write files via `Storage::disk('local')` after resolving path template |
| `S3DeliveryAdapter` | Upload via `AsyncAws/S3` after resolving path template |
| `EmailDeliveryAdapter` | Send via `symfony/mailer`; attach audit HTML + `.sig`; omit run log |

---

## 9. `cloning:verify-audit` Command

Verifies the integrity of a stored audit log by comparing its content against the accompanying `.sig` file. Any holder of the project's `APP_KEY` can run this.

```
cloning:verify-audit
    {file   : Path to the .html audit log file}
    {--sig= : Path to the .sig file (default: <file>.sig in the same directory)}
```

**Behaviour:**
1. Locate the `.sig` file: use `--sig` if provided, otherwise look for `<file>.sig` next to the HTML.
2. Read both files.
3. Re-derive the canonical JSON from the audit JSON block embedded in the HTML.
4. Recompute `SHA-256(canonicalJson)`.
5. Recompute `HMAC-SHA256(hash, APP_KEY)`.
6. Compare both values against the stored values in `.sig` using `hash_equals()`.
7. Exit `Success (0)` if both match; `GeneralError (1)` if either does not.

**Success output:**
```
  ✓  Audit log verified — signature matches
     File:      production-db_staging_2026-04-01T14-32-00Z_audit.html
     SHA-256:   e3b0c44298fc1c149afb...
     Signed at: 2026-04-01T14:34:12Z
```

**Failure output:**
```
  ✗  Audit log verification FAILED — document may have been tampered with
     File:      production-db_staging_2026-04-01T14-32-00Z_audit.html
     Expected:  a3f1c9... (from .sig)
     Got:       d72e04... (recomputed)
```

The command does not connect to any database or network resource. It only reads the two local files and the local `APP_KEY`.

---

## 10. Delivery Failure Handling

### 10.1 Retry with Exponential Backoff

Every channel delivery attempt is wrapped in a retry loop. The retry parameters are resolved in this order:
1. The channel's own `retry` block (if present in its channel definition).
2. The top-level `audit.retry` block (if present).
3. Built-in defaults (shown below).

This means individual channels can have more or fewer attempts than others — useful when email delivery warrants more patience than a local write.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `max_attempts` | `3` | Total attempts including the first try |
| `initial_delay_ms` | `500` | Delay before the second attempt |
| `backoff_multiplier` | `2` | Multiplier applied to the delay after each failure |
| `max_delay_ms` | `10000` | Cap on computed delay |

**Delay formula** (full jitter — avoids thundering herd across channels):
```
cap     = min(max_delay_ms, initial_delay_ms × backoff_multiplier^(attempt − 1))
delay   = random_int(0, cap)   // milliseconds
```

**Attempt sequence with defaults:**
```
Attempt 1 → fails
  wait ~0–500 ms
Attempt 2 → fails
  wait ~0–1 000 ms
Attempt 3 → fails → give up; log warning
```

### 10.2 Per-Channel Independence

Each channel is retried independently. Failure of one channel does not block or skip other channels.

### 10.3 Failure Outcomes

| Situation | Behaviour |
|-----------|-----------|
| Attempt succeeds before max_attempts | Stop retrying; log success |
| All attempts exhausted on one channel | Log `[WARN]` with channel name and last error; continue |
| All channels fail for audit log | Log `[ERROR]`; run exit code still reflects data transfer only |
| All channels fail for run log | Log `[WARN]`; no effect on exit code |
| `audit` section absent from `clonio.json` | Skip all delivery silently |
| Unknown variable in path template (e.g. `{foo}`) | Log `[WARN]`; leave variable as-is in resolved path |

Delivery failures never affect the run's exit code.

---

## 11. Out of Scope

- True cryptographic PDF signing (PAdES/PKCS#12) — requires certificate infrastructure; deferred to v2
- PDF generation — deferred to v1.1 via `dompdf/dompdf` (pure PHP, SPC-compatible)
- Audit log encryption at rest (beyond HMAC signing) — future
- Streaming delivery (uploading while the run is still in progress) — future
- `{week}` path template variable for ISO week-based partitioning — not supported

---

## 12. Decisions

- **`--audit-channel=` CLI flag**: Added to `cloning:run` (see PRD-cloning-run.md §3). Accepts a comma-separated list of channel names. When provided, overrides `audit_log.deliver_to` and `run_log.deliver_to` for that run only — only the specified channels receive artefacts. Useful for ad-hoc delivery without editing `clonio.json`.
- **`{week}` path variable**: Not implemented. ISO week-based partitioning is not supported. Use `{year}/{month}` or `{year}/{month}/{day}` instead.
- **Per-channel retry overrides**: Each channel definition accepts an optional `retry` block. It takes precedence over the top-level `audit.retry` block for that channel. Channels without a `retry` block fall back to the top-level defaults. Resolution order: channel retry → global retry → built-in defaults.
