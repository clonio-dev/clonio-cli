# Docker container as a release channel

**Issue:** [#84](https://github.com/clonio-dev/clonio-cli/issues/84)
**Status:** Approved for implementation
**Date:** 2026-04-21

## Problem

Clonio CLI currently ships as three standalone binaries, a PHAR, and a Composer package. Users who prefer containerized tooling have no first-class install path and must wrap the binary themselves. Issue #84 asks for a Docker image, "fully packed with all necessary dependencies," that can be used as a one-off command against files in the current working directory.

## Goals

- One-line container invocation that matches the UX of the native binary:
  ```bash
  docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest <command>
  ```
- Multi-arch (`linux/amd64` + `linux/arm64`) published under a single manifest, so `docker pull` auto-selects.
- Published automatically as part of the existing tag-driven release flow. No extra manual step.
- Verifiable on branches via `workflow_dispatch` — the image must build, even if it doesn't push.
- Pre-release tags (`-alpha`, `-beta`, `-rc`) publish only the exact version, never `:latest` or floating tags.

## Non-goals

- Not a development container (no mounted source, no PHP runtime inside).
- Not publishing to Docker Hub — GHCR only for v1.
- Not preinstalling database client tools (`mysql-client`, `pg_dump`) preemptively. The current CLI doesn't shell out, and YAGNI applies. Alpine base leaves the door open to add them later with a one-line `apk add`.
- No attempt to sign or SBOM-attest the image in v1. Can be layered on later.

## High-level architecture

```
GitHub Actions — build.yml
──────────────────────────────────────────────────────────────
  test  →  build  →  docker  →  release  →  notify-packagist
                        │
                        └─ depends on `build` for the prebuilt
                           clonio-linux-x86_64 + clonio-linux-aarch64
                           artifacts; no recompile inside Docker.
```

The Docker image is an assembly step, not a compilation step. Both static binaries already exist as artifacts from the `build` matrix; the `docker` job downloads them, renames into a platform-keyed layout that `docker buildx` understands, and runs a single `buildx build --platform linux/amd64,linux/arm64` to produce a multi-arch manifest.

## Components

### 1. `Dockerfile` (repo root)

```dockerfile
# syntax=docker/dockerfile:1.7
FROM alpine:3

# ca-certificates — Guzzle-based adapters (S3, webhook, ntfy) need a CA bundle.
# tzdata — correct timestamps in audit logs / cloning dumps when TZ is set.
RUN apk add --no-cache ca-certificates tzdata

ARG TARGETARCH
COPY bin/clonio-linux-${TARGETARCH} /usr/local/bin/clonio
RUN chmod +x /usr/local/bin/clonio

WORKDIR /workspace
ENTRYPOINT ["/usr/local/bin/clonio"]
```

The CI step stages binaries as `bin/clonio-linux-amd64` and `bin/clonio-linux-arm64` (renaming from the native artifact names `clonio-linux-x86_64` / `clonio-linux-aarch64`), so the `COPY` line is a clean `${TARGETARCH}` substitution.

### 2. `.dockerignore`

Minimal — excludes everything except the `bin/` directory and the `Dockerfile` itself:

```
*
!Dockerfile
!bin/
```

### 3. `docker` job in `.github/workflows/build.yml`

Appended after the existing `build` job. Outline:

- `needs: build`
- `permissions: packages: write` for GHCR
- Download `clonio-linux-*` artifacts via `actions/download-artifact@v8` with `pattern` + `merge-multiple: true`
- Stage: rename to `bin/clonio-linux-amd64`, `bin/clonio-linux-arm64`, chmod +x
- `docker/setup-qemu-action@v3` (arm64 emulation)
- `docker/setup-buildx-action@v3`
- `docker/login-action@v3` — only when `github.event_name == 'push'` (skip for dry-runs)
- `docker/metadata-action@v5`:
  ```yaml
  images: ghcr.io/clonio-dev/clonio
  flavor: latest=false
  tags: |
    type=semver,pattern={{version}}
    type=semver,pattern={{major}}.{{minor}},enable=${{ !contains(github.ref_name, '-') }}
    type=semver,pattern={{major}},enable=${{ !contains(github.ref_name, '-') }}
    type=raw,value=latest,enable=${{ github.event_name == 'push' && !contains(github.ref_name, '-') }}
  labels: |
    org.opencontainers.image.source=https://github.com/clonio-dev/clonio-cli
    org.opencontainers.image.description=Clonio CLI — safe production-to-dev database transfers
    org.opencontainers.image.licenses=MIT
  ```
- `docker/build-push-action@v6`:
  ```yaml
  context: .
  platforms: linux/amd64,linux/arm64
  push: ${{ github.event_name == 'push' }}
  tags: ${{ steps.meta.outputs.tags }}
  labels: ${{ steps.meta.outputs.labels }}
  cache-from: type=gha
  cache-to: type=gha,mode=max
  ```

The `release` job is **not** updated to depend on `docker`. If the image build or push fails, the GitHub Release still publishes the native binaries. This matches the current behaviour where PHAR / binary failure doesn't block other artifacts.

### 4. Docs

- **`docs/docker-distribution.md`** (new) — follows the shape of `composer-distribution.md`:
  - Quickstart `docker run` line
  - Tag scheme table
  - Supported platforms
  - Pinning guidance (`:1.2.3` in CI, `:latest` for ad-hoc)
  - Recipes: mounting `clonio.json`, passing `.env`, `TZ` forwarding, UID/GID notes for files the container writes
- **`README.md`** — new row in the install/channels table.
- **`build.yml`** release body — extend the install table with Docker.
- **`docs/releasing.md`** — one-paragraph note that Docker publishes automatically, with pre-release caveats.

## Publishing matrix

| Trigger                         | Tags pushed                                     |
|---------------------------------|--------------------------------------------------|
| Stable tag `vX.Y.Z`             | `X.Y.Z`, `X.Y`, `X`, `latest`                    |
| Pre-release `vX.Y.Z-alpha.N`    | `X.Y.Z-alpha.N` only                             |
| `workflow_dispatch`             | *(image is built for validation; nothing pushed)* |

## User-facing invocation

```bash
# Run any clonio command against files in the current directory
docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest init
docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest cloning:run

# Pin to a specific version (recommended for CI)
docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:1.2.3 --version

# Pass a .env through
docker run --rm --env-file .env -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:latest ...
```

## Risks and mitigations

| Risk | Mitigation |
|------|-----------|
| QEMU-emulated arm64 build is slow | Cached via `type=gha`; binaries are prebuilt so there's no compilation inside Docker. Expected <2 min steady state. |
| GHCR package visibility defaults to private on first push | Manually mark the package public after the first successful publish (one-time). Document in `docs/releasing.md`. |
| File ownership — files the container writes are owned by root | Document `--user "$(id -u):$(id -g)"` as the standard invocation in docs. |
| `:latest` drift if a pre-release is the newest tag | Prevented by the `enable=` guards on `type=raw,value=latest`. |
| Dockerfile silently stops matching artifact naming | CI staging step is explicit about the rename; if artifact names ever change, the stage step fails loudly before `docker buildx`. |

## Testing plan

- Trigger `workflow_dispatch` on the implementation branch and confirm the `docker` job builds both arches and skips the push.
- After merge, tag `make alpha` and verify:
  - Image `:X.Y.Z-alpha.N` is published
  - No `:latest`, no floating `:X` / `:X.Y` tags for that version
- After a real `make patch`, verify the full tag set (exact, minor, major, latest) appears.
- Smoke-test from a clean host: `docker run --rm ghcr.io/clonio-dev/clonio:latest --version`.
- Round-trip test with an `init` + a generated `clonio.json`: confirm the file appears on the host under `$(pwd)` with expected ownership.

## Out of scope (future work)

- Docker Hub mirror
- Image signing (cosign) and SBOMs (syft → GHCR)
- Adding `mysql-client` / `postgresql-client` — revisit once the CLI actually needs them
- A dev-container variant with the source mounted and the dev toolchain preinstalled
