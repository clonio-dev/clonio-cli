# PRD — `connection:add` Command

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Allow users to register a new named database connection in their project by running a single command. All parameters can be supplied as options; missing parameters are prompted interactively in a fixed order. Credentials are stored encrypted in a local JSON file.

---

## 2. Background

Clonio operates on database connections defined per project. Before any transfer or anonymization can happen, at least one connection must be registered. The `connection:add` command is the primary entry point for this setup.

---

## 3. Command Signature

```
connection:add
    {name?           : Unique name / alias for this connection}
    {--type=         : Driver — mysql|mariadb|pgsql|sqlsrv|sqlite}
    {--host=         : Hostname or IP address}
    {--port=         : TCP port (defaults depend on driver)}
    {--database=     : Database / schema name}
    {--schema=       : PostgreSQL search_path (pgsql only, default: public)}
    {--username=     : Database user}
    {--password=     : Database password (stored encrypted)}
    {--production    : Flag — marks this connection as a production database}
```

---

## 4. Supported Drivers

| Driver key | Display name   | Default port | Requires host | Requires schema option |
|------------|----------------|:------------:|:-------------:|:---------------------:|
| `mysql`    | MySQL          | 3306         | Yes           | No                    |
| `mariadb`  | MariaDB        | 3306         | Yes           | No                    |
| `pgsql`    | PostgreSQL     | 5432         | Yes           | Yes (default: public) |
| `sqlsrv`   | SQL Server     | 1433         | Yes           | No                    |
| `sqlite`   | SQLite         | —            | No            | No                    |

---

## 5. Interactive Flow

When a required parameter is not provided as an option, the command prompts for it. Prompts are shown in this fixed order:

1. **Name** — unique identifier for this connection within the project (e.g. `production-db`, `local-dev`)
2. **Type** — choice list: MySQL / MariaDB / PostgreSQL / SQL Server / SQLite
3. **Host** — skipped for `sqlite`; free-form text input
4. **Port** — skipped for `sqlite`; pre-filled with the driver default, user can override
5. **Database** — the database name (or file path for `sqlite`)
6. **Schema** — pgsql only; default `public`
7. **Username** — skipped for `sqlite`; free-form text input
8. **Password** — skipped for `sqlite`; masked input (not echoed to terminal); stored encrypted
9. **Is production?** — yes / no confirmation; defaults to `no`

If `--production` flag is passed, step 9 is skipped and the value is set to `true`.

---

## 6. Validation Rules

| Field      | Rule                                                                  |
|------------|-----------------------------------------------------------------------|
| `name`     | Required; unique within the config file; `[a-z0-9_-]+` pattern      |
| `type`     | Must be one of the five supported driver keys                         |
| `host`     | Required for non-sqlite; non-empty string                             |
| `port`     | Required for non-sqlite; integer, 1–65535                             |
| `database` | Required for all types; non-empty string                              |
| `schema`   | pgsql only; non-empty string; defaults to `public`                    |
| `username` | Required for non-sqlite; non-empty string                             |
| `password` | Required for non-sqlite; encrypted before storage                     |

If a `name` already exists in the config file, the command exits with an error and suggests `connection:edit` (future command).

---

## 7. Storage

### 7.1 Config File Location

The connections are stored in **`clonio.json`** in the **current working directory** (the user's project root, not the Clonio installation directory). The `filesystems.php` disk `local` already uses `getcwd()` as root, so this file can be read/written via `Storage::disk('local')`.

The full file structure and JSON Schema are defined in **PRD-clonio-json.md**.

### 7.2 Password Encryption

- Passwords are encrypted using Laravel's `Crypt::encryptString()` before writing to disk.
- The key is taken from `APP_KEY` resolved by `clonio init` (env var or `.env` file — see PRD-init.md).
- The encrypted value is stored with the prefix `encrypted:` for explicit identification.
- If `APP_KEY` is not set, the command exits with a clear error and instructs the user to run `clonio init`.

---

## 8. UX Details


- **Production warning:** If `is_production` is set to `true`, display a prominent warning box (e.g. yellow border) after saving, reminding the user that Clonio will treat read/write operations on this connection with extra care.
- **Success message:** After saving, display a summary table of the saved connection (password shown as `••••••••`).
- **Dry-run / preview:** No dry-run mode for this command — connections are always persisted immediately.
- **File creation:** If `clonio.json` does not exist yet, it is created automatically with the correct `$schema` reference.
- **File permissions:** `clonio.json` should be created with `0600` permissions (owner read/write only) to protect credentials at rest.

---

## 10. Error Cases

| Situation                            | Behaviour                                                              |
|--------------------------------------|------------------------------------------------------------------------|
| `name` already exists                | Exit with error; suggest `connection:edit <name>`                     |
| `APP_KEY` missing or empty           | Exit with error; instruct user to run `clonio init`                   |
| Invalid driver passed via `--type`   | Exit with error; list valid drivers                                    |
| Port out of range                    | Exit with error; re-prompt if interactive                              |
| `clonio.json` exists but is invalid JSON | Exit with error; suggest manual repair or show JSON parse error   |
| No write permission in cwd           | Exit with error; show path and permission hint                         |

---

## 11. Out of Scope (this command)

- Editing existing connections → `connection:edit` (future)
- Removing connections → `connection:remove` (future)
- Listing connections → `connection:list` (future)
- Testing connectivity → `connection:test` (future)
- Encrypting an already-plain-text password found in `clonio.json`
- Supporting multiple config files or a global config
- Import from `.env` / DSN strings

---

## 12. Open Questions

- [ ] Should `connection:update` be suggested on duplicate name, or `connection:edit`? — Align command naming before implementation.
- [ ] File permissions (`0600`) on `clonio.json` — enforceable on Windows? (See also PRD-clonio-json.md §2)

> Questions about `clonio.json` structure, schema versioning, and `.gitignore` handling are tracked in **PRD-clonio-json.md §8**.
