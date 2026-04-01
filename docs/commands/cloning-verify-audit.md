# `cloning:verify-audit` Command

Verify the integrity of a Clonio audit log by re-deriving its HMAC-SHA256 signature and comparing it against the stored `.sig` sidecar file. Any holder of the project's `APP_KEY` can run this command.

## Usage

```bash
clonio cloning:verify-audit <file> [--sig=<path>]
```

## Arguments

| Argument | Description |
|----------|-------------|
| `file` | Path to the `.html` audit log file to verify |

## Options

| Option | Description |
|--------|-------------|
| `--sig=<path>` | Path to the `.sig` file (default: `<file>.sig` in the same directory) |

## Description

Every `cloning:run` produces a signed audit log. The audit log HTML contains an embedded canonical JSON block describing the run. A companion `.sig` file holds the HMAC-SHA256 signature of that block.

`cloning:verify-audit` re-derives the signature from the HTML file and checks it against the `.sig` file using a constant-time comparison. This confirms that the document has not been modified since it was produced.

The command does not connect to any database or network resource. It only reads the two local files and the local `APP_KEY`.

## Example

```bash
clonio cloning:verify-audit production-db_staging_2026-04-01T14-32-00Z_audit.html
```

### Success

```
  ✓  Audit log verified — signature matches
     File:      production-db_staging_2026-04-01T14-32-00Z_audit.html
     SHA-256:   e3b0c44298fc1c149afb...
     Signed at: 2026-04-01T14:34:12Z
```

### Failure (tampered or corrupted document)

```
  ✗  Audit log verification FAILED — document may have been tampered with
     File:      production-db_staging_2026-04-01T14-32-00Z_audit.html
     Expected:  a3f1c9... (from .sig)
     Got:       d72e04... (recomputed)
```

### Specifying a custom `.sig` path

```bash
clonio cloning:verify-audit audit.html --sig=/archive/2026/04/audit.sig
```

## How Signing Works

1. Clonio serialises the audit record to a canonical JSON string (UTF-8, no Unicode escaping).
2. Computes `SHA-256` of the canonical JSON.
3. Computes `HMAC-SHA256(sha256Hash, APP_KEY)`.
4. Writes the signature to the `.sig` sidecar file in the format `sha256:<signature>`.

Verification reverses steps 1–3 using the same `APP_KEY` and compares both values using `hash_equals()`.

## Audit Log File Naming

Audit logs produced by `cloning:run` follow this naming pattern:

```
{source}_{target}_{timestamp}_audit.html
{source}_{target}_{timestamp}_audit.sig
```

For example:
```
production-db_staging_2026-04-01T14-32-00Z_audit.html
production-db_staging_2026-04-01T14-32-00Z_audit.sig
```

Both files must be present for verification.

## Exit Codes

| Code | Meaning |
|:----:|---------|
| `0` | Signature verified successfully |
| `1` | Signature does not match — document may have been tampered with |
| `2` | Config error — `APP_KEY` not set or not readable |
| `5` | IO error — audit log file or `.sig` file not found |

## Related Commands

- `cloning:run` — execute a cloning transfer and produce the audit log
- `init` — set up `APP_KEY` required for signing and verification
