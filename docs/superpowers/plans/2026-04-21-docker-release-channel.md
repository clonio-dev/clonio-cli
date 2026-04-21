# Docker Release Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish `clonio-cli` as a multi-arch Docker image to GHCR as a first-class release channel, driven by the existing tag-based CI flow.

**Architecture:** A new `docker` job in `.github/workflows/build.yml` runs after the existing `build` matrix, downloads the prebuilt static Linux binaries (amd64 + arm64), and assembles them into an Alpine-based multi-arch image via `docker buildx`. No recompilation inside Docker. Publishing rules: stable tags push the full tag set (`X.Y.Z`, `X.Y`, `X`, `latest`); pre-release tags push only the exact version; `workflow_dispatch` builds the image for validation but does not push.

**Tech Stack:** Alpine Linux base, Docker Buildx, GitHub Actions (`docker/setup-qemu-action@v3`, `docker/setup-buildx-action@v3`, `docker/login-action@v3`, `docker/metadata-action@v5`, `docker/build-push-action@v6`), GHCR (`ghcr.io/clonio-dev/clonio`).

**Working directory:** `/Users/rok/workspace/clonio-dev/clonio-cli`

**Branch:** `feat/84-docker-release-channel` (already created, already has the design spec committed).

**Spec reference:** `docs/superpowers/specs/2026-04-21-docker-release-channel-design.md`

---

## Task 1: Add `.dockerignore`

Keeps the build context minimal — only the binaries and the Dockerfile itself.

**Files:**
- Create: `.dockerignore`

- [ ] **Step 1: Create `.dockerignore`**

Create the file with this exact content:

```
# Build context is intentionally minimal — only the static binaries
# (staged by CI into bin/) and the Dockerfile itself need to be present.
*
!Dockerfile
!bin/
```

- [ ] **Step 2: Commit**

```bash
git add .dockerignore
git commit -m "chore(docker): add .dockerignore limiting build context to bin/ + Dockerfile

Refs #84"
```

---

## Task 2: Add the `Dockerfile`

Alpine base, copies the platform-specific static binary in via `${TARGETARCH}`.

**Files:**
- Create: `Dockerfile`

- [ ] **Step 1: Create `Dockerfile`**

Create the file with this exact content:

```dockerfile
# syntax=docker/dockerfile:1.7
FROM alpine:3

# ca-certificates — Guzzle-based audit adapters (S3, webhook, ntfy) need a CA bundle.
# tzdata — correct timestamps in audit logs and cloning dumps when TZ is set.
RUN apk add --no-cache ca-certificates tzdata

# CI stages the binaries as:
#   bin/clonio-linux-amd64  (from the x86_64 artifact)
#   bin/clonio-linux-arm64  (from the aarch64 artifact)
# buildx sets TARGETARCH to "amd64" or "arm64" per platform.
ARG TARGETARCH
COPY bin/clonio-linux-${TARGETARCH} /usr/local/bin/clonio
RUN chmod +x /usr/local/bin/clonio

WORKDIR /workspace
ENTRYPOINT ["/usr/local/bin/clonio"]
```

- [ ] **Step 2: Local smoke-build with a placeholder binary**

The real binary is only produced by SPC in CI. For local validation, create a fake executable so we can prove the Dockerfile parses and copies correctly on the host architecture.

Run (from repo root):

```bash
mkdir -p bin
# Host is macOS arm64 → target is linux/arm64 → Dockerfile wants bin/clonio-linux-arm64
printf '#!/bin/sh\necho clonio-stub %s\n' "$@" > bin/clonio-linux-arm64
chmod +x bin/clonio-linux-arm64
docker buildx build --platform linux/arm64 --load -t clonio-local-test .
docker run --rm --platform linux/arm64 clonio-local-test || true
rm -rf bin
```

Expected output: the `docker buildx build` command finishes with `=> naming to docker.io/library/clonio-local-test` (or similar) and `docker run` prints `clonio-stub` lines. If buildx errors with "bin/clonio-linux-arm64: not found", the Dockerfile's `COPY` path is wrong — fix before continuing.

> **If you don't have Docker available locally:** skip the smoke-build. Task 8 exercises the full build in CI via `workflow_dispatch`, which is the authoritative verification.

- [ ] **Step 3: Verify `bin/` is gone**

