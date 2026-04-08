# Releasing

Releases are fully automated via GitHub Actions. The only manual step is pushing a git tag.

## Steps

**1. Make sure `main` is clean and tests pass**

```bash
git checkout main
git pull
composer test
```

**2. Create and push a version tag**

Use the Makefile to tag and push in one step:

```bash
make patch   # v0.1.0 → v0.1.1
make minor   # v0.1.1 → v0.2.0
make major   # v0.2.0 → v1.0.0
```

Run `make` with no arguments to see the current version and a preview of what each command would produce.

Each `make` target creates a git tag on the current commit and pushes it to `origin`. It does **not** modify any files — the version is resolved at runtime from the tag (see [Version resolution](#version-resolution) below).

Or tag manually if needed:

```bash
git tag v1.0.0
git push origin v1.0.0
```

The tag must follow the `v*` pattern (e.g. `v0.1.0`, `v1.2.3`). This is what triggers the `build` workflow.

**3. Watch the workflow**

Go to `https://github.com/clonio-dev/clonio-cli/actions` and open the `build` run. It has three stages:

| Stage | What happens |
|-------|-------------|
| **Tests** | Full test suite runs on Ubuntu. If this fails, nothing else runs. |
| **Build** | Three jobs run in parallel — one per platform. Each compiles a standalone binary, builds the PHAR, and uploads both as artifacts. |
| **Release** | Downloads all artifacts and publishes a GitHub release with binaries, PHAR, and an auto-generated changelog. |

The full run takes roughly 15–30 minutes on a cold cache (SPC compiles PHP from source). Subsequent runs with a warm cache are much faster.

**4. Verify the release**

Once the workflow completes, the release appears at:
`https://github.com/clonio-dev/clonio-cli/releases/latest`

It will contain four files:

| File | Platform | Requires |
|------|----------|----------|
| `clonio-linux-x86_64` | Linux (Intel/AMD) | nothing |
| `clonio-linux-aarch64` | Linux (ARM64) | nothing |
| `clonio-macos-aarch64` | macOS Apple Silicon | nothing |
| `clonio.phar` | Any platform | PHP 8.5 |

The version embedded in each binary matches the tag name (see below).

## Version resolution

The app version is resolved at runtime in `AppServiceProvider` using this priority:

1. **`VERSION` file** — during CI, the build workflow writes the tag name (e.g. `v1.2.3`) into `VERSION` before building the PHAR. This file is baked into the PHAR/binary.
2. **`git describe --tags`** — used during local development when the `VERSION` file contains `unreleased`.
3. **Composer `InstalledVersions`** — final fallback.

No manual version bump in `composer.json` or any other file is required. The Makefile targets only create and push git tags.

## Branch protection

Direct pushes to `main` are forbidden. All changes must go through a pull request. The `tests` workflow must pass before merging.

## Deleting a bad release

If something goes wrong after pushing a tag:

```bash
# Delete the tag locally and remotely
git tag -d v1.0.0
git push origin :refs/tags/v1.0.0
```

Then delete the draft/broken release on GitHub manually, fix the issue, and re-tag.

## Cache invalidation

If you change the PHP version or extensions list in `build.yml`, bump the `-v2` suffix in the two SPC cache keys (`spc-downloads-*` and `spc-build-*`) so the old compiled PHP is not reused.
