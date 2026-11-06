# Security and deployment

Clonio reads production data. Where it runs, which credentials it holds and what its configuration says decide how much damage a compromised machine can do. This page describes what Clonio protects, what it does not, and how to deploy it.

## What Clonio protects, and what it does not

### Stored secrets

`clonio.json` holds database passwords and audit channel secrets (S3 keys, SMTP passwords, webhook URLs, ntfy tokens). Each one is stored in one of two forms:

| Stored value | Where the secret lives |
|---|---|
| `env:NAME` | In the environment variable `NAME`, read when Clonio needs it. The secret is never written to disk. **Default and recommended.** |
| `encrypted:<payload>` | In `clonio.json`, encrypted with `APP_KEY`. |

Encryption with `APP_KEY` protects a `clonio.json` that leaks **on its own**, for example when it is committed by mistake. It does **not** protect against someone who can read the project directory: `APP_KEY` usually sits in `.env` next to `clonio.json`, and the two files together reveal every encrypted secret. Any process, backup or container image that holds both files holds the plain-text credentials.

With `env:` references, `clonio.json` and `.env` contain no credentials at all. The secrets exist only in the environment of the process that runs Clonio, and only for as long as it runs.

`connection:add`, `connection:update`, `audit:add` and `audit:update` ask for an environment variable first. Use the encrypted form only on a single-user machine, for secrets that are worth no more than the machine itself.

### `APP_KEY` also signs audit logs

Clonio signs each audit log with an HMAC derived from `APP_KEY`. Anyone who has `APP_KEY` can create an audit log that `cloning:verify-audit` accepts. Treat `APP_KEY` as a secret even when no encrypted values are stored: keep it in your CI secret store, not in a `.env` file on a shared machine.

### Anonymization is only as complete as your configuration

Clonio transforms exactly the columns your `.cloning.yaml` names. Everything else is copied unchanged. Typical gaps:

- **Free-text columns** — comments, notes, support tickets, chat messages and addresses entered as text often contain names, e-mail addresses and phone numbers.
- **Quasi-identifiers** — birth date, postcode and gender together identify most people, even when the name is replaced.
- **Structured blobs** — JSON, XML or serialized columns, and audit or event tables that copy row contents.
- **Files and paths** — upload paths, avatar URLs and file names that include a person's name.
- **New columns** — a migration adds a column that the configuration does not cover yet.

Review the configuration when the schema changes, and check samples of the target data. Only then is the target environment free of personal data.

## Deployment scenarios

The rule: **real data exists only in production and in the short-lived CI job that anonymizes it.** Every environment after that receives anonymized data only.

```
production (replica, read-only user)
        │
        │  CI job on an ephemeral runner: clonio cloning:run
        ▼
acceptance (anonymized)
        │
        │  clonio in CI, in a container or on a developer machine
        ▼
test / dev (anonymized)
```

### Production → acceptance: in CI only

Run the transfer that reads production as a single CI step:

