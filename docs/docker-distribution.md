# Docker distribution

Clonio CLI is published as a multi-arch Docker image on the GitHub Container Registry.

**Registry:** `ghcr.io/clonio-dev/clonio`
**Platforms:** `linux/amd64`, `linux/arm64`
**Base:** `alpine:3`

---

## Quick start

Run any Clonio command against files in your current directory:

```bash
docker run --rm -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest --version
```

The image's `WORKDIR` is `/workspace`. Mount your project root there and Clonio reads / writes files exactly as the native binary would.

---

## Tag scheme

| Tag                 | Points to                                          | Use when                        |
|---------------------|----------------------------------------------------|---------------------------------|
| `:latest`           | Newest **stable** release                          | Ad-hoc local use                |
| `:1`                | Newest `1.x.y` stable                              | Tracking a major                |
| `:1.2`              | Newest `1.2.y` stable                              | Tracking a minor                |
| `:1.2.3`            | Exact immutable version                            | **CI pipelines** (recommended)  |
| `:1.2.3-alpha.1`    | Exact pre-release build                            | Validating an alpha             |

Pre-release tags (`-alpha`, `-beta`, `-rc`) never update `:latest` or the floating `:1` / `:1.2` tags.

---

## Common recipes

### Initialize a new project

```bash
docker run --rm -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest init
```

The resulting `clonio.json` lands in your host `$(pwd)` — no copy-out needed.

### Run a cloning job with a `.env`

```bash
docker run --rm \
  --env-file .env \
  -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest cloning:run
```

### Preserve host timezone for audit log timestamps

```bash
docker run --rm \
  -e TZ="$(date +%Z)" \
  -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest cloning:run
```

### Avoid root-owned output files (Linux hosts)

On Linux hosts, files written by the container will be owned by `root` unless you pass `--user`. On macOS and Windows Docker Desktop this is handled automatically.

```bash
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest cloning:run
```

### Pin the version in CI

```yaml
# GitHub Actions example
- name: Run Clonio
  run: |
    docker run --rm \
      -v "${{ github.workspace }}":/workspace \
      ghcr.io/clonio-dev/clonio:1.2.3 cloning:run
```

Prefer an exact `:1.2.3` tag in CI. `:latest` is safe for interactive use but drifts silently as new releases ship.

---

## Image internals

- **Base:** `php:8.5-cli-alpine` with `ca-certificates`, `tzdata`, and the PHP extensions Clonio needs (`gd`, `pcntl`, `pdo_mysql`, `pdo_pgsql` on top of the bundled set).
- **Source layout:** application code + vendor live at `/app`. The CLI entrypoint is `/app/clonio` (the same script you run as `php clonio` in development).
- **Entrypoint:** `php /app/clonio`. Any arguments after `docker run … image` are passed straight through.
- **Workdir:** `/workspace`. Mount your project root here so `clonio.json`, `.env`, and `.cloning.yaml` resolve against the same `getcwd()` Clonio uses on the host.
- **Build:** the image is built from source (no static binary dependency), so the publish step can run in parallel with the platform-specific binary builds.

### Mounting host files

Clonio reads `clonio.json` and `.env` from `getcwd()` — inside the container that's `/workspace`. The `.env` file holds `APP_KEY`, which is required to decrypt any `encrypted:…` connection passwords. **Both files must be on the mounted volume**, otherwise the container will either skip configuration entirely or fail to decrypt credentials.

```bash
docker run --rm -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest connection:list
```

If you keep `clonio.json` / `.env` outside your project root, mount that directory instead and pass `-w /workspace` is unnecessary (`WORKDIR` already points there).

### Connecting to a database on the host

Inside the container, `127.0.0.1` and `localhost` refer to the container itself — not your host machine. If MySQL / PostgreSQL is running on your laptop, the container needs a different hostname to reach it.

**macOS and Windows Docker Desktop** — use the magic DNS name `host.docker.internal`:

```bash
docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest \
  connection:update source --host=host.docker.internal --no-interaction
```

Then test:

```bash
docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest \
  connection:test source
```

**Linux** — `host.docker.internal` is not enabled by default. Either:

```bash
# Option A: add the gateway hostname explicitly
docker run --rm \
  --add-host=host.docker.internal:host-gateway \
  -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest \
  connection:test source

# Option B: share the host network (simplest, Linux-only)
docker run --rm --network=host \
  -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest \
  connection:test source
```

**MySQL bind address.** Default MySQL listens on `127.0.0.1` only and will reject the container's bridge IP. Verify and adjust if needed:

```bash
mysql -uroot -e "SHOW VARIABLES LIKE 'bind_address';"
# if 127.0.0.1 only: set `bind-address = 0.0.0.0` in my.cnf and restart mysqld.
```

(Same applies to PostgreSQL's `listen_addresses` in `postgresql.conf`, plus a matching `pg_hba.conf` entry for the bridge subnet.)

### Building locally

```bash
make docker-build          # host arch only — fast
make docker-build-multiarch # linux/amd64 + linux/arm64 via QEMU
make docker-test           # build + run the docker smoke test
make docker-shell          # interactive sh inside the image, with $(pwd) mounted
```

---

## Release cadence

The image is built and published by the same workflow that produces the release binaries (`.github/workflows/build.yml`), triggered by `v*` tags. No separate release step — tagging a version publishes the binaries, the PHAR, and the Docker image in the same run.
