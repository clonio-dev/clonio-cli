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

# Pre-create the sqlite files. Clonio's DatabaseConnectionService refuses to
# open a sqlite connection whose file does not exist (safety against typos);
# touching them first mirrors the existing cloning-run-test.yml workflow.
touch "$WORK/prod.sqlite" "$WORK/dev.sqlite"

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
