# `dump` Connection Type

A **dump** connection is a virtual output target. Used as the `--target` of `cloning:run`, it produces a dialect-correct SQL file compressed into a (optionally AES-256 encrypted) ZIP archive — **no live target database required**.

This makes Clonio usable in air-gapped or multi-stage environments where source and target are never directly connected: the source is anonymized as usual, but the result is written to a portable file you transport and import on the target system at any time.

> **Not to be confused with `cloning:dump`**, a separate command that inspects a database and generates a `.cloning.yaml` config. The `dump` *connection type* documented here is a transfer **target**.

## Connection fields

A dump connection is stored in `clonio.json` next to regular database connections and identified by `"type": "dump"`. It needs no host, port, database, or username.

| Field | Required | Description |
|---|:---:|---|
| `type` | yes | Must be `dump` |
| `dialect` | yes | Target DBMS SQL dialect — one of `mysql`, `mariadb`, `pgsql`, `sqlsrv`, `sqlite` |
| `password` | no | ZIP archive encryption password, stored encrypted (`encrypted:…`). Omit for an unencrypted archive. |

### Example `clonio.json` entry

```json
"staging-dump": {
  "type": "dump",
  "dialect": "pgsql",
  "password": "encrypted:eyJpdiI6..."
}
```

## Adding a dump connection

`connection:add` offers `Dump (SQL file)` in the driver list. The interactive flow is shortened — host, port, database, and username are skipped:

1. **Name** — unique identifier (e.g. `staging-dump`)
2. **Driver** — choose `dump`
3. **Dialect** — target DBMS: `mysql` / `mariadb` / `pgsql` / `sqlsrv` / `sqlite`
4. **ZIP password** (optional) — masked input; leave blank for no encryption

Non-interactive:

```bash
clonio connection:add staging-dump \
  --type=dump \
  --dialect=pgsql \
  --password="$ZIP_PASSWORD"
```

Omit `--password` to produce an unencrypted archive. The `--dialect` flag is required for dump connections; the network flags (`--host`, `--port`, `--database`, `--username`) are ignored.

## Testing a dump connection

`connection:test` skips the PDO ping for dump connections. Instead it verifies the current working directory is writable and prints:

```
Dump connection "staging-dump" — dialect: pgsql, target: /path/to/cwd, encryption: AES-256
```

`encryption` shows `AES-256` when a password is set, `none` otherwise. Exit code `5` (`IoError`) if the working directory is not writable.

## Running a dump

Use the dump connection as the `--target`:

```bash
clonio cloning:run production-db.cloning.yaml --target staging-dump
```

The source connection is still opened normally and all anonymization, fake data, and key remapping run as usual. The target phases are routed to the SQL dump writer instead of a live PDO:

| Phase | Live target | Dump target |
|---|---|---|
| Connection checks | PDO ping to target | Validate `dialect`, verify `cwd` writable; no PDO ping |
| Schema replication | DDL via PDO | Write DDL to the `.sql` file |
| Data transfer | Bulk INSERT via PDO | Write INSERT batches to the `.sql` file |
| Summary | Row counts | Row counts **plus** archive path and compressed size |

After the data transfer the `.sql` file is finalised, compressed to `.zip` (AES-256 when a password is set), and the intermediate `.sql` is deleted.

The source connection **must** be a real database. Using a dump connection as the source fails with `ValidationError` (exit code `4`).

### Output files

| Artefact | Pattern |
|---|---|
| SQL file (intermediate, deleted) | `<source-db>_<YYYYMMDD>_<HHmmss>.sql` |
| ZIP archive (final) | `<source-db>_<YYYYMMDD>_<HHmmss>.zip` |
| Location | Current working directory |

Run summary excerpt:

```
  Tables: 24/24  Rows: 18432  Duration: 4.1s

  Dump: app_20260615_143022.zip  (2.3 MB, AES-256)
```

## How the SQL is generated

### DDL strategy

The `options.drop_unknown_tables` field in the cloning YAML drives the DDL:

| `drop_unknown_tables` | DDL emitted |
|:---:|---|
| `true` | `DROP TABLE IF EXISTS` + `CREATE TABLE` (target treated as disposable) |
| `false` | `CREATE TABLE IF NOT EXISTS` (only add missing structures) |

`options.disable_foreign_key_checks` controls the per-dialect FK preamble/postamble (e.g. `SET FOREIGN_KEY_CHECKS = 0` for MySQL, `SET session_replication_role = 'replica'` for PostgreSQL). When `false`, the FK wrapper is omitted.

### DML batching

INSERT batches use `options.chunk_size` from the cloning YAML — each chunk read from the source is written as one multi-row `INSERT`. Per-dialect INSERT syntax is emitted (e.g. `INSERT OR REPLACE` for SQLite, `N'…'` Unicode literals for SQL Server). Binary/BLOB columns are always hex-encoded in dialect notation (`0x…`, `E'\\x…'`, `X'…'`); `NULL` stays `NULL`.

### Cross-dialect type mapping

When the source DBMS differs from the dump dialect (e.g. MySQL source → PostgreSQL dump), column types are mapped conservatively to the widest compatible target type. Source length/precision is preserved where possible (`VARCHAR(512)`, `DECIMAL(10,2)`), downgrading safely (e.g. MySQL → `LONGTEXT`, SQL Server → `NVARCHAR(MAX)`) above a dialect's limit to avoid truncation. Unknown types fall back to `TEXT` / `NVARCHAR(MAX)`.

### v1 portability notes

To keep the generated dump unconditionally importable across all five dialects, the shipped output deliberately omits:

- **`AUTO_INCREMENT` / `SERIAL` / `IDENTITY`** — primary keys are plain columns; the pipeline always inserts explicit key values (no SQL Server `SET IDENTITY_INSERT` wrapper needed).
- **`DEFAULT` clauses** — every value is copied explicitly from the source.
- **Foreign-key constraints in DDL** — data is written in dependency order with FK checks disabled.

### SQL Server schema prefix

The `[schema].[table]` prefix in SQL Server output derives from the **source** connection: the source's `schema` field if the source is `sqlsrv`, otherwise `dbo`. Adjust the dump file if your target SQL Server uses a different schema.

## Audit log

Dump runs extend the standard audit record with: the dump dialect, the absolute archive path, the archive's SHA-256 hash, and whether it was encrypted.

## Error cases

| Situation | Exit code |
|---|---|
| Unknown `dialect` value | `4` ValidationError |
| Working directory not writable / disk full | `5` IoError |
| ZIP library / AES-256 unavailable | `1` GeneralError |
| Source connection is also type `dump` | `4` ValidationError |

## Out of scope (v1)

- Importing/applying a dump file (a `cloning:apply` command) — separate feature
- Incremental/diff dumps — v1 always writes a full dump of the selected rows
- One file per table — single-file only
- Plain-text (uncompressed) output — always ZIP

## Related commands

- `connection:add` — add a dump connection
- `connection:test` — verify the working directory is writable
- `cloning:run` — run a transfer with a dump connection as `--target`
- `cloning:dump` — (different) generate a `.cloning.yaml` from a live database
