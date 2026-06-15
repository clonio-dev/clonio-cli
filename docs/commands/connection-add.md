# `connection:add` Command

Adds a new database connection to the `clonio.json` configuration file in the current directory.

## Usage

```bash
clonio connection:add [<name>] [options]
```

All arguments and options are optional. Any value not supplied via a flag will be collected interactively.

## Interactive flow

When run without flags the command walks through each field in order:

1. **Name** — A unique identifier for the connection. Must match `[a-z0-9_-]+`.
2. **Driver** — Selected from a list: `mysql`, `mariadb`, `pgsql`, `sqlsrv`, `sqlite`, `dump`.
3. **Host** — Hostname or IP address of the database server (default: `localhost`). Skipped for SQLite and dump.
4. **Port** — TCP port (default taken from the driver). Skipped for SQLite.
5. **Database** — Database name, or file path for SQLite.
6. **Schema** — Schema name (default: `public`). PostgreSQL only.
7. **Username** — Database user. Skipped for SQLite.
8. **Password** — Entered via a masked prompt. Skipped for SQLite.
9. **Production** — Prompted only when `--production` is not passed. Defaults to `no`.

A summary table is displayed before writing. The operation can be cancelled at the final confirmation prompt.

## Options

| Option | Description |
|---|---|
| `name` | Connection name (argument, optional) |
| `--type=` | Driver type: `mysql`, `mariadb`, `pgsql`, `sqlsrv`, `sqlite`, `dump` |
| `--dialect=` | Target SQL dialect for `dump` connections: `mysql`, `mariadb`, `pgsql`, `sqlsrv`, `sqlite` |
| `--host=` | Database host (default: `localhost`) |
| `--port=` | Database port (1–65535) |
| `--database=` | Database name or file path |
| `--schema=` | Schema name (PostgreSQL only, default: `public`) |
| `--username=` | Database username |
| `--password=` | Database password (stored encrypted) |
| `--production` | Mark the connection as a production environment |

## Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Connection added successfully, or save was cancelled by the user |
| `2` | `ConfigError` | Password encryption failed — `APP_KEY` is missing or invalid |
| `4` | `ValidationError` | Invalid connection name, duplicate name, out-of-range port, or unknown driver |
| `5` | `IoError` | Could not write to `clonio.json` |

## Notes

### Password encryption

Passwords are never stored in plain text. Before writing, each password is encrypted with Laravel's `Crypt` facade:

```
encrypted:<base64-encoded-ciphertext>
```

This requires `APP_KEY` to be set in the environment (`.env` or exported). If the key is absent, the command exits with code `2`.

### clonio.json location

The file is read from and written to the **current working directory**. Run the command from the root of the project whose connections you want to manage.

### SQLite differences

For SQLite connections, host, port, username, and password fields are skipped entirely — only the database file path is required. The password is stored as an empty string and no encryption is attempted.

### Dump connections

A `dump` connection is a virtual SQL-file output target, not a live database. The host, port, database, and username steps are skipped; instead you choose a target SQL `dialect` and an optional ZIP archive password (encrypted at rest like any other secret; blank means no encryption). See [`dump` Connection Type](connection-dump.md) for the full reference.

### Production warning

If a connection is marked as production, a warning box is displayed after the production prompt. Production connections may trigger additional confirmation prompts in destructive operations elsewhere in the CLI.
