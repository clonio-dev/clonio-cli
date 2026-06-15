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
staging: OK (42ms)
```

If the connection fails:

```
staging: FAILED — Connection refused
```

### All connections

When no name is given, every connection in `clonio.json` is tested and the results are displayed in a table:

```
 ────────────┬────────────┬────────┬────────
  Connection   Driver       Status   Time
 ────────────┼────────────┼────────┼────────
  local        SQLite       OK       1ms
  staging      MySQL        OK       38ms
  prod         PostgreSQL   OK       55ms
 ────────────┴────────────┴────────┴────────

All 3 connections OK.
```

A summary line is always printed regardless of `--ci` mode.

### What "tested" means

| Driver | Method |
|--------|--------|
| SQLite | Checks that the database file exists, is readable, and is writable. No network connection is attempted. |
| MySQL, MariaDB, PostgreSQL, SQL Server | Opens a real TCP connection using `PDO` and calls `getPdo()`. The connection is purged immediately after the test. |
| Dump | No PDO. Verifies the current working directory is writable and prints `Dump connection "<name>" — dialect: <dialect>, target: <cwd>, encryption: AES-256\|none`. Exits with code `5` (`IoError`) if the directory is not writable. |

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
