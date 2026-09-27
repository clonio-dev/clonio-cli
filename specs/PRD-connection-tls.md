# PRD — Secure Transport (TLS) for Connections

**Version:** 0.4
**Status:** Implemented — CI TLS matrix pending first run
**Date:** 2026-09-27
**Issue:** [#149 — Connections using insecure transport are prohibited](https://github.com/clonio-dev/clonio-cli/issues/149)
**Supersedes:** [PR #163](https://github.com/clonio-dev/clonio-cli/pull/163) (not merged — see §12)

---

## 1. Goal

Let every network connection (`mysql`, `mariadb`, `pgsql`, `sqlsrv`) declare how its transport is secured, so Clonio can connect to servers that reject unencrypted clients (e.g. MySQL with `require_secure_transport=ON`) and — optionally — verify the server's identity against a CA.

The feature is configured once per connection in `clonio.json`, set via `connection:add` / `connection:update`, and applied transparently wherever a connection is opened (`connection:test`, `cloning:run`, schema introspection, etc.).

---

## 2. Background

Today Clonio opens MySQL/MariaDB connections without TLS. A server with `require_secure_transport=ON` rejects them:

```
SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.
```

Current state per driver:

| Driver             | Current transport behaviour                                                         |
|--------------------|-------------------------------------------------------------------------------------|
| `mysql`/`mariadb`  | No TLS options passed → plaintext. Fails against `require_secure_transport=ON`.     |
| `pgsql`            | No `sslmode` passed → libpq default `prefer` (TLS if offered, no verification).     |
| `sqlsrv`           | ODBC driver default (`Encrypt=yes` on ODBC 18); only knob is `trust_server_certificate`. |
| `sqlite`, `dump`   | No network — not applicable.                                                        |

The issue asks for `Pdo\Mysql::ATTR_SSL_CA`. Passing only a CA solves one case (verified TLS with a private CA). It does not cover "encrypt, but I have no CA file" (common with managed databases and self-signed dev servers), and it leaves PostgreSQL and SQL Server with inconsistent, driver-specific knobs. This PRD defines one driver-agnostic model instead.

The companion web app (`clonio-dev/clonio`) currently maps a single `ssl` string to `ATTR_SSL_CA` (MySQL/MariaDB) and `sslmode` (PostgreSQL). The CLI model below is a superset; aligning the web app is out of scope (§11).

---

## 3. Concept: Transport Mode

Each network connection has an optional `ssl` object. Its `mode` is one of three values plus "unset":

| Mode       | Encrypted | Server certificate verified | Typical use                                                        |
|------------|:---------:|:---------------------------:|--------------------------------------------------------------------|
| *(unset)*  | driver default | driver default         | Backwards compatible — behaviour exactly as today                  |
| `disable`  | No        | —                           | Explicitly plaintext (local dev, trusted network)                  |
| `require`  | Yes       | No                          | Server enforces TLS; self-signed or unknown CA; fixes issue #149   |
| `verify`   | Yes       | Yes — CA **and** hostname   | Production over untrusted networks; private or public CA           |

Design decisions:

- **No `prefer` mode.** PDO MySQL cannot fall back from TLS to plaintext, and a silent downgrade is not a meaningful security setting. "Unset" already means "whatever the driver does by default".
- **No separate `verify-ca` / `verify-full`.** mysqlnd cannot verify the CA without also verifying the hostname, so the distinction cannot be offered consistently. `verify` always means both.
- **`require` never verifies** and accepts no CA file (§3.1). Verification is an explicit opt-in via `verify`, so the security level is readable from the mode alone.

### 3.1 Certificate files

| Field  | Meaning                                  | Allowed with mode    | Required with mode |
|--------|------------------------------------------|----------------------|--------------------|
| `ca`   | PEM file with the CA certificate(s) that signed the server certificate | `verify` only | `verify` on mysql/mariadb (see note) |
| `cert` | PEM client certificate (mutual TLS)      | `require`, `verify`  | —                  |
| `key`  | PEM private key for `cert`               | `require`, `verify`  | if `cert` is set   |

Note on `verify` without `ca`: allowed for `pgsql` and `sqlsrv`, which fall back to the system trust store. For `mysql`/`mariadb`, `ca` is required with `verify` (mysqlnd has no reliable system-store fallback across the SPC binaries).

`cert` and `key` must be set together or not at all.

`ca` is only meaningful with `verify`. With `require`, mysqlnd ignores the CA entirely (spike §5.1), while libpq silently upgrades `sslmode=require` to CA verification when a root certificate is present. Allowing `ca` with `require` would therefore mean different things per driver, so it is rejected.

### 3.2 Path resolution

The `local` disk is rooted at `getcwd()`, and PHAR/SPC binaries are read-only archives, so certificate paths must never resolve against the application's install location.

- Paths are stored in `clonio.json` **exactly as the user entered them**.
- At connect time a path is resolved as follows:
  1. Leading `~/` → the user's home directory (`$HOME`).
  2. Absolute path → used as-is.
  3. Relative path → resolved against the **current working directory** (the directory containing `clonio.json`), via `Storage::path()`.
- Clonio never uses `base_path()` for certificate paths.
- Clonio stores **paths only**, never certificate or key contents. `clonio.json` stays free of key material.

Relative paths are recommended for project-local certs (e.g. `certs/ca.pem`), since they keep `clonio.json` portable across machines and into the Docker image (where cwd is the mounted project directory).

---

## 4. `clonio.json` Format

```json
"connections": {
  "prod-mysql": {
    "type": "mysql",
    "host": "db.example.com",
    "port": 3306,
    "database": "app",
    "username": "clonio",
    "password": "encrypted:eyJpdiI6...",
    "is_production": true,
    "ssl": {
      "mode": "verify",
      "ca": "certs/prod-ca.pem"
    }
  },
  "staging-mysql": {
    "type": "mysql",
    "host": "staging.internal",
    "port": 3306,
    "database": "app",
    "username": "clonio",
    "password": "encrypted:eyJpdiI6...",
    "is_production": false,
    "ssl": { "mode": "require" }
  }
}
```

Rules:

- `ssl` is optional. Absent → mode unset (§3), no behaviour change for existing files.
- `ssl` is only valid on network types (`mysql`, `mariadb`, `pgsql`, `sqlsrv`). On `sqlite` or `dump` it is a validation error.
- `ssl.mode` is required when `ssl` is present.
- `ConnectionData::toArray()` omits `ssl` entirely when the mode is unset, and omits `ca`/`cert`/`key` when null.

### 4.1 JSON Schema (`resources/schema/clonio.schema.json`)

Add to the network-connection branch:

```json
"ssl": {
  "type": "object",
  "additionalProperties": false,
  "required": ["mode"],
  "description": "Transport security (TLS) for this connection.",
  "properties": {
    "mode": {
      "type": "string",
      "enum": ["disable", "require", "verify"],
      "description": "disable = plaintext; require = encrypted, certificate not verified; verify = encrypted, CA and hostname verified."
    },
    "ca":   { "type": "string", "description": "Path to the CA certificate (PEM). Relative paths resolve against the working directory." },
    "cert": { "type": "string", "description": "Path to the client certificate (PEM) for mutual TLS." },
    "key":  { "type": "string", "description": "Path to the client private key (PEM) for mutual TLS." }
  },
  "dependencies": { "cert": ["key"], "key": ["cert"] }
}
```

While touching the schema: the existing `trust_server_certificate` key written by the CLI is missing from the schema's network branch (which has `additionalProperties: false`). Add it in the same change.

---

## 5. Driver Mapping

`DatabaseConnectionService::buildConfig()` translates the mode into driver config. Resolved paths (§3.2) are used everywhere.

### 5.1 MySQL / MariaDB (`config['options']`, PHP 8.5 `Pdo\Mysql` constants)

| Mode      | PDO options                                                                                          |
|-----------|------------------------------------------------------------------------------------------------------|
| unset     | none (as today)                                                                                      |
| `disable` | none                                                                                                 |
| `require` | `Mysql::ATTR_SSL_CA => ''`, `Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false`; `ATTR_SSL_CERT`/`ATTR_SSL_KEY` if set |
| `verify`  | `Mysql::ATTR_SSL_CA => <ca>`, `Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true`; `ATTR_SSL_CERT`/`ATTR_SSL_KEY` if set |

**Spike results (2026-09-27, PHP 8.5.10 / mysqlnd, `mysql:8.4` and `mariadb:11`, both with `--require-secure-transport=ON` and a private test CA):**

| Options                                           | Result (identical on MySQL and MariaDB)            |
|---------------------------------------------------|----------------------------------------------------|
| none                                              | fail `[3159] … insecure transport are prohibited`  |
| `VERIFY_SERVER_CERT=false` only                   | fail `[3159]` — does **not** enable TLS            |
| `SSL_CA=''` + `VERIFY_SERVER_CERT=false`          | **OK**, TLS 1.3 (`TLS_AES_256_GCM_SHA384`)         |
| `SSL_CIPHER='DEFAULT'` + `VERIFY_SERVER_CERT=false` | OK, TLS 1.3                                      |
| `SSL_CIPHER='DEFAULT'` only                       | fail `[2002]` — mysqlnd verifies by default        |
| wrong CA + `VERIFY_SERVER_CERT=false`             | OK — CA ignored when not verifying                 |
| right CA, `VERIFY_SERVER_CERT` unset              | OK — mysqlnd verifies by default when TLS is on    |
| wrong CA, `VERIFY_SERVER_CERT` unset              | fail `[2002]`                                      |
| right CA + `VERIFY_SERVER_CERT=true`              | OK                                                 |
| wrong CA + `VERIFY_SERVER_CERT=true`              | fail `[2002]`                                      |
| right CA + `VERIFY_SERVER_CERT=true`, host not in cert SAN | fail `[2002]` — hostname **is** checked   |
| missing CA file + `VERIFY_SERVER_CERT=true`       | fail `[2002]`                                      |

Consequences:

- `require` without a CA uses **`ATTR_SSL_CA => ''`**: it switches TLS on without touching cipher negotiation. Document this in a code comment at the mapping.
- `VERIFY_SERVER_CERT` must always be set **explicitly** (`false` for `require`, `true` for `verify`) — mysqlnd's default is to verify as soon as TLS is on.
- `verify` checks CA and hostname, which confirms the single `verify` mode (§3).
- All TLS failures surface as the same generic `[2002] Cannot connect to MySQL using SSL`, with no PHP warning carrying details. Wrong CA, hostname mismatch and missing file are indistinguishable at driver level → Clonio must check file existence itself before connecting, and the hint for `[2002]` must name all likely causes (§8).

`disable` against a server with `require_secure_transport=ON` fails as today; the error hint (§8) applies.

### 5.2 PostgreSQL (Laravel `PostgresConnector` DSN keys)

| Mode      | Config keys                                                                   |
|-----------|-------------------------------------------------------------------------------|
| unset     | none (libpq default `prefer`)                                                 |
| `disable` | `sslmode => disable`                                                          |
| `require` | `sslmode => require`; `sslcert`, `sslkey` if set (never `sslrootcert`, see §3.1) |
| `verify`  | `sslmode => verify-full`; `sslrootcert => <ca>` if set, else `sslrootcert => system` (OS trust store, libpq ≥ 16); `sslcert`, `sslkey` if set |

### 5.3 SQL Server (Laravel `SqlServerConnector`)

| Mode      | Config keys                                                   |
|-----------|---------------------------------------------------------------|
| unset     | legacy behaviour: `trust_server_certificate` flag if stored   |
| `disable` | `encrypt => 'no'`                                             |
| `require` | `encrypt => 'yes'`, `trust_server_certificate => 'yes'`       |
| `verify`  | `encrypt => 'yes'`, `trust_server_certificate => 'no'`        |

Values are strings: `SqlServerConnector` interpolates them into the DSN, where PHP `false` would render as an empty value.

`ca`, `cert`, `key` are not supported by the `pdo_sqlsrv` DSN; for `sqlsrv` they are a validation error. `verify` uses the OS trust store.

**Legacy `trust_server_certificate`:** still read. If `ssl` is present it wins and `trust_server_certificate` is ignored. `connection:update` migrates a stored `trust_server_certificate: true` to `ssl: { mode: require }` and drops the legacy key. The `--trust-server-certificate` option on `connection:add` stays as an alias for `--ssl-mode=require` (sqlsrv only) and is marked deprecated in its help text.

---

## 6. Command Changes

### 6.1 `connection:add`

New options (value options, declared **with `=`**):

```
{--ssl-mode=   : Transport security — disable|require|verify (network drivers only)}
{--ssl-ca=     : Path to CA certificate (PEM)}
{--ssl-cert=   : Path to client certificate (PEM, mutual TLS)}
{--ssl-key=    : Path to client private key (PEM, mutual TLS)}
```

Interactive flow — inserted after **Password** and before **Is production?** (PRD-connection-add §5), network drivers only:

1. **Transport security** — choice: `Require (encrypted, not verified)` / `Verify (encrypted + certificate check)` / `Disable` / `Driver default`. **Default `Require`, except `sqlsrv` which defaults to `Verify`** (ODBC Driver 18 already verifies by default — `require` would weaken it). Skipped if `--ssl-mode` is given. With `--no-interaction` and no `--ssl-mode`, the same per-driver default is used.
2. **CA certificate path** — only for `verify`, and not for `sqlsrv`. Empty answer = none (then validation requires it on `mysql`/`mariadb`). Skipped if `--ssl-ca` is given.
3. **Client certificate?** — only for `require` / `verify`, not `sqlsrv`; confirm, default `no`. If yes, prompt **Client certificate path** and **Client key path**. Skipped if `--ssl-cert`/`--ssl-key` are given.

**Default `require` for new connections.** Encrypted transport is the default for every newly added network connection. For `sqlsrv` the default is `verify`, because ODBC Driver 18 already verifies by default — `require` would weaken it. Existing connections without `ssl` keep driver-default behaviour (§4) — the new default is never applied retroactively. Servers without TLS support (e.g. the stock `postgres` Docker image) need an explicit `Disable` / `--ssl-mode=disable`; the connect-time hint (§8) says so.

Non-interactive: passing `--ssl-mode` without further prompts must be enough to create a fully specified connection (the flags above never trigger a prompt when supplied). Options not applicable to the chosen driver (e.g. `--ssl-mode` with `--type=sqlite`, `--ssl-ca` with `--type=sqlsrv`) exit with `ValidationError` (4).

Summary table gains rows (network drivers only): `Transport security` (mode label) and, if set, `CA certificate`, `Client certificate`, `Client key` (paths as entered).

### 6.2 `connection:update`

Same prompts in the same position, pre-filled with stored values. Specifics:

- Path prompts show the stored value in the label and pass **no** default to `ask()`: `CA certificate path [certs/ca.pem] (Enter = keep, "none" = remove)`. Enter keeps the stored value, `none` (case-insensitive) removes it, anything else replaces it. (Passing the stored value as `ask()` default makes an empty answer indistinguishable from "keep", so a CA could never be removed.)
- Switching the mode to `Driver default` or `Disable` drops `ca`/`cert`/`key`; switching to `require` drops `ca`.
- On type change to `sqlite`/`dump`, `ssl` is removed (PRD-connection-update §4). On type change between network drivers, the mode is kept, and fields the new driver does not support are dropped with an info line.
- `showDiff()` includes `ssl.mode`, `ssl.ca`, `ssl.cert`, `ssl.key` — every change must be visible before "Save changes?".
- All prompt results are type-checked (string or null) before entering `ConnectionData` — no raw `mixed` from `ask()`.

### 6.3 `connection:list`

New column `TLS` showing the mode (`default`, `disable`, `require`, `verify`; `—` for sqlite/dump). Paths are not listed.

### 6.4 `connection:test`

- The success line for network connections shows the mode: `prod: OK (42ms, tls: verify)` (`tls: default` when unset).
- With `-v`, after a successful handshake, report the negotiated cipher where cheaply available (MySQL/MariaDB: `SHOW SESSION STATUS LIKE 'Ssl_cipher'`; PostgreSQL: `SELECT ssl, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()`; SQL Server: `SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID` — Microsoft does not expose the cipher over T-SQL, so `encrypt_option = 'TRUE'` is reported as the stable label `encrypted (cipher not reported by SQL Server)`, and `'FALSE'` as no cipher at all, so the `TLS cipher:` line proves encryption for SQL Server exactly as it does for the other drivers). This is the only query beyond the handshake, verbose only, and failures are ignored. Amend PRD-connection-test §4/§7 accordingly.

---

## 7. Validation

All rules are applied in `connection:add` / `connection:update` before confirmation (→ `ValidationError` (4)) and again at connect time in `buildConfig()` (→ `ConnectionError` (3)). Loading `clonio.json` only checks structure — `ssl` is an object, `mode` is valid, `ssl` sits on a network type — and aborts with a message naming the connection, the same way an invalid `type` is handled today.

| Rule                                                                 | Error                                                  |
|----------------------------------------------------------------------|--------------------------------------------------------|
| `ssl` on `sqlite` / `dump`                                           | `ssl is only supported for network connections`        |
| `mode` not in `disable|require|verify`                               | list valid modes                                       |
| `ca`/`cert`/`key` set with mode `disable`                            | `certificate files require mode require or verify`     |
| `ca` set with mode `require`                                         | `a CA certificate is only used with mode verify`       |
| `ca`/`cert`/`key` on `sqlsrv`                                        | `sqlsrv uses the system trust store; certificate paths are not supported` |
| `verify` without `ca` on `mysql`/`mariadb`                           | `mode verify requires a CA certificate for MySQL/MariaDB` |
| only one of `cert` / `key`                                           | `cert and key must be set together`                    |
| file does not exist / not readable (add/update only)                 | show resolved absolute path                            |

File existence is checked at add/update time and again at connect time — **not** when merely loading `clonio.json`, so a config can be shared before the certs exist on a given machine.

Warning (not an error): if `key` is group- or world-readable on POSIX systems, print a warning suggesting `chmod 600`.

---

## 8. Error Handling at Connect Time

`DatabaseConnectionService::open()` maps known failures to actionable messages. The driver error is kept (sanitised, no password) and a hint is appended:

| Driver error (match)                                                     | Hint                                                                                          |
|--------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------|
| MySQL `[3159]` / `insecure transport are prohibited`                     | `The server requires TLS. Run "clonio connection:update <name>" and set transport security to "require" or "verify".` |
| PostgreSQL `pg_hba.conf` + `no encryption` (covers both `no pg_hba.conf entry …` and `pg_hba.conf rejects connection …` from a `hostnossl … reject` line) | same hint |
| MySQL/MariaDB `[2002] Cannot connect to MySQL using SSL` (mode `verify`)  | `TLS handshake failed. Likely causes: the CA file did not sign the server certificate, or the host name "<host>" is not in the certificate. Use mode "require" to skip verification.` |
| MySQL/MariaDB `[2002] Cannot connect to MySQL using SSL` (mode `require`) | `TLS handshake failed. The server may not support TLS — use mode "disable" if the connection is on a trusted network.` |
| MySQL/MariaDB `[2006] MySQL server has gone away` while connecting with mode `require` or `verify` | same "may not support TLS" hint. This is what mysqlnd reports against a server without TLS (Docker spike: MySQL 8.4 `--tls-version=''`, MariaDB 11 `--skip-ssl`). There is no silent plaintext fallback |
| PostgreSQL `server does not support SSL`                                  | same "may not support TLS" hint                                                                |
| PostgreSQL certificate / hostname verification errors                    | same verification hint as MySQL                                                               |
| SQL Server (ODBC 18) `certificate verify failed`                          | same verification hint as MySQL                                                               |
| CA / cert / key file missing at connect time                             | `Certificate file not found: <resolved path>` — raised before PDO is called (mysqlnd reports a missing file only as the generic `[2002]`, see §5.1) |

Exit code stays `ConnectionError` (3).

---

## 9. Security Considerations

- Only file **paths** are stored; no key material in `clonio.json`.
- Certificate and key contents are never printed or logged, including with `-vvv`.
- `require` provides confidentiality against passive eavesdropping only. The mode label in prompts and `connection:list` makes the missing verification explicit.

---

## 10. Acceptance Criteria

- [x] A MySQL 8 server started with `--require_secure_transport=ON` accepts a connection with `ssl.mode = require` and no CA; `connection:test` exits 0. (verified live via Docker smoke test, Task 11)
- [ ] The same server with `ssl.mode = verify` and the server's CA file connects; with a wrong CA it fails with the verification hint (§8) and exit code 3. (pending first CI run — not pushed yet)
- [ ] The same server without `ssl` fails with the `require_secure_transport` hint (§8). (pending first CI run — not pushed yet)
- [ ] MariaDB: same three cases. (pending first CI run — not pushed yet)
- [x] PostgreSQL: `require` → `sslmode=require`; `verify` + `ca` → `sslmode=verify-full`, `sslrootcert=<resolved path>`; `verify` without `ca` → `sslrootcert=system` (unit test on `buildConfig()`).
- [x] SQL Server: `require` → `encrypt=yes, trust_server_certificate=yes`; legacy `trust_server_certificate: true` without `ssl` behaves as today (unit tests).
- [x] Relative `ca` path resolves against cwd (`Storage::fake('local')`), `~/` against `$HOME`, absolute stays unchanged. `base_path()` is never involved (unit test).
- [x] `connection:add --ssl-mode=verify --ssl-ca=certs/ca.pem ...` runs fully non-interactively.
- [x] `connection:add` for a network driver without `--ssl-mode` offers `Require` as the preselected choice; `--no-interaction` stores `ssl: { mode: require }`. (also verified live via Docker smoke test, Task 11)
- [x] `connection:update` keeps a stored CA on Enter, removes it on `none`, and the diff shows the change.
- [x] Existing `clonio.json` files without `ssl` load and connect exactly as before (regression tests on existing fixtures).
- [x] `clonio.schema.json` validates all examples in §4, rejects `ssl` on sqlite, rejects `cert` without `key`. (Task 11 found `dependentRequired` has no effect under the schema's declared draft-07 — fixed to `dependencies`, the draft-07 equivalent, and re-verified with a Draft7Validator)
- [x] `composer test` passes (PHPStan level max, type coverage ≥ 90 %, coverage ≥ 85 %).

- [ ] Every cell of the end-to-end matrix in §10.1 passes in GitHub Actions. (pending first CI run — not pushed yet)
- [x] The feature is documented in the docs project (§10.2).
- [ ] Existing workflows keep passing with the new default. Connections to stock `postgres:16` services in `connection-test.yml` and `cloning-run-test.yml` pass `--ssl-mode=disable`. (flag confirmed present in both workflow files; passing status pending first CI run — not pushed yet)

### 10.1 End-to-end test matrix (GitHub Actions)

Every combination of **driver × server posture × client configuration** runs against a real server in CI. Unit tests cover the config mapping; this matrix proves the drivers behave as the mapping assumes.

**Drivers:** `mysql` (`mysql:8.4`), `mariadb` (`mariadb:11`), `pgsql` (`postgres:16`), `sqlsrv` (`mcr.microsoft.com/mssql/server:2022-latest`).

**Server postures:**

| Posture | MySQL / MariaDB | PostgreSQL | SQL Server |
|---|---|---|---|
| `plain` | TLS off (`--tls-version=''` / `--skip-ssl`) | stock image (no TLS) | n/a (SQL Server always offers TLS) |
| `self-signed` | n/a | n/a | stock image (auto-generated self-signed cert) |
| `tls` | TLS on, test-CA server cert, client TLS optional | `ssl=on`, test-CA server cert, `host` + `hostssl` allowed | test-CA server cert, `forceencryption=0`, test CA installed in the runner's system trust store |
| `tls-required` | `tls` + `--require-secure-transport=ON` | `tls` + `hostnossl … reject` | `tls` + `forceencryption=1` |
| `mtls-required` | `tls-required` + user `REQUIRE X509` | `tls-required` + `hostssl … scram-sha-256 clientcert=verify-ca` (chain only; `verify-full` would also bind the cert CN to the DB user) | n/a (no client certificates, §3.1) |

The test CA is generated per run. The server certificate SAN is `DNS:localhost, IP:127.0.0.1`. A second, unrelated "wrong CA" is generated alongside it.

**Client configurations** (each is one `connection:add`, then `connection:test <name> -v`):

| Case | Connection config | Applies to |
|---|---|---|
| `absent` | no `ssl` block (removed from `clonio.json` after `add`) | all |
| `legacy-trust` | no `ssl`, `trust_server_certificate: true` | sqlsrv |
| `disable` | `mode: disable` | all |
| `require` | `mode: require` | all |
| `verify` | `mode: verify` + test CA (sqlsrv: system store) | all |
| `verify-wrong-ca` | `mode: verify` + wrong CA | mysql, mariadb, pgsql |
| `verify-host-mismatch` | `mode: verify` + test CA, host = runner's non-loopback IP (not in SAN) | all |
| `verify-system` | `mode: verify`, no CA → system trust store (test CA not installed) | pgsql |
| `require-mtls` | `mode: require` + client cert/key signed by the test CA | mysql, mariadb, pgsql |
| `verify-mtls` | `mode: verify` + test CA + client cert/key | mysql, mariadb, pgsql |
| `missing-file` | `mode: verify` + CA path that does not exist (file deleted after `add`) | mysql, mariadb, pgsql |

**Expected outcomes.** `OK` = exit 0 and output `tls: <mode>`. `OK+c` = `OK` plus a `TLS cipher:` line under `-v`. `OK−c` = `OK` and **no** `TLS cipher:` line, which proves plaintext. `F:req` = exit 3 plus "The server requires TLS". `F:tls` = exit 3 plus "TLS handshake failed" (either hint). `F:ver` = exit 3 plus "TLS handshake failed. Likely causes". `F:host` = `F:ver` naming the dialled IP. `F` = exit 3, no specific hint. `F:file` = exit 3 plus "Certificate file not found", with no network connection attempted.

MySQL / MariaDB:

| Case | plain | tls | tls-required | mtls-required |
|---|---|---|---|---|
| `absent` | OK−c | OK−c | F:req | MySQL F ‡ / MariaDB F:req |
| `disable` | OK−c | OK−c | F:req | MySQL F ‡ / MariaDB F:req |
| `require` | F:tls | OK+c | OK+c | F |
| `verify` | F:tls | OK+c | OK+c | F |
| `verify-wrong-ca` | F:tls | F:ver | F:ver | F:ver |
| `verify-host-mismatch` | F:tls | F:host | F:host | F:host |
| `require-mtls` | F:tls | OK+c | OK+c | OK+c |
| `verify-mtls` | F:tls | OK+c | OK+c | OK+c |
| `missing-file` | F:file | F:file | F:file | F:file |

‡ MySQL 8.4 checks the account's `REQUIRE X509` before `require_secure_transport` and refuses a plaintext connection with `[1045] Access denied`, which cannot be told apart from a wrong password, so no hint is given (local rehearsal of the matrix). MariaDB 11 answers `[3159]` and gets the "server requires TLS" hint. The cases file encodes the difference as a per-driver override column (`mysql=F`).

PostgreSQL (`absent` = libpq `prefer`):

| Case | plain | tls | tls-required | mtls-required |
|---|---|---|---|---|
| `absent` | OK−c | OK+c | OK+c | F |
| `disable` | OK−c | OK−c | F:req | F:req |
| `require` | F:tls | OK+c | OK+c | F |
| `verify` | F:tls | OK+c | OK+c | F |
| `verify-wrong-ca` | F:tls | F:ver | F:ver | F:ver |
| `verify-host-mismatch` | F:tls | F:host | F:host | F:host |
| `verify-system` | F:tls | F:ver | F:ver | F:ver |
| `require-mtls` | F:tls | OK+c | OK+c | OK+c |
| `verify-mtls` | F:tls | OK+c | OK+c | OK+c |
| `missing-file` | F:file | F:file | F:file | F:file |

SQL Server (`encrypt_option` cipher query, §6.4, proves encryption per §10.1 footnote conventions; ODBC Driver 18 defaults to `Encrypt=yes`):

| Case | self-signed | tls | tls-required |
|---|---|---|---|
| `absent` | F:ver | OK+c | OK+c |
| `legacy-trust` | OK+c | OK+c | OK+c |
| `disable` | OK−c | OK−c | OK+c † |
| `require` | OK+c | OK+c | OK+c |
| `verify` | F:ver | OK+c | OK+c |
| `verify-host-mismatch` | F:ver | F:host | F:host |

† From Microsoft's ODBC 18 encryption table, not from the Docker spike. SQL Server forces encryption and ODBC encrypts regardless of `Encrypt=no`. The first CI run confirms this cell (and that `encrypt_option` reads `TRUE` there). If it deviates, the spec and docs are corrected; the assertion is never loosened to accept both outcomes.

`self-signed` and `tls` do not set `forceencryption` on the server, so `disable` (`Encrypt=no`) there is genuinely unencrypted (`OK−c`). Every other successful case sets `Encrypt=yes` (`require`/`verify`/`legacy-trust`/driver default for `absent`) or hits a forced server (`tls-required`), so it is always fully encrypted (`OK+c`), even though SQL Server never reports which cipher was used.

The matrix is data-driven. A single cases file per driver family lists `case | posture | expected` rows, with an optional `<driver>=<expected>` column for a driver that deviates within its family (MySQL vs. MariaDB, ‡), and one script runs `connection:add` / `connection:test` and asserts the exit code, the required substring and the forbidden substring. Adding a posture or a case is then one row, not new workflow YAML. `F` cells without a hint assert only the exit code. The CI jobs are blocking, including SQL Server (unlike the existing optional `connection-test-mssql` job).

### 10.2 Documentation (clonio-docs)

The public docs live in the separate docs project (`clonio-dev/clonio-docs`, Pergament; sources under `content/docs/`, `public/` is build output). The feature ships with:

- **New page** `content/docs/1-connections/04-transport-security.md`:
  - modes and what each guarantees
  - the default `require`, and what it means for existing connections
  - certificate files and path resolution
  - mutual TLS
  - SQL Server specifics (system trust store, legacy `trust_server_certificate`)
  - a per-driver mapping table
  - `clonio.json` examples
  - common provider setups (managed MySQL/PostgreSQL with a downloaded CA bundle)
- `01-managing-connections.md`: transport-security prompt and `--ssl-*` flags in the add flow, `TLS` column, `tls:` in test output, update keep/`none`, link to the new page. `.gitignore`/security notes: certificate *paths* are stored, key files belong outside the repo or in `.gitignore`.
- `02-supported-databases.md`: TLS support per driver, and a Docker networking note that `verify` checks the host name, and `host.docker.internal` is usually not in the certificate.
- `5-reference/02-command-reference.md`: `connection:add` / `connection:update` mention transport security.
- `5-reference/04-troubleshooting.md`: one entry per hint in §8 (insecure transport prohibited, handshake failed / server may not support TLS, verification failed, certificate file not found, stock PostgreSQL + default `require`).
- `3-running-clones/03-ci-cd.md`: passing `--ssl-mode` / `--ssl-ca` in pipelines, with the CA file from a CI secret written to disk.

---

## 11. Out of Scope

- Storing certificate contents inline in `clonio.json` (base64 or PEM strings)
- `ssl.capath` (directory of CAs), cipher selection, TLS version pinning
- SSH tunnelling as an alternative secure transport
- Aligning the companion web app's `ssl` field with this model
- Automatic download of provider CA bundles (AWS RDS, Azure, GCP)

---

## 12. Relation to PR #163

PR #163 is not merged. This PRD replaces it. Problems it had that this design avoids:

- CA path resolved via `base_path()` → broke absolute paths and pointed into the read-only binary (§3.2).
- `--attr_ssl_ca` declared without `=` → could not take a value (§6.1).
- `connection:update` could not clear a CA and passed untyped `ask()` results (§6.2).
- CA change not shown in the update diff (§6.2).
- CA-only approach did not cover encrypted-but-unverified connections, the most common fix for issue #149 (§3).

---

## 13. Decisions

- [x] **mysqlnd option combination for `require` without CA** — `ATTR_SSL_CA => ''` + `ATTR_SSL_VERIFY_SERVER_CERT => false`, verified against MySQL 8.4 and MariaDB 11 (§5.1).
- [x] **Audit trail records transport mode** — no, not needed.
- [x] **`cloning:run` warning for unencrypted production connections** — no, not needed.
- [x] **Default for new connections** — `require`, effective with this feature (§6.1). Existing connections are unaffected.
- [x] **End-to-end coverage** — every driver × server posture × client configuration combination runs in GitHub Actions (§10.1).
- [x] **User documentation** — in the clonio-docs project, not only in `docs/commands/` (§10.2).
- [x] **mysqlnd against a server without TLS** — `require`/`verify` fail with `[2006] MySQL server has gone away`, with no silent plaintext fallback (Docker spike, MySQL 8.4 + MariaDB 11; §8).