Run: `ls bin 2>&1`
Expected: `ls: bin: No such file or directory` (we cleaned it up; it must NOT be committed).

- [ ] **Step 4: Commit**

```bash
git add Dockerfile
git commit -m "feat(docker): add multi-arch Dockerfile (alpine base, static binary)

Consumes prebuilt clonio-linux-{amd64,arm64} binaries staged under bin/
by the CI docker job. ca-certificates and tzdata cover the runtime
dependencies of Guzzle-based adapters and timestamp rendering.

Refs #84"
```

---

## Task 3: Add the `docker` job to `.github/workflows/build.yml`

Runs after `build`, assembles the multi-arch image from the two Linux artifacts, and pushes to GHCR under the tag rules from the spec.

**Files:**
- Modify: `.github/workflows/build.yml` — insert the `docker` job between `build` and `release`

- [ ] **Step 1: Insert the `docker` job**

Open `.github/workflows/build.yml`. Find this block (the end of the `build` job, just before the `release` job):

```yaml
      - name: Upload PHAR as artifact
        if: matrix.upload_phar
        run: cp builds/clonio builds/clonio.phar
      - uses: actions/upload-artifact@v7
        if: matrix.upload_phar
        with:
          name: clonio.phar
          path: builds/clonio.phar

  release:
    name: Publish Release
```

Insert the new `docker` job between the `clonio.phar` upload and the `release:` line, so the block becomes:

```yaml
      - name: Upload PHAR as artifact
        if: matrix.upload_phar
        run: cp builds/clonio builds/clonio.phar
      - uses: actions/upload-artifact@v7
        if: matrix.upload_phar
        with:
          name: clonio.phar
          path: builds/clonio.phar

  docker:
    name: Docker image
    needs: build
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - name: Checkout
        uses: actions/checkout@v6

      - name: Download linux binaries from build job
        uses: actions/download-artifact@v8
        with:
          path: ./artifacts
          pattern: clonio-linux-*
          merge-multiple: true

      - name: Stage binaries for Docker context
        run: |
          mkdir -p bin
          cp artifacts/clonio-linux-x86_64  bin/clonio-linux-amd64
          cp artifacts/clonio-linux-aarch64 bin/clonio-linux-arm64
          chmod +x bin/clonio-linux-*

      - name: Set up QEMU
        uses: docker/setup-qemu-action@v3

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to GHCR
        if: github.event_name == 'push'
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Derive tags and labels
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ghcr.io/clonio-dev/clonio
          # We control :latest explicitly — default flavor would tag every push :latest.
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

      - name: Build and push image
        uses: docker/build-push-action@v6
        with:
          context: .
          platforms: linux/amd64,linux/arm64
          # Push on tag; on workflow_dispatch we only build (validates the Dockerfile).
          push: ${{ github.event_name == 'push' }}
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
          cache-from: type=gha
          cache-to: type=gha,mode=max

  release:
    name: Publish Release
```

Note: the `release` job's existing `needs: [test, build]` and its `if:` condition are **not** modified. If the Docker job fails, the GitHub Release still publishes.

- [ ] **Step 2: Validate workflow YAML syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/build.yml'))" && echo "YAML valid"`

Expected output: `YAML valid`

- [ ] **Step 3: Sanity-check the job was added**

Run: `grep -n '^  docker:' .github/workflows/build.yml`

Expected output: one line showing the `docker:` job at its inserted location, e.g. `241:  docker:` (line number will vary).

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/build.yml
git commit -m "ci(docker): add GHCR multi-arch image publishing job

Reuses the prebuilt clonio-linux-{x86_64,aarch64} artifacts from the
build matrix and assembles them into a multi-arch manifest via buildx.

Tag rules:
  - stable tag vX.Y.Z  → X.Y.Z, X.Y, X, latest
  - pre-release tag    → X.Y.Z-alpha.N only (no floating, no latest)
  - workflow_dispatch  → build only, no push (Dockerfile validation)

Release job does not depend on docker; a docker failure does not block
the GitHub Release.

