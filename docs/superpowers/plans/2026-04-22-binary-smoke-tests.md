# Binary Smoke Tests + mPDF/PHAR Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the mPDF write-error that crashes `cloning:run` inside the PHAR/micro-SAPI runtime, and add a per-artifact functional smoke test that gates every release (Linux x86_64 binary, Linux aarch64 binary, macOS aarch64 binary, PHAR, Docker image).

**Architecture:** One-line config change in `AuditLogRenderer` to direct mPDF's temp files to `sys_get_temp_dir()`. A shared bash script (`tests/smoke/run-smoke.sh`) executes `init → 2 sqlite connections → fake:data → cloning:dump → cloning:run → row-count verification` against any clonio invocation (native binary, PHAR, or Docker image). The script is wired into `.github/workflows/build.yml` so each binary runs the smoke after build, and the Docker job runs the smoke against a single-arch local image before the multi-arch push. The release job's `needs:` is extended to include `docker`.

**Tech Stack:** PHP 8.5 / Laravel Zero, mPDF, Pest v4, GitHub Actions, Bash, sqlite3 CLI, Docker buildx.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/Services/Audit/AuditLogRenderer.php` | Modified — pass `tempDir` to Mpdf constructor |
| `tests/Unit/Services/Audit/AuditLogRendererTest.php` | Modified — add `renderPdf()` happy-path test |
| `tests/smoke/run-smoke.sh` | New — shared functional smoke script (3 invocation modes) |
| `.github/workflows/build.yml` | Modified — replace `--version` smoke with functional smoke; add PHAR smoke; add Docker smoke; gate release on docker |

---

## Task 1: Fix mPDF tempDir for PHAR runtime

**Files:**
- Modify: `app/Services/Audit/AuditLogRenderer.php:235-243`
- Modify: `tests/Unit/Services/Audit/AuditLogRendererTest.php` (add new test, reuses existing `makeFullAuditRecord()` helper)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/Audit/AuditLogRendererTest.php`:

```php
it('renders a PDF document', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $pdf = $renderer->renderPdf($record, $canonicalJson);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});
```

- [ ] **Step 2: Run test to confirm baseline (passes today, but locks in behavior)**

Run: `./vendor/bin/pest tests/Unit/Services/Audit/AuditLogRendererTest.php --filter="renders a PDF"`
Expected: PASS (the bug is PHAR-specific; in dev runtime mPDF writes to `vendor/.../tmp` happily).

This test is the regression guard against removing the PDF rendering entirely. The PHAR-specific failure is covered by the binary smoke test in later tasks.

- [ ] **Step 3: Apply the tempDir fix**

In `app/Services/Audit/AuditLogRenderer.php:235-243`, change:

```php
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_top' => 18,
    'margin_bottom' => 18,
    'margin_left' => 14,
    'margin_right' => 14,
    'default_font' => 'DejaVuSans',
]);
```

to:

```php
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_top' => 18,
    'margin_bottom' => 18,
    'margin_left' => 14,
    'margin_right' => 14,
    'default_font' => 'DejaVuSans',
    // Direct mpdf's spill files to the OS temp dir. Without this, mpdf
    // defaults to a path inside its own vendor directory, which is read-only
    // when clonio runs from the PHAR/micro-SAPI distribution.
    'tempDir' => sys_get_temp_dir(),
]);
```

- [ ] **Step 4: Run tests to confirm still passing**

Run: `./vendor/bin/pest tests/Unit/Services/Audit/AuditLogRendererTest.php`
Expected: all PASS.

- [ ] **Step 5: Run static analysis**

Run: `composer test:types`
Expected: PHPStan passes (no new errors).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Audit/AuditLogRenderer.php tests/Unit/Services/Audit/AuditLogRendererTest.php
git commit -m "fix(audit): set mpdf tempDir to OS temp so PDF works inside PHAR

mpdf defaults to a path under vendor/mpdf/mpdf/tmp for spill files. That path
is read-only when clonio is shipped as a PHAR / micro-SAPI binary, causing
cloning:run to crash mid-audit-render with:

  mkdir(): phar error: cannot create directory \"phar:///.../tmp/mpdf\",
  write operations disabled

