# Clonio CLI

[![Tests](https://github.com/clonio-dev/clonio-cli/actions/workflows/tests.yml/badge.svg)](https://github.com/clonio-dev/clonio-cli/actions/workflows/tests.yml)
[![Latest Release](https://img.shields.io/github/v/release/clonio-dev/clonio-cli)](https://github.com/clonio-dev/clonio-cli/releases/latest)

Clonio transfers your production database to your test and dev environments with automatic anonymization, fake data generation, and full audit trails.

It is free and open source for individuals and NGOs. See [clonio.io](https://clonio.io) for more information.

---

## Installation

Download the binary for your platform from the [latest release](https://github.com/clonio-dev/clonio-cli/releases/latest):

| Platform            | Binary                   |
|---------------------|--------------------------|
| Linux x86_64        | `clonio-linux-x86_64`    |
| Linux aarch64       | `clonio-linux-aarch64`   |
| macOS Apple Silicon | `clonio-macos-aarch64`   |

Rename it, make it executable, and optionally move it to your PATH:

```bash
# Linux
mv clonio-linux-x86_64 clonio
chmod +x clonio
mv clonio /usr/local/bin/clonio

# macOS
mv clonio-macos-aarch64 clonio
chmod +x clonio
mv clonio /usr/local/bin/clonio
```

No PHP installation required — the binary is fully self-contained.

> **macOS note:** The binary is currently unsigned. macOS may block it with a Gatekeeper warning. To allow it, run:
> ```bash
> xattr -d com.apple.quarantine clonio
> ```
> See [docs/code-signing.md](docs/code-signing.md) for the full signing setup once an Apple Developer account is available.

---

## Commands

| Command | Description |
|---------|-------------|
| [`about`](docs/commands/about.md) | Display product information |

---

## Development

See [docs/development.md](docs/development.md) for setup, local builds, testing, and linting.

---

## Releasing

See [docs/releasing.md](docs/releasing.md) for the step-by-step release process.

---

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).
