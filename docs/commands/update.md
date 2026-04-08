# `update` Command

Updates the currently running Clonio binary or PHAR to the latest release from GitHub.

## Usage

```bash
clonio update
clonio update 1.2.0
clonio update v1.2.0
clonio update --no-verify-ssl
clonio update 1.2.0 --no-verify-ssl
```

## Behaviour

The command detects how it is being executed and downloads the correct file:

| Runtime | Detected via | Downloads |
|---------|-------------|-----------|
| Compiled binary on Linux x86_64 | `PHP_SAPI === 'micro'` + `php_uname('m')` | `clonio-linux-x86_64` |
| Compiled binary on Linux aarch64 | `PHP_SAPI === 'micro'` + `php_uname('m')` | `clonio-linux-aarch64` |
| Compiled binary on macOS Apple Silicon | `PHP_SAPI === 'micro'` + `php_uname('m')` | `clonio-macos-aarch64` |
| PHAR (`php clonio.phar`) | `PHP_SAPI === 'cli'` | `clonio.phar` |

Steps performed:

1. Fetches the target release from the GitHub API (latest or a specific version)
2. Compares it against the current version
3. If already at the target version, exits with a message
4. Downloads the new file to a temporary path alongside the current binary
5. Sets executable permissions (`0755`)
6. Atomically replaces the current binary via `rename()`

## Arguments

| Argument | Description |
|----------|-------------|
| `version` | Target version to install (e.g. `1.2.0` or `v1.2.0`). When omitted, the latest release is used. |

## Options

| Option | Description |
|--------|-------------|
| `--no-verify-ssl` | Skip SSL certificate verification. Use when behind a corporate VPN or proxy that performs SSL inspection, which can cause certificate errors. |

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Already at the target version, or updated successfully |
| `1` | GitHub unreachable, requested version not found, download failed, or binary could not be replaced |

## Permissions

The command replaces the file it is running from. If it is installed in a system path (e.g. `/usr/local/bin`), elevated permissions may be required:

```bash
sudo clonio update
```

## Notes

- The download follows redirects (GitHub releases redirect to the CDN)
- A partial download is cleaned up automatically on failure
- `arm64` (macOS convention) is normalised to `aarch64` (Linux convention) for filename resolution

### SSL Issues

If the update fails with a certificate error (common behind corporate VPNs with SSL inspection), use `--no-verify-ssl`:

```bash
clonio update --no-verify-ssl
```

This disables certificate verification for both the GitHub API request and the binary download. A warning is printed when this flag is active.