Pointing tempDir at sys_get_temp_dir() works on Linux, macOS, and inside the
alpine Docker image. Adds a happy-path Pest test for renderPdf(); the
PHAR-specific regression is covered by the binary smoke tests added in the
follow-up commits."
```

---

## Task 2: Add shared smoke-test script

**Files:**
- Create: `tests/smoke/run-smoke.sh`

- [ ] **Step 1: Create the script**

Write `tests/smoke/run-smoke.sh` with mode `0755`:

```bash
#!/usr/bin/env bash
#
# Functional smoke test for a clonio artifact (binary, PHAR, or Docker image).
#
# Exercises the end-to-end flow:
#   init → 2 sqlite connections (prod/dev) → fake:data → cloning:dump → cloning:run
#   → verify all 9 fake-data tables transferred row-for-row to the dev sqlite file.
#
# Usage:
#   tests/smoke/run-smoke.sh bin    /abs/path/to/clonio-linux-x86_64
#   tests/smoke/run-smoke.sh phar   /abs/path/to/clonio.phar
#   tests/smoke/run-smoke.sh docker clonio:smoke-test
#
# Requires on PATH: bash 4+, sqlite3, docker (only for docker mode), php (only
# for phar mode).

set -euo pipefail

if [[ $# -lt 2 ]]; then
    echo "Usage: $0 <bin|phar|docker> <path-or-image>" >&2
    exit 2
fi

MODE="$1"
TARGET="$2"

# Tables created and seeded by `fake:data`. Update in lockstep with
# app/Fake/FakeSchemaBuilder.php.
TABLES=(
    users
    user_login_history
    projects
    issues
    comments
    categories
    tags
    products
    product_tags
)
ROW_COUNT=20

WORK="$(mktemp -d -t clonio-smoke-XXXXXX)"
cleanup() {
    rc=$?
    if [[ $rc -ne 0 ]]; then
        echo "" >&2
        echo "─── DIAGNOSTICS (smoke failed, rc=$rc) ───" >&2
        echo "Working directory: $WORK" >&2
        if [[ -f "$WORK/clonio.json" ]]; then
            echo "--- clonio.json ---" >&2
            cat "$WORK/clonio.json" >&2 || true
        fi
        if [[ -f "$WORK/prod.sqlite" ]]; then
            echo "--- prod.sqlite schema ---" >&2
            sqlite3 "$WORK/prod.sqlite" '.schema' >&2 || true
        fi
        if [[ -f "$WORK/dev.sqlite" ]]; then
            echo "--- dev.sqlite schema ---" >&2
            sqlite3 "$WORK/dev.sqlite" '.schema' >&2 || true
        fi
    fi
    rm -rf "$WORK"
    exit $rc
}
trap cleanup EXIT

cd "$WORK"

# Build the $CLONIO command vector based on mode. Using an array so paths with
# spaces survive correctly. Container mounts host $WORK at the same path so
# absolute paths stored in clonio.json resolve identically inside and out.
case "$MODE" in
    bin)
        CLONIO=("$TARGET")
        ;;
    phar)
        CLONIO=(php "$TARGET")
        ;;
    docker)
        # --user matches the runner's UID so files written by the container
        # remain owned by the runner and `rm -rf` in cleanup works.
        CLONIO=(docker run --rm \
            --user "$(id -u):$(id -g)" \
            -v "$WORK:$WORK" \
            -w "$WORK" \
            "$TARGET")
        ;;
    *)
        echo "Unknown mode: $MODE (expected bin|phar|docker)" >&2
        exit 2
        ;;
esac

clonio() { "${CLONIO[@]}" "$@"; }

echo "─── Smoke test ($MODE: $TARGET) ───"
echo "Working dir: $WORK"
echo

echo "▶ init"
clonio init

echo
echo "▶ connection:add prod (sqlite, production)"
clonio connection:add prod \
    --type=sqlite \
    --database="$WORK/prod.sqlite" \
    --production \
    --no-interaction

echo
echo "▶ connection:add dev (sqlite)"
clonio connection:add dev \
    --type=sqlite \
    --database="$WORK/dev.sqlite" \
    --no-interaction

echo
echo "▶ fake:data prod $ROW_COUNT --fresh"
clonio fake:data prod "$ROW_COUNT" --fresh --no-interaction

echo
echo "▶ cloning:dump (auto-generate spec from prod schema)"
clonio cloning:dump --connection=prod --output=smoke.cloning.yaml --ci --force

echo
echo "▶ cloning:run smoke.cloning.yaml --target=dev (this exercises the audit PDF — PHAR regression)"
clonio cloning:run smoke.cloning.yaml --target=dev --ci

echo
echo "▶ verify row counts (prod vs. dev) for ${#TABLES[@]} tables"
fail=0
for t in "${TABLES[@]}"; do
    src=$(sqlite3 "$WORK/prod.sqlite" "SELECT COUNT(*) FROM $t;")
    dst=$(sqlite3 "$WORK/dev.sqlite"  "SELECT COUNT(*) FROM $t;")
    if [[ "$src" -eq 0 ]]; then
        echo "  ✗ $t: source has 0 rows — fake:data did not seed" >&2
        fail=1
    elif [[ "$src" != "$dst" ]]; then
        echo "  ✗ $t: source=$src target=$dst — mismatch" >&2
        fail=1
    else
        echo "  ✓ $t: $src rows transferred"
    fi