- **Ephemeral runner.** Use a runner that is created for the job and destroyed afterwards (hosted runners, or self-hosted runners in ephemeral mode). Nothing from the job survives it.
- **No Clonio in running environments.** Do not install Clonio, `clonio.json` or a `.env` with `APP_KEY` in the production or acceptance containers. An attacker who gets into a running container must not find credentials for the other environment there.
- **Secrets from the CI secret store.** Provide the database passwords and `APP_KEY` as CI secrets. `clonio.json` refers to them with `env:` references.
- **Read-only user on a replica.** Read from a replica, not from the primary, with a user that may only `SELECT` (see [Database permissions](#database-permissions)). A leaked credential then cannot change or delete production data.
- **Restricted network access.** Allow the replica to accept connections only from the CI runners.
- **Production flag.** Mark the source with `--production`, so `cloning:run` never offers it as a target.
- **External audit channel.** Deliver the audit log to a channel outside the runner (S3, e-mail, a webhook), because the runner's disk is gone after the job.

Example with GitHub Actions:

```yaml
jobs:
  anonymize-to-acceptance:
    runs-on: ubuntu-latest        # hosted runners are ephemeral
    steps:
      - uses: actions/checkout@v5  # contains production.cloning.yaml, no clonio.json

      - name: Configure connections (references only, no secrets on disk)
        run: |
          clonio connection:add production --type=mysql --host=replica.db.internal --port=3306 \
            --database=app --username=clonio_ro --password=env:CLONIO_PRODUCTION_PASSWORD \
            --ssl-mode=verify --ssl-ca=certs/prod-ca.pem --production --no-interaction
          clonio connection:add acceptance --type=mysql --host=acceptance.db.internal --port=3306 \
            --database=app --username=clonio --password=env:CLONIO_ACCEPTANCE_PASSWORD \
            --no-interaction
          clonio audit:add archive --type=s3 --s3-endpoint=https://s3.eu-central-1.amazonaws.com \
            --s3-bucket=clonio-audit --s3-region=eu-central-1 --s3-access-key="$CLONIO_AUDIT_ACCESS_KEY" \
            --s3-secret-key=env:CLONIO_AUDIT_SECRET_KEY --enable --no-interaction
        env:
          CLONIO_AUDIT_ACCESS_KEY: ${{ vars.CLONIO_AUDIT_ACCESS_KEY }}

      - name: Anonymize production into acceptance
        run: clonio cloning:run production.cloning.yaml --target acceptance --ci
        env:
          APP_KEY: ${{ secrets.CLONIO_APP_KEY }}
          CLONIO_PRODUCTION_PASSWORD: ${{ secrets.CLONIO_PRODUCTION_PASSWORD }}
          CLONIO_ACCEPTANCE_PASSWORD: ${{ secrets.CLONIO_ACCEPTANCE_PASSWORD }}
          CLONIO_AUDIT_SECRET_KEY: ${{ secrets.CLONIO_AUDIT_SECRET_KEY }}
```

Pass the secrets only to the step that needs them. The step that configures the connections needs none, because it stores references only. The S3 access key ID is not a secret field: Clonio stores it as written, so pass the value itself.

If you prefer to commit `clonio.json`, make sure it contains only `env:` references. It still names hosts and user names, so keep the repository private.

### Acceptance → test and dev

Test and dev environments receive data from acceptance, never from production. The data there is already anonymized, so a compromised test system or developer machine cannot expose personal data — provided the anonymization of the production transfer is complete (see above).

Here Clonio may also run inside a container, on a long-lived CI runner or on a developer machine. `env:` references are still the better choice, so that credentials for acceptance do not end up in a file.

Do not give test or dev environments network access to production, and do not store production credentials there.

## Database permissions

Every source connection — production above all — should only be able to read: `SELECT` and nothing else. Clonio never writes to the source. Besides the table data, it reads the schema (tables, columns, primary and foreign keys) and row estimates from the database's catalog; these catalog queries need no extra privileges on any supported database: each database shows a user the metadata of the tables it may read.

### Source (read only)

| Database | Grant | What Clonio reads with it |
|---|---|---|
| MySQL / MariaDB | `SELECT` on the database | Table data; `information_schema.TABLES`, `COLUMNS`, `KEY_COLUMN_USAGE`; `SHOW CREATE TABLE` (when source and target are MySQL/MariaDB); `SHOW SESSION STATUS` for the TLS cipher |
| PostgreSQL | `CONNECT` on the database, `USAGE` on the schema, `SELECT` on its tables | Table data; `information_schema.columns`; `pg_class`, `pg_namespace`, `pg_constraint`, `pg_attribute` for tables and keys; `pg_stat_ssl` for the TLS cipher |
| SQL Server | `SELECT` on the schema (the login's default schema, see [`connection:add`](commands/connection-add.md#sql-server-schemas)) | Table data; `INFORMATION_SCHEMA` views; `sys.foreign_keys`, `sys.foreign_key_columns` for foreign keys; `sys.partitions`. `VIEW SERVER STATE` is optional: without it, `connection:test` does not show whether the session is encrypted |
| SQLite | Read access to the database file | Table data; `sqlite_master`, `PRAGMA table_xinfo`, `PRAGMA foreign_key_list` |

Clonio only clones base tables, so no privilege on views (`SHOW VIEW`) is needed.

MySQL / MariaDB:

```sql
CREATE USER 'clonio_ro'@'10.0.%' IDENTIFIED BY '…';
GRANT SELECT ON app.* TO 'clonio_ro'@'10.0.%';
```

PostgreSQL:

```sql
CREATE ROLE clonio_ro LOGIN PASSWORD '…';
GRANT CONNECT ON DATABASE app TO clonio_ro;
GRANT USAGE ON SCHEMA public TO clonio_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO clonio_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO clonio_ro;
```

SQL Server:

```sql
CREATE LOGIN clonio_ro WITH PASSWORD = '…';
CREATE USER clonio_ro FOR LOGIN clonio_ro WITH DEFAULT_SCHEMA = dbo;
GRANT SELECT ON SCHEMA::dbo TO clonio_ro;
```

Restrict where the user may connect from to the addresses of your CI runners: the host part (MySQL), `pg_hba.conf` (PostgreSQL) or a firewall rule (SQL Server). On a read replica, the database itself rejects writes as well.

### Target (acceptance, test, dev)

The target user creates, empties and fills tables. It needs:

| Database | Needed for |
|---|---|
| MySQL / MariaDB | `SELECT`, `INSERT`, `DELETE`, `CREATE`, `ALTER`, `DROP` (`TRUNCATE` needs `DROP`) on the target database |
| PostgreSQL | `CREATE` and `USAGE` on the schema; ownership of the tables Clonio drops, truncates or alters. Turning off foreign-key checks while loading (`SET session_replication_role`) needs a superuser, or on PostgreSQL 15+ `GRANT SET ON PARAMETER session_replication_role`; without it, Clonio loads with the checks on |
| SQL Server | `CREATE TABLE`, and `ALTER`, `SELECT`, `INSERT`, `DELETE` on the schema (dropping, truncating and removing foreign keys need `ALTER`) |
| SQLite | Write access to the database file and its directory |

Never use the target user for a source connection, and never give the source user any of these privileges.

## Checklist

- [ ] All secrets in `clonio.json` are `env:` references.
- [ ] `APP_KEY` is a CI secret, not a file on a shared machine.
- [ ] Every source connection uses a user with `SELECT` only; production is read from a replica, only from CI runners.
- [ ] The transfer from production runs as one CI step on an ephemeral runner.
- [ ] No running container holds Clonio, `clonio.json` or `APP_KEY`.
- [ ] Test and dev are fed from acceptance only.
- [ ] The anonymization configuration covers free-text columns, quasi-identifiers and blobs, and is reviewed when the schema changes.
- [ ] Audit logs go to a channel outside the runner.
- [ ] Local files that must exist are readable only by their owner: `chmod 600 clonio.json .env`.
