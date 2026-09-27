# `connection:test` Command

Tests the connectivity of one or all saved database connections defined in `clonio.json`.

## Usage

Test a specific connection by name:

```bash
clonio connection:test <name>
```

Test all connections at once:

```bash
clonio connection:test
```

Suppress table output for use in scripts and CI pipelines:

```bash
clonio connection:test --ci
```

## Behaviour

### Single connection

When a `name` argument is provided, only that connection is tested. The result is printed on one line:

```
staging: OK (42ms, tls: require)
```

If the connection fails:

```
staging: FAILED — Connection refused
```

### All connections

When no name is given, every connection in `clonio.json` is tested and the results are displayed in a table:

```
 ────────────┬────────────┬─────────┬────────┬────────
  Connection   Driver       TLS      Status   Time
 ────────────┼────────────┼─────────┼────────┼────────
  local        SQLite       —        OK       1ms
  staging      MySQL        require  OK       38ms
  prod         PostgreSQL   default  OK       55ms
 ────────────┴────────────┴─────────┴────────┴────────

All 3 connections OK.
```

The `TLS` column shows the configured transport mode (`default`, `disable`, `require`, `verify`), matching `connection:list`. SQLite and Dump connections show `—` since they have no network transport.

With `-v`, an additional `Cipher` column shows the negotiated TLS cipher for each successful MySQL/MariaDB/PostgreSQL connection. Encrypted SQL Server connections show `encrypted (cipher not reported by SQL Server)`. The column shows `—` when the connection is not encrypted or isn't a network connection. The cipher is only queried when it will actually be displayed, so no extra query runs without `-v`.

A summary line is always printed regardless of `--ci` mode.

### What "tested" means

| Driver | Method |
|--------|--------|
| SQLite | Checks that the database file exists, is readable, and is writable. No network connection is attempted. |
| MySQL, MariaDB, PostgreSQL, SQL Server | Opens a real TCP connection using `PDO` and calls `getPdo()`. The connection is purged immediately after the test. |
| Dump | No PDO. Verifies the current working directory is writable and prints `Dump connection "<name>" — dialect: <dialect>, target: <cwd>, encryption: AES-256\|none`. Exits with code `5` (`IoError`) if the directory is not writable. |

### Transport security

With `-v`, a successful MySQL/MariaDB/PostgreSQL test also prints the negotiated cipher (`TLS cipher: TLS_AES_256_GCM_SHA384`). SQL Server does not expose the cipher over T-SQL, only whether the session is encrypted, so a successful encrypted SQL Server test instead prints `TLS cipher: encrypted (cipher not reported by SQL Server)`; the line is omitted when the session is not encrypted, exactly as for the other drivers. This is the only query sent beyond the handshake.

TLS failures include a hint, for example:

```
staging: FAILED — SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.
The server requires TLS. Run "clonio connection:update staging" and set transport security to "require" or "verify".
```

### Password decryption

Passwords stored with the `encrypted:` prefix are decrypted using the application `APP_KEY` before the connection attempt. If decryption fails (e.g. `APP_KEY` is missing or incorrect), the command exits with code `2` without attempting a network connection.

## Options

| Option | Description |
|--------|-------------|
| `--ci` | Suppress the results table and non-error output. Errors are still written to stderr. The summary line is always printed. |

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | All tested connections succeeded |
| `2` | Configuration error: named connection not found, no connections defined, or `APP_KEY` required for decryption is missing/invalid |
| `3` | One or more connections failed to connect |

## CI Integration

Use `--ci` in automated pipelines to keep output clean. Only failures are written to stderr; the summary line goes to stdout. A non-zero exit code signals failure to the pipeline:

```yaml
# GitHub Actions example
- name: Test database connections
  run: clonio connection:test --ci
```

```bash
# Shell script example
if ! clonio connection:test --ci; then
  echo "One or more connections are unavailable" >&2
  exit 1
fi
```
