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
8. **Password** — Clonio asks how to get the password. The default is **Read it from an environment variable (recommended)**: you enter a variable name (default `CLONIO_<NAME>_PASSWORD`) and only the reference `env:CLONIO_<NAME>_PASSWORD` is stored. The other choice, **Enter it now and store it encrypted in clonio.json**, asks for the password with a masked prompt. Skipped for SQLite.
9. **Transport security** — Network drivers only. `Require (encrypted, not verified)`, `Verify (encrypted + certificate check)`, `Disable (plaintext)` or `Driver default`. Default is `Require`, except SQL Server which defaults to `Verify` (ODBC Driver 18 already verifies by default; `require` would weaken it). With `Verify`, a CA certificate path is asked (not for SQL Server). With `Require`/`Verify`, you can add a client certificate and key for mutual TLS.
10. **Production** — Prompted only when `--production` is not passed. Defaults to `no`.

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
| `--password=` | Database password: `env:NAME` stores a reference to an environment variable; any other value is stored encrypted (prints a shell-history warning) |
| `--ssl-mode=` | Transport security: `disable`, `require`, `verify` (network drivers; default `require`, `verify` for `sqlsrv`) |
| `--ssl-ca=` | CA certificate (PEM). Only with `--ssl-mode=verify`; required for MySQL/MariaDB verify |
| `--ssl-cert=` | Client certificate (PEM) for mutual TLS; requires `--ssl-key` |
| `--ssl-key=` | Client private key (PEM) for mutual TLS; requires `--ssl-cert` |
| `--trust-server-certificate` | Deprecated alias for `--ssl-mode=require` (SQL Server) |
| `--production` | Mark the connection as a production environment |

## Exit codes

| Code | Constant | Meaning |
|---|---|---|
| `0` | `Success` | Connection added successfully, or save was cancelled by the user |
| `2` | `ConfigError` | Password encryption failed — `APP_KEY` is missing or invalid |
| `4` | `ValidationError` | Invalid `env:` reference, invalid connection name, duplicate name, out-of-range port, unknown driver, invalid ssl mode or certificate options, certificate file not found |
| `5` | `IoError` | Could not write to `clonio.json` |

## Notes

### Passwords: environment references (default) or encrypted

A stored password has one of two forms:

| Stored value | Meaning |
|---|---|
| `env:CLONIO_PROD_PASSWORD` | Clonio reads the password from the environment variable `CLONIO_PROD_PASSWORD` each time it connects. The password is never written to disk. **This is the default.** |
| `encrypted:<payload>` | The password is encrypted with `APP_KEY` (Laravel `Crypt`) and stored in `clonio.json`. |

Environment references are the recommended form. An encrypted password is only as safe as `APP_KEY`, and `APP_KEY` usually sits in the `.env` file next to `clonio.json`: anyone who can read both files can decrypt the password. See [Security and deployment](../security.md).

- Variable names must match `[A-Za-z_][A-Za-z0-9_]*`. At the prompt, a leading `$` or `env:` is removed.
- If the variable is not set yet, Clonio warns but still saves the reference. `connection:test` and `cloning:run` fail with `environment variable … is not set` until you set it.
- Without interaction and without `--password`, a network connection stores `env:CLONIO_<NAME>_PASSWORD`.
- `--password=env:NAME` stores the reference without a warning. Any other `--password` value is encrypted and Clonio warns that it may end up in your shell history.
- Encrypting requires `APP_KEY` (`.env` or exported). If the key is absent, the command exits with code `2`.

```bash
export CLONIO_PROD_PASSWORD='…'   # e.g. from your CI secret store
clonio connection:add prod --type=mysql --host=db.example.com --port=3306 \
  --database=app --username=clonio_ro --password=env:CLONIO_PROD_PASSWORD \
  --production --no-interaction
```

### clonio.json location

The file is read from and written to the **current working directory**. Run the command from the root of the project whose connections you want to manage.

### SQLite differences

For SQLite connections, host, port, username, and password fields are skipped entirely — only the database file path is required. The password is stored as an empty string and no encryption is attempted.

### Dump connections

A `dump` connection is a virtual SQL-file output target, not a live database. The host, port, database, and username steps are skipped; instead you choose a target SQL `dialect` and an optional ZIP archive password (default: no ZIP encryption; otherwise an `env:` reference or an encrypted value, like any other secret). See [`dump` Connection Type](connection-dump.md) for the full reference.

### SQL Server schemas

A `sqlsrv` connection reads and writes only the login's default schema (`SCHEMA_NAME()`, usually `dbo`). Tables in other schemas are ignored, even when a table with the same name exists there. To clone another schema, connect with a login whose default schema is that schema. The `--schema` option has no effect on SQL Server.

### Transport security (TLS)

New network connections use `require` by default: the connection is encrypted, the server certificate is not verified. This is what servers with `require_secure_transport=ON` need. SQL Server (`sqlsrv`) defaults to `verify` instead, because ODBC Driver 18 already verifies the server certificate by default — `require` would weaken that.

| Mode | Encrypted | Certificate verified |
|---|---|---|
| `disable` | No | — |
| `require` | Yes | No |
| `verify` | Yes | CA **and** host name |

Certificate paths are stored as entered. `~/` resolves to your home directory; relative paths resolve against the current working directory (next to `clonio.json`). Only paths are stored, never certificate contents.

Servers without TLS (for example the stock `postgres` Docker image) need `--ssl-mode=disable`.

```bash
clonio connection:add prod --type=mysql --host=db.example.com --port=3306 \
  --database=app --username=clonio --password=env:CLONIO_PROD_PASSWORD \
  --ssl-mode=verify --ssl-ca=certs/prod-ca.pem --production --no-interaction
```

### Production warning

If a connection is marked as production, a warning box is displayed after the production prompt. Production connections may trigger additional confirmation prompts in destructive operations elsewhere in the CLI.
