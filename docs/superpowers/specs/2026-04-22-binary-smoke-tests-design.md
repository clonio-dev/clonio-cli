# Binary Smoke Tests in Release Pipeline + mPDF/PHAR Fix

**Date:** 2026-04-22
**Status:** Design

## Problem

Two issues surfaced on the latest macOS release:

1. **Crash on `cloning:run`** — the Audit PDF renderer asks mPDF to write its temp files inside the vendor directory. Inside the PHAR archive this path is read-only, so mPDF fails with `mkdir(): phar error: … write operations disabled`. The end-to-end flow has never been exercised against a compiled binary in CI — only `--version` is smoke-tested today.
2. **No functional coverage for release artifacts** — the release is gated on `test` (runs `composer test` on source) and `build` (compiles three binaries), but nothing runs `clonio` through a real workflow against the compiled binaries, the PHAR, or the Docker image. A bug that only appears inside the PHAR/micro SAPI slips past CI.

## Goal

Before any release artifact (Linux x86_64 binary, Linux aarch64 binary, macOS aarch64 binary, PHAR, Docker image) is published, run a shared smoke test against it that exercises the full end-to-end cloning flow — the same flow that failed on macOS.

## Non-goals

- Covering every DB backend combination against every binary — the existing `cloning-run-test.yml` already runs an 11-combo matrix against PHP source. The binary smoke test only needs to prove the binary can execute the core flow; SQLite-only is sufficient.
- Adding per-release signing/notarization for macOS — separate concern.

## Design

### 1. mPDF tempDir fix

`app/Services/Audit/AuditLogRenderer.php:235-243` constructs `new Mpdf([...])` without setting `tempDir`. mPDF then defaults to `vendor/mpdf/mpdf/src/Config/../../tmp/mpdf`, which is inside the PHAR archive and read-only.

**Change:** add `'tempDir' => sys_get_temp_dir()` to the Mpdf config array.

`sys_get_temp_dir()` is writable on Linux, macOS, and inside Docker containers; it returns `/tmp` by default in the PHP micro SAPI runtime and respects `TMPDIR` when set. The PDF buffer is still returned in-memory via `$mpdf->Output('', 'S')`, so the temp directory is only used for mPDF's internal spill files during rendering.

### 2. Shared smoke-test script

New file: `tests/smoke/run-smoke.sh`

The script supports three invocation modes, selected by the first argument:

```
run-smoke.sh bin <path>        # native binary: /path/to/clonio-linux-x86_64
run-smoke.sh phar <path>       # php <path>    : /path/to/clonio.phar
run-smoke.sh docker <image>    # docker run --rm -v "$work_dir":/work -w /work <image>
```

The script creates a host tmp working directory and builds `$CLONIO` accordingly. For native/phar, `$CLONIO` is a command that runs on the host and reads/writes inside the cwd. For docker, `$CLONIO` is a `docker run` invocation that mounts the host tmp dir at `/work` — the container's cwd matches the host cwd, so paths like `$PWD/prod.sqlite` resolve identically inside and outside the container. Host-side verification (`sqlite3 dev.sqlite`) then operates on the shared mount.

Flow (identical across all three modes):

1. Create an isolated temp working directory (`mktemp -d`) and `cd` into it; register a trap to clean up on exit.
2. `$CLONIO init` — generates `.env` with `APP_KEY` and seeds `clonio.json` with default audit channels.
3. `$CLONIO connection:add prod --type=sqlite --database="$PWD/prod.sqlite" --production --no-interaction`
4. `$CLONIO connection:add dev --type=sqlite --database="$PWD/dev.sqlite" --no-interaction`
5. `$CLONIO fake:data prod 20 --fresh --no-interaction` — creates the 9-table schema and seeds 20 rows per table (small for CI speed; structure matters more than volume).
6. `$CLONIO cloning:dump --connection=prod --output=smoke.cloning.yaml --ci --force` — auto-generates a complete cloning spec from the seeded schema. Matches the pattern in `cloning-run-test.yml`.
7. `$CLONIO cloning:run smoke.cloning.yaml --target=dev --ci` — executes the transfer and (crucially) generates the audit PDF. This is the step that currently fails in PHAR.
8. Verification: for each of the 9 tables (`users`, `user_login_history`, `projects`, `issues`, `comments`, `categories`, `tags`, `products`, `product_tags`), assert that `sqlite3 dev.sqlite "SELECT COUNT(*) FROM <table>"` returns a non-zero row count and equals the source count. If any differ, dump both SQLite files' `.schema` and the `clonio.json` for diagnostics, then exit non-zero.