done

if [[ $fail -ne 0 ]]; then
    echo "smoke test FAILED — see diagnostics above" >&2
    exit 1
fi

echo
echo "✓ smoke test PASSED"
```

- [ ] **Step 2: Make it executable**

Run: `chmod +x tests/smoke/run-smoke.sh`

- [ ] **Step 3: Validate locally against the dev `php clonio` entry point**

Run: `./tests/smoke/run-smoke.sh bin "$(pwd)/clonio"`

Note: `clonio` (the project root file) is a PHP script with a shebang, so `bin` mode works against it directly without `php`. Expected: passes through all steps and prints `✓ smoke test PASSED`. If it fails, fix the script — this is the cheapest iteration loop.

- [ ] **Step 4: Validate locally against the PHAR**

Run:
```bash
composer build  # produces builds/clonio
./tests/smoke/run-smoke.sh phar "$(pwd)/builds/clonio"
```

Expected: passes (same behavior — micro SAPI not yet in the loop).

- [ ] **Step 5: Commit**

```bash
git add tests/smoke/run-smoke.sh
git commit -m "test(smoke): shared functional smoke script for binaries / phar / docker

Drives clonio through the full init → 2 sqlite connections → fake:data →
cloning:dump → cloning:run flow and verifies that every fake-data table is
transferred row-for-row from the prod sqlite to the dev sqlite.

Three invocation modes (bin / phar / docker) so the same script runs against
every release artifact. Diagnostics on failure dump the working clonio.json
and both sqlite schemas before cleanup."
```

---

## Task 3: Wire smoke into `build` job (Linux x86_64, Linux aarch64, macOS aarch64, PHAR)

**Files:**
- Modify: `.github/workflows/build.yml:234-235` (existing `--version` smoke step)

- [ ] **Step 1: Replace the `--version` smoke step**

Locate this in `.github/workflows/build.yml` (currently lines 234-235):

```yaml
      - name: Smoke test
        run: ./${{ matrix.output }} --version
```

Replace with:

```yaml
      - name: Smoke test — version
        run: ./${{ matrix.output }} --version

      - name: Smoke test — functional (binary)
        run: ./tests/smoke/run-smoke.sh bin "$PWD/${{ matrix.output }}"

      - name: Smoke test — functional (PHAR)
        if: matrix.upload_phar
        run: ./tests/smoke/run-smoke.sh phar "$PWD/builds/clonio"
```

The PHAR smoke runs only on the linux-x86_64 matrix entry (the only row with `upload_phar: true`). PHP is already installed in the build job by the `shivammathur/setup-php` step earlier, so `php` is on PATH for phar mode. `sqlite3` is preinstalled on `ubuntu-latest`, `ubuntu-24.04-arm`, and `macos-latest` runners.

- [ ] **Step 2: Lint the YAML locally**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/build.yml'))" && echo OK`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/build.yml
git commit -m "ci(build): run functional smoke against every binary + phar

Replaces the --version-only smoke check with the shared
tests/smoke/run-smoke.sh flow, executed natively on each build matrix
runner (linux-x86_64, linux-aarch64, macos-aarch64). PHAR smoke runs on
the linux-x86_64 row, which is the one that uploads the PHAR artifact.

Catches PHAR/micro-SAPI runtime regressions like the recent mpdf tempDir
crash before they ship."
```

---

## Task 4: Add Docker smoke + gate release on docker job

**Files:**
- Modify: `.github/workflows/build.yml` (`docker` job — insert smoke before multi-arch push; `release` job — extend `needs`)

- [ ] **Step 1: Insert local-load + smoke step in the `docker` job**

In `.github/workflows/build.yml`, find the `docker` job. Between the existing `Set up Docker Buildx` step (around line 281-282) and the `Log in to GHCR` step (around line 284-290), insert:

```yaml
      - name: Build amd64 image into local daemon (for smoke)
        uses: docker/build-push-action@v6
        with:
          context: .
          platforms: linux/amd64
          load: true
          tags: clonio:smoke-test
          cache-from: type=gha

      - name: Smoke test — functional (Docker)
        run: ./tests/smoke/run-smoke.sh docker clonio:smoke-test
