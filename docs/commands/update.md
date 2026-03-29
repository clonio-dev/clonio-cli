# `update` Command

Updates the currently running Clonio binary or PHAR to the latest release from GitHub.

## Usage

```bash
clonio update
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

1. Fetches the latest release tag from the GitHub API
2. Compares it against the current version
3. If already up to date, exits with a message
4. Downloads the new file to a temporary path alongside the current binary
5. Sets executable permissions (`0755`)
6. Atomically replaces the current binary via `rename()`

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Already up to date, or updated successfully |
| `1` | GitHub unreachable, download failed, or binary could not be replaced |

## Permissions

The command replaces the file it is running from. If it is installed in a system path (e.g. `/usr/local/bin`), elevated permissions may be required:

```bash
sudo clonio update
```

## Notes

- The download follows redirects (GitHub releases redirect to the CDN)
- A partial download is cleaned up automatically on failure
- `arm64` (macOS convention) is normalised to `aarch64` (Linux convention) for filename resolution
