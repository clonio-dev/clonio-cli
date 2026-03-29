# PRD — `connection:test` Command

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Verify that Clonio can successfully open a TCP connection and authenticate with a configured database. Usable both interactively by developers and automatically in CI/CD pipelines.

---

## 2. Command Signature

```
connection:test
    {name?           : Name of the connection to test (tests all if omitted)}
```

`--ci` and `--verbose` / `-v` are global flags available on all commands — see **PRD-command-behaviour.md**.

---

## 3. Behaviour

### 3.1 Single connection

```
$ clonio connection:test local-dev

  Testing "local-dev" (mysql @ 127.0.0.1:3306) ...  ✓  Connected (42 ms)
```

### 3.2 All connections (no name given)

Each connection is tested sequentially and results are shown in a table:

```
  Connection      Type     Host              Result
  ─────────────────────────────────────────────────────────────
  local-dev       mysql    127.0.0.1:3306    ✓ Connected (38 ms)
  staging         pgsql    db.staging.io     ✓ Connected (91 ms)
  prod            pgsql    db.prod.io        ✗ Failed — Connection refused
  local-sqlite    sqlite   /path/to/db       ✓ Connected (2 ms)

  3/4 connections OK
```

The command exits with code `3` (`ConnectionError`) if any connection failed — see **PRD-command-behaviour.md §6**.

### 3.3 SQLite

For `sqlite`, "test" means: check that the file exists and is readable (and writable if not a read-only database). No network connection is attempted.

---

## 4. Test Mechanism

The command uses Laravel's database layer (`DB::connection($name)->getPdo()`) to establish a real connection. This proves:

- Network reachability (host + port)
- Valid credentials (username + password)
- Database / schema existence

No queries are executed beyond the PDO handshake. The connection is closed immediately after the test.

---

## 5. CI Integration

### 5.1 CI Behaviour

CI mode (`--ci`) is defined globally in **PRD-command-behaviour.md**. For `connection:test` specifically:

- Suppresses the result table; only errors are written to stderr
- Exits with code `3` if any connection fails

### 5.2 GitHub Actions Usage

A new optional job `connection-test` can be added to `tests.yml` or as a standalone `connection-test.yml` workflow. Because it requires a live database, it uses **GitHub Actions service containers**.

```yaml
# .github/workflows/connection-test.yml (example skeleton — exact config in the SPEC)
services:
  mysql:
    image: mysql:8.0
    env:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: clonio_test
    ports: ["3306:3306"]
    options: >-
      --health-cmd="mysqladmin ping"
      --health-interval=10s
      --health-timeout=5s
      --health-retries=5

  postgres:
    image: postgres:16
    env:
      POSTGRES_PASSWORD: secret
      POSTGRES_DB: clonio_test
    ports: ["5432:5432"]
    options: >-
      --health-cmd pg_isready
      --health-interval 10s
      --health-timeout 5s
      --health-retries 5
```

A `clonio.json` fixture is written (or injected via env) before the test step, pointing at the service containers. The `connection:test --ci` command then verifies all entries.

### 5.3 Test Matrix

| Driver    | CI tested | Service container   |
|-----------|:---------:|---------------------|
| `mysql`   | Yes       | `mysql:8.0`         |
| `mariadb` | Yes       | `mariadb:11`        |
| `pgsql`   | Yes       | `postgres:16`       |
| `sqlsrv`  | Desired   | `mcr.microsoft.com/mssql/server:2022-latest` — heavy image, optional |
| `sqlite`  | Yes       | None (file-based)   |

---

## 6. Error Cases

| Situation                          | Behaviour                                                    |
|------------------------------------|--------------------------------------------------------------|
| `name` does not exist in config    | Exit with error                                              |
| `clonio.json` missing              | Exit with error; suggest `connection:add`                    |
| `APP_KEY` missing (cannot decrypt) | Exit with error; suggest `clonio init`                       |
| Connection timeout                 | Show "Timed out after Xs" in result column; continue with next |
| Wrong credentials                  | Show driver error message (sanitised — no password in output)|
| SQLite file not found              | Show "File not found: /path/to/db"                           |

---

## 7. Out of Scope

- Running test queries (SELECT 1, etc.) beyond the PDO handshake
- Measuring sustained throughput or latency
- Testing connections that are not in `clonio.json` (ad-hoc DSN input)