```

`load: true` on buildx requires single-platform; we test amd64 natively on the `ubuntu-latest` runner. The arm64 docker layer wraps the same `clonio-linux-aarch64` binary that was already smoke-tested natively in Task 3, so we trust it without QEMU.

- [ ] **Step 2: Gate the release job on the docker job**

Find this in `.github/workflows/build.yml` (around line 326):

```yaml
  release:
    name: Publish Release
    needs: [test, build]
    runs-on: ubuntu-latest
    if: always() && needs.test.result == 'success' && needs.build.result == 'success' && github.event_name == 'push' && startsWith(github.ref, 'refs/tags/')
```

Change to:

```yaml
  release:
    name: Publish Release
    needs: [test, build, docker]
    runs-on: ubuntu-latest
    if: always() && needs.test.result == 'success' && needs.build.result == 'success' && needs.docker.result == 'success' && github.event_name == 'push' && startsWith(github.ref, 'refs/tags/')
```

- [ ] **Step 3: Lint the YAML again**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/build.yml'))" && echo OK`
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/build.yml
git commit -m "ci(docker): smoke-test image before push and gate release on it

Build a single-arch amd64 image into the local docker daemon (\`load: true\`)
and run the functional smoke against it before the multi-arch buildx push.
Extend the release job's \`needs:\` to include \`docker\` so a smoke regression
inside the container blocks publishing.

The arm64 docker layer wraps the same clonio-linux-aarch64 binary that's
already smoke-tested natively in the build job, so we don't QEMU-test it
inside docker."
```

---

## Task 5: Push branch, open PR, trigger dry-run build

- [ ] **Step 1: Push the branch**

Run: `git push -u origin fix/mpdf-phar-tempdir-and-binary-smoke-tests`

- [ ] **Step 2: Open the PR**

Run:
```bash
gh pr create --title "fix(audit): mpdf tempDir for PHAR + per-artifact smoke tests" --body "$(cat <<'EOF'
## Summary

- **Bug fix:** `cloning:run` crashed inside the PHAR/micro-SAPI distribution because mPDF tried to create its temp dir inside the read-only PHAR. mPDF now uses `sys_get_temp_dir()`.
- **Functional smoke tests:** new shared `tests/smoke/run-smoke.sh` exercises `init → 2 sqlite connections → fake:data → cloning:dump → cloning:run → row-count verification` against every release artifact:
  - Linux x86_64 binary (native on `ubuntu-latest`)
  - Linux aarch64 binary (native on `ubuntu-24.04-arm`)
  - macOS aarch64 binary (native on `macos-latest`)
  - PHAR (on `ubuntu-latest`, where it's already uploaded)
  - Docker image (single-arch amd64 loaded into local daemon)
- The release job now \`needs: [test, build, docker]\` so a smoke failure in any artifact blocks publishing.

Spec: \`docs/superpowers/specs/2026-04-22-binary-smoke-tests-design.md\`
Plan: \`docs/superpowers/plans/2026-04-22-binary-smoke-tests.md\`

## Test plan

- [ ] Pest unit suite (\`composer test\`) passes locally
- [ ] \`tests/smoke/run-smoke.sh bin "\$PWD/clonio"\` passes locally against the dev entry point
- [ ] \`tests/smoke/run-smoke.sh phar "\$PWD/builds/clonio"\` passes locally against the freshly built PHAR
- [ ] CI dry-run via \`workflow_dispatch\` on \`build.yml\` shows green smoke on every matrix row + docker
EOF
)"
```

- [ ] **Step 3: Trigger the dry-run build**

Run: `gh workflow run build.yml --ref fix/mpdf-phar-tempdir-and-binary-smoke-tests`

Then poll once after a few seconds:

Run: `gh run list --workflow build.yml --branch fix/mpdf-phar-tempdir-and-binary-smoke-tests --limit 1`

Expected: a run is queued/in-progress. Note its run id and report it back to the user.

---

## Self-Review

**Spec coverage:**
- mPDF tempDir fix → Task 1 ✓
- Shared smoke script with 3 modes → Task 2 ✓
- build job: replace --version, add PHAR smoke → Task 3 ✓
- docker job: amd64 smoke before multi-arch push → Task 4 ✓
- release gate on docker → Task 4 ✓
- Pest regression test for renderPdf() → Task 1 ✓
- Files-touched list (spec §4) → matches Task 1+2+3+4 ✓

**Placeholder scan:** no TBDs, no "implement later", no "similar to Task N", no missing code blocks.

**Type/name consistency:** `clonio` shell function, `CLONIO` array, `TABLES` array, `ROW_COUNT` — used consistently. `clonio:smoke-test` Docker tag used same way in Task 4 step 1 and step 1 again. Workflow step names all match the format used elsewhere in `build.yml`.

Plan complete and saved to `docs/superpowers/plans/2026-04-22-binary-smoke-tests.md`.
