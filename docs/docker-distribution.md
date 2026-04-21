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

- **Base:** `alpine:3` with `ca-certificates` and `tzdata` installed.
- **Binary:** the same static `clonio-linux-{amd64,arm64}` artifact that is attached to each GitHub Release — placed at `/usr/local/bin/clonio`.
- **Entrypoint:** `/usr/local/bin/clonio`. Any arguments after `docker run … image` are passed straight through.
- **Workdir:** `/workspace`. Mount your project root here.

---

## Release cadence

The image is built and published by the same workflow that produces the release binaries (`.github/workflows/build.yml`), triggered by `v*` tags. No separate release step — tagging a version publishes the binaries, the PHAR, and the Docker image in the same run.