Refs #84"
```

---

## Task 4: Add Docker row to the GitHub Release body

The existing release notes include an install table; Docker should appear there too.

**Files:**
- Modify: `.github/workflows/build.yml` — the release body in the `release` job

- [ ] **Step 1: Extend the install table**

In `.github/workflows/build.yml`, find the `Create GitHub Release` step's body block containing this table:

```yaml
            | File | Platform | Requires |
            |------|----------|----------|
            | `clonio-linux-x86_64` | Linux — x86_64 (Intel/AMD) | nothing |
            | `clonio-linux-aarch64` | Linux — aarch64 (ARM64) | nothing |
            | `clonio-macos-aarch64` | macOS — Apple Silicon (M1/M2/M3/M4) | nothing |
            | `clonio.phar` | Any platform | PHP 8.5 |
```

Add a new row for Docker right after the `clonio.phar` row, so the table reads:

```yaml
            | File | Platform | Requires |
            |------|----------|----------|
            | `clonio-linux-x86_64` | Linux — x86_64 (Intel/AMD) | nothing |
            | `clonio-linux-aarch64` | Linux — aarch64 (ARM64) | nothing |
            | `clonio-macos-aarch64` | macOS — Apple Silicon (M1/M2/M3/M4) | nothing |
            | `clonio.phar` | Any platform | PHP 8.5 |
            | `ghcr.io/clonio-dev/clonio:${{ github.ref_name }}` | linux/amd64 + linux/arm64 | Docker |
```

- [ ] **Step 2: Add a Docker usage section under the PHAR section**

In the same release body, find the `### PHAR (any platform with PHP 8.5)` section:

```yaml
            ### PHAR (any platform with PHP 8.5)

            ```bash
            php clonio.phar --version
            ```

            ### Composer (dev dependency)
```

Insert a new `### Docker` section between PHAR and Composer, so it reads:

```yaml
            ### PHAR (any platform with PHP 8.5)

            ```bash
            php clonio.phar --version
            ```

            ### Docker

            ```bash
            docker run --rm -v "$(pwd)":/workspace \
              ghcr.io/clonio-dev/clonio:${{ github.ref_name }} --version
            ```

            See [docs/docker-distribution.md](https://github.com/clonio-dev/clonio-cli/blob/main/docs/docker-distribution.md) for pinning, tag scheme, and common recipes.

            ### Composer (dev dependency)
```

- [ ] **Step 3: Validate workflow YAML**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/build.yml'))" && echo "YAML valid"`

Expected output: `YAML valid`

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/build.yml
git commit -m "docs(release): surface Docker channel in GitHub Release notes

Refs #84"
```

---

## Task 5: Create `docs/docker-distribution.md`

User-facing guide, patterned after `docs/composer-distribution.md`.

**Files:**
- Create: `docs/docker-distribution.md`

- [ ] **Step 1: Create the doc**

Create `docs/docker-distribution.md` with this exact content:

````markdown
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
````

- [ ] **Step 2: Commit**

```bash
git add docs/docker-distribution.md
git commit -m "docs: add Docker distribution guide

Covers tag scheme, common recipes (env files, TZ, --user on Linux),
image internals, and CI pinning guidance.

Refs #84"
```

---

## Task 6: Add Docker to the `README.md` install table and add a Docker section

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Extend the install table**

Open `README.md`. Find the existing install table:

```markdown
| Platform            | Binary                   |
|---------------------|--------------------------|
| Linux x86_64        | `clonio-linux-x86_64`    |
| Linux aarch64       | `clonio-linux-aarch64`   |
| macOS Apple Silicon | `clonio-macos-aarch64`   |
| Any (PHP 8.5+)      | `clonio.phar`            |
```

Add a Docker row, making it:

```markdown
| Platform            | Binary                               |
|---------------------|--------------------------------------|
| Linux x86_64        | `clonio-linux-x86_64`                |
| Linux aarch64       | `clonio-linux-aarch64`               |
| macOS Apple Silicon | `clonio-macos-aarch64`               |
| Any (PHP 8.5+)      | `clonio.phar`                        |
| Docker (any OS)     | `ghcr.io/clonio-dev/clonio:latest`   |
```

- [ ] **Step 2: Add a Docker install section**

Find the existing `### Composer (dev dependency)` section:

```markdown
### Composer (dev dependency)

Clonio can also be required as a dev dependency in any PHP project:

```bash
composer require --dev clonio-dev/clonio-cli
```

This makes `vendor/bin/clonio` available with the exact release version. See [docs/composer-distribution.md](docs/composer-distribution.md) for details, CI examples, and PHP version requirements.
```

