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

```bash
git tag v1.0.0
git push origin v1.0.0
```

The tag must follow the `v*` pattern (e.g. `v0.1.0`, `v1.2.3`). This is what triggers the build workflow.

**3. Watch the workflow**

Go to `https://github.com/clonio-dev/clonio-cli/actions` and open the `build` run. It has two stages:

| Stage | What happens |
|-------|-------------|
| **Tests** | Full test suite runs on Ubuntu. If this fails, no binaries are built. |
| **Build** | Four jobs run in parallel — one per platform. Each compiles a standalone binary and uploads it to the release. |

The full run takes roughly 15–30 minutes on a cold cache (SPC compiles PHP from source). Subsequent runs with a warm cache are much faster.

**4. Verify the release**

Once the workflow completes, the release appears at:
`https://github.com/clonio-dev/clonio-cli/releases/latest`

It will contain three binaries:

| File | Platform |
|------|----------|
| `clonio-linux-x86_64` | Linux (Intel/AMD) |
| `clonio-linux-aarch64` | Linux (ARM64) |
| `clonio-macos-aarch64` | macOS Apple Silicon |

The version embedded in each binary matches the tag name and is resolved automatically from git — no manual version bump required.

## Deleting a bad release

If something goes wrong after pushing a tag:

```bash
# Delete the tag locally and remotely
git tag -d v1.0.0
git push origin :refs/tags/v1.0.0
```

Then delete the draft/broken release on GitHub manually, fix the issue, and re-tag.

## Cache invalidation

If you change the PHP version or extensions list in `build.yml`, bump the `-v1` suffix in the two SPC cache keys (`spc-downloads-*` and `spc-build-*`) so the old compiled PHP is not reused.
