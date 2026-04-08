# `connection:list` Command

Lists all database connections configured in the `clonio.json` configuration file.

## Usage

```bash
clonio connection:list
```

## Behaviour

1. If no connections are configured, a message is printed suggesting `connection:add` and the command exits successfully.
2. If connections exist, they are displayed in a table with the following columns:

```
 ────────────┬────────────┬──────────────────┬────────────┬────────────
  Name         Driver       Host               Database     Production
 ────────────┼────────────┼──────────────────┼────────────┼────────────
  local        sqlite       —                  local.db     No
  staging      mysql        staging.db:3306    myapp        No
  prod         pgsql        db.example.com     myapp        Yes
 ────────────┴────────────┴──────────────────┴────────────┴────────────
```

### Columns

| Column | Description |
|--------|-------------|
| **Name** | The connection name as defined in `clonio.json` |
| **Driver** | Database driver (`sqlite`, `mysql`, `mariadb`, `pgsql`, `sqlsrv`) |
| **Host** | Host and port (e.g. `db.example.com:3306`). Shown as `—` for SQLite connections |
| **Database** | Database name or file path. Shown as `—` if not set |
| **Production** | `Yes` if the connection is marked as production, `No` otherwise |

## Options

This command has no options or arguments.

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Always — the command succeeds even when no connections are configured |

## Notes

- Passwords and other sensitive fields are never displayed. Use `connection:test` to verify that credentials are correct.
- Production connections are flagged in the table to help prevent accidental operations against live databases.