Insert a new `### Docker` section directly above it, so the file reads:

````markdown
### Docker

No install required — run Clonio as a one-off container against your current directory:

```bash
docker run --rm -v "$(pwd)":/workspace \
  ghcr.io/clonio-dev/clonio:latest --version
```

Multi-arch image (`linux/amd64`, `linux/arm64`). See [docs/docker-distribution.md](docs/docker-distribution.md) for the full tag scheme, recipes, and CI pinning guidance.

### Composer (dev dependency)
````

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(readme): add Docker install channel

Refs #84"
```

---

## Task 7: Add a Docker note to `docs/releasing.md`

- [ ] **Step 1: Inspect the current file**

Run: `cat docs/releasing.md`

Expected: you see a step-by-step release guide with a stage table listing Tests / Build / Release.

- [ ] **Step 2: Extend the stage table**

Open `docs/releasing.md`. Find this table:

```markdown
| Stage | What happens |
|-------|-------------|
| **Tests** | Full test suite runs on Ubuntu. If this fails, nothing else runs. |
| **Build** | Three jobs run in parallel — one per platform. Each compiles a standalone binary, builds the PHAR, and uploads both as artifacts. |
| **Release** | Downloads all artifacts and publishes a GitHub release with binaries, PHAR, and an auto-generated changelog. |
```

Add a new **Docker** row between Build and Release, so it reads:

```markdown
| Stage | What happens |
|-------|-------------|
| **Tests** | Full test suite runs on Ubuntu. If this fails, nothing else runs. |
| **Build** | Three jobs run in parallel — one per platform. Each compiles a standalone binary, builds the PHAR, and uploads both as artifacts. |
| **Docker** | Assembles the two Linux static binaries into a multi-arch image and publishes it to `ghcr.io/clonio-dev/clonio`. Pre-release tags publish only the exact version (no `:latest`, no floating tags). Runs after Build. Non-blocking — a Docker failure does not block the GitHub Release. |
| **Release** | Downloads all artifacts and publishes a GitHub release with binaries, PHAR, and an auto-generated changelog. |
```

- [ ] **Step 3: Commit**

```bash
git add docs/releasing.md
git commit -m "docs(releasing): document Docker publishing stage

Refs #84"
```

---

## Task 8: CI dry-run via `workflow_dispatch`

The authoritative verification that the `docker` job works. Builds both arches, skips the GHCR push.

- [ ] **Step 1: Push the branch**

Run:

```bash
git push -u origin feat/84-docker-release-channel
```

Expected: `* [new branch]      feat/84-docker-release-channel -> feat/84-docker-release-channel`

- [ ] **Step 2: Trigger the dry-run**

Run:

```bash
gh workflow run build.yml --ref feat/84-docker-release-channel
```

Expected: no error output. (If `gh` returns "workflow not found", confirm you're inside the `clonio-cli` repo.)

- [ ] **Step 3: Watch the run**

Run:

```bash
sleep 5 && gh run list --workflow=build.yml --branch=feat/84-docker-release-channel --limit=1
```

Expected: one row showing an `in_progress` run. Copy its run ID for the next step.

Then tail it:

```bash
gh run watch  # pick the run just started
```

Expected: `test` → `build` (x3) → `docker` all complete with green checkmarks. `release` and `notify-packagist` are skipped (grey) because this is a `workflow_dispatch`, not a tag push.

- [ ] **Step 4: Confirm Docker built both arches without pushing**

Run:

```bash
RUN_ID=$(gh run list --workflow=build.yml --branch=feat/84-docker-release-channel --limit=1 --json databaseId --jq '.[0].databaseId')
gh run view "$RUN_ID" --log --job="Docker image" 2>&1 | grep -E "(linux/amd64|linux/arm64|Login to GHCR|Build and push image)" | head -20
```

Expected:
- Lines mentioning `linux/amd64` and `linux/arm64` (confirming buildx ran for both platforms).
- The `Log in to GHCR` step is **skipped** (`if: github.event_name == 'push'` guard).
- The `Build and push image` step shows `push: false` effectively — the build completed but nothing was uploaded to the registry.

If the job failed, read the failing step's log and fix the underlying issue; do not retry blindly.

- [ ] **Step 5: No commit**

This task performs verification only. Do not commit anything.

---

## Task 9: Open the pull request

- [ ] **Step 1: Open the PR**

Run:

```bash
gh pr create \
  --title "feat: Docker release channel (GHCR, multi-arch) — closes #84" \
  --body "$(cat <<'EOF'