Exit codes: `0` on success, non-zero on any failure with a meaningful error line on stderr.

Note on path translation in Docker mode: because the host tmp dir and the container `/work` are bind-mounted, and the script stores the *host* `$PWD` in `clonio.json` (via `connection:add --database="$PWD/..."`), subsequent commands must see the same path. Solution: `cd` on the host into the tmp dir and use the container invocation `-v "$PWD":"$PWD" -w "$PWD"` — the path is identical on both sides, so the stored config works from either side. This is simpler than `/work` path rewriting.

### 3. CI wiring (`.github/workflows/build.yml`)

**`build` job (existing matrix: linux x86_64, linux aarch64, macos aarch64):**

Replace the current `Smoke test` step (line 234-235: `./${{ matrix.output }} --version`) with:

```yaml
- name: Smoke test — functional
  run: ./tests/smoke/run-smoke.sh "$PWD/${{ matrix.output }}"
```

On the linux-x86_64 matrix entry (`upload_phar: true`), add a second invocation:

```yaml
- name: Smoke test — PHAR
  if: matrix.upload_phar
  run: ./tests/smoke/run-smoke.sh php "$PWD/builds/clonio"
```

Runners:
- Linux x86_64 → `ubuntu-latest` (has sqlite3, bash, PHP via shivammathur setup) ✓
- Linux aarch64 → `ubuntu-24.04-arm` (same Ubuntu image, sqlite3 available) ✓
- macOS aarch64 → `macos-latest` (has sqlite3 preinstalled) ✓

**`docker` job:**

Currently the job builds multi-arch and pushes only on tag events. We insert a smoke step before the multi-arch build:

1. Build an **amd64-only** image into the local docker daemon via `docker buildx build --load --platform linux/amd64 -t clonio:smoke-test .` (the existing Dockerfile already reads the right binary from `bin/`).
2. Run `./tests/smoke/run-smoke.sh docker clonio:smoke-test`. The script handles the `docker run` invocation internally (see §2 note on path translation).
3. Only after that succeeds, the existing `docker/build-push-action` step builds the multi-arch image and optionally pushes.

The aarch64 Docker layer wraps the same `clonio-linux-aarch64` binary that was already smoke-tested natively in the `build` job, so we do not need a slow QEMU round for it.

**`release` job:**

Change `needs: [test, build]` to `needs: [test, build, docker]` so the Docker smoke gate protects the release.

### 4. Regression coverage in Pest

Add a small unit test `tests/Unit/Services/Audit/AuditLogRendererPdfTest.php` that instantiates the renderer, calls `renderPdf()` with a minimal fixture, and asserts the result begins with `%PDF-`. This protects the happy path. The PHAR-specific regression is covered by the binary smoke test above; a unit test cannot simulate the PHAR stream wrapper.

## Files touched

```
app/Services/Audit/AuditLogRenderer.php        — one-line mpdf config change
tests/Unit/Services/Audit/AuditLogRendererPdfTest.php   — new test (happy path)
tests/smoke/run-smoke.sh                       — new shared script (~100 lines bash)
.github/workflows/build.yml                    — replace --version smoke; add PHAR step;
                                                 add docker smoke; gate release on docker
```

## Risks and tradeoffs

- **CI time impact.** Each smoke run is ~10–30s (20 rows × 9 tables, SQLite). Added to build: ~3 × 30s + PHAR 30s + docker build/smoke ~1–2min. Acceptable for release gating.
- **`sys_get_temp_dir()` in Docker.** `/tmp` is writable in the Alpine base image — no issue. If a user bind-mounts their own `/tmp` as read-only, mPDF breaks; that is exotic and documented as unsupported.
- **Docker amd64-only smoke.** We rely on the arm64 Docker image using the same binary that the build job smoke-tested natively. This is a reasonable trust boundary — the Dockerfile logic is identical across arches.
- **`fake:data` schema drift.** If someone changes the 9-table list in `FakeSchemaBuilder`, the verification loop's hardcoded table names must be updated. Mitigation: keep the list in a single shell variable at the top of the script, and add a comment pointing at `app/Fake/FakeSchemaBuilder.php`.
