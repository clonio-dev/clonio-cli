# Release Process

## Overview

Clonio CLI uses a two-stage automated release pipeline:

1. **Every push to `main`** — the `phar-build` workflow builds `builds/clonio` (the PHAR) and commits it back to main automatically.
2. **Every `v*` tag** — the `build` workflow runs the full test suite, builds three standalone binaries (Linux x86_64, Linux aarch64, macOS aarch64), and publishes a GitHub release.

The PHAR version is sourced from the `"version"` field in `composer.json` (no `v` prefix).

## Branch protection

**Direct pushes to `main` are forbidden.**

All changes must go through a pull request. Branch protection rules are configured in the GitHub repository settings:

- Require pull request reviews before merging
- Require status checks to pass (tests workflow) before merging
- Do not allow bypassing the above settings

This ensures the test suite always passes before code lands on main, and the auto-committed PHAR build (`[skip ci]`) is the only direct push that CI itself makes.

## Creating a release

**1. Ensure `main` is up to date and tests pass locally**

```bash
git checkout main
git pull
composer test
```

**2. Bump the version with `make`**

```bash
make patch   # e.g. v0.3.2 → v0.3.3
make minor   # e.g. v0.3.3 → v0.4.0
make major   # e.g. v0.4.0 → v1.0.0
```

Run `make` (no arguments) to preview the current version and what each command would produce.

Each `make` target:
- Writes the new version to `VERSION`
- Updates the `"version"` field in `composer.json` (no `v` prefix)
- Commits both files: `chore: release vX.Y.Z`
- Creates the git tag
- Pushes the commit to `main`
- Pushes the tag — this triggers the `build` workflow

**What CI does after the tag is pushed:**

| Stage | Workflow | Trigger |
|-------|----------|---------|
| PHAR build | `phar-build` | push to main (from the release commit) |
| Tests | `build` | `v*` tag |
| Standalone binaries (×3) | `build` | `v*` tag (after tests pass) |
| GitHub release | `build` | `v*` tag (after binaries are built) |

**3. Watch the workflows**

Go to `https://github.com/clonio-dev/clonio-cli/actions`. The `build` run takes 15–30 minutes on a cold cache.

**4. Verify the release**

Once complete, the release is available at `https://github.com/clonio-dev/clonio-cli/releases/latest` with:

| File | Platform | Requirements |
|------|----------|-------------|
| `clonio-linux-x86_64` | Linux — x86_64 | none |
| `clonio-linux-aarch64` | Linux — aarch64 (ARM64) | none |
| `clonio-macos-aarch64` | macOS — Apple Silicon | none |
| `clonio.phar` | Any platform | PHP 8.5 |

## Manual PHAR rebuild

If you need to trigger a PHAR rebuild without pushing code, use the manual dispatch:

1. Go to `https://github.com/clonio-dev/clonio-cli/actions/workflows/phar-build.yml`
2. Click **Run workflow** → select `main` → **Run workflow**

The workflow will rebuild the PHAR and commit it to `main` if it changed.

## Recovering from a bad release

```bash
# Delete the tag locally and remotely
git tag -d v1.0.0
git push origin :refs/tags/v1.0.0
```

Then delete the broken release on GitHub manually, fix the issue, and re-tag.

## Version source of truth

The canonical version lives in `composer.json` → `"version"`. The `VERSION` file mirrors it (with the `v` prefix) for legacy tooling. Both are updated together by the `make` release targets.

## Cache invalidation

If you change the PHP version or extensions list in `build.yml`, bump the `-v2` suffix on the two SPC cache keys (`spc-downloads-*` and `spc-build-*`) so stale compiled PHP is not reused.