## Summary

Adds a Docker container as a first-class release channel (issue #84). Multi-arch (`linux/amd64` + `linux/arm64`) image published to `ghcr.io/clonio-dev/clonio` alongside the existing binary/PHAR/Composer release flow.

## What changed

- **`Dockerfile`** (new) — Alpine base, copies the prebuilt static binary keyed by `${TARGETARCH}`, `ENTRYPOINT=/usr/local/bin/clonio`, `WORKDIR=/workspace`.
- **`.dockerignore`** (new) — minimal context (only `Dockerfile` + `bin/`).
- **`.github/workflows/build.yml`** — new `docker` job after `build`. Downloads the `clonio-linux-*` artifacts, renames them to the buildx-friendly `amd64`/`arm64` layout, and publishes via `docker/metadata-action` + `docker/build-push-action`. GHCR login is gated on `push` so `workflow_dispatch` does a build-only dry-run. Release body updated with the Docker install section.
- **`docs/docker-distribution.md`** (new) — tag scheme, recipes (`--env-file`, `TZ`, `--user` on Linux), CI pinning guidance, image internals.
- **`README.md`** — install table + Docker section.
- **`docs/releasing.md`** — describes the new Docker stage.

## Publishing matrix

| Trigger                      | Tags pushed                         |
|------------------------------|-------------------------------------|
| Stable tag `vX.Y.Z`          | `X.Y.Z`, `X.Y`, `X`, `latest`       |
| Pre-release `vX.Y.Z-alpha.N` | `X.Y.Z-alpha.N` only                |
| `workflow_dispatch`          | *(built for validation, not pushed)* |

## Test plan

- [ ] `workflow_dispatch` on this branch — `docker` job builds `linux/amd64` + `linux/arm64`, skips GHCR login and push. **(Verified in Task 8 of the plan.)**
- [ ] After merge, `make alpha` → verify `ghcr.io/clonio-dev/clonio:X.Y.Z-alpha.N` appears in GHCR, no `:latest` or floating tags.
- [ ] Smoke: `docker run --rm ghcr.io/clonio-dev/clonio:X.Y.Z-alpha.N --version` on both `linux/amd64` and `linux/arm64` (or rely on the Docker multi-arch manifest on one arch).
- [ ] Round-trip: `docker run --rm -v "$(pwd)":/workspace ghcr.io/clonio-dev/clonio:X.Y.Z-alpha.N init` — confirm `clonio.json` lands in the host cwd.
- [ ] After `make patch`, verify the full tag set (exact, minor, major, latest) is present.
- [ ] One-time: flip the GHCR package visibility to public after the first successful publish.

Design spec: `docs/superpowers/specs/2026-04-21-docker-release-channel-design.md`.

Closes #84.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected output: a GitHub PR URL (e.g., `https://github.com/clonio-dev/clonio-cli/pull/N`).

- [ ] **Step 2: Report the PR URL back to the user**

Paste the PR URL from the previous step's output so the user can review.

---

## Self-review (for the plan author)

Before handing off execution, confirm:

- Every spec requirement maps to a task:
  - Dockerfile + Alpine + multi-arch → Task 2, Task 3.
  - GHCR publishing + tag scheme → Task 3 (metadata-action tag config), Task 4 (release body).
  - `workflow_dispatch` dry-run → Task 3 (`push:` guard), Task 8 (verification).
  - Pre-release handling → Task 3 (`enable=` guards on floating tags).
  - Release body + README + releasing.md + distribution doc → Tasks 4–7.
  - GHCR package visibility one-time flip → listed in PR test plan (Task 9).

- No placeholders: every code block, command, and expected output is concrete.

- Type consistency: the `COPY` path (`bin/clonio-linux-${TARGETARCH}`) matches the CI staging step (`cp artifacts/clonio-linux-x86_64 bin/clonio-linux-amd64`). Both sides agree on the `amd64` / `arm64` naming.

- Risk: GHCR's first-push-private default. Acknowledged in the PR test plan as a one-time manual step.
