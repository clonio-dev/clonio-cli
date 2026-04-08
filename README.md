# Clonio CLI

[![Tests](https://github.com/clonio-dev/clonio-cli/actions/workflows/tests.yml/badge.svg)](https://github.com/clonio-dev/clonio-cli/actions/workflows/tests.yml)
[![Connection Tests](https://github.com/clonio-dev/clonio-cli/actions/workflows/connection-test.yml/badge.svg)](https://github.com/clonio-dev/clonio-cli/actions/workflows/connection-test.yml)
[![Cloning Run Tests](https://github.com/clonio-dev/clonio-cli/actions/workflows/cloning-run-test.yml/badge.svg)](https://github.com/clonio-dev/clonio-cli/actions/workflows/cloning-run-test.yml)
[![Latest Release](https://img.shields.io/github/v/release/clonio-dev/clonio-cli)](https://github.com/clonio-dev/clonio-cli/releases/latest)
[![Packagist Version](https://img.shields.io/packagist/v/clonio-dev/clonio-cli)](https://packagist.org/packages/clonio-dev/clonio-cli)

Clonio transfers your production database to your test and dev environments with automatic anonymization, fake data generation, and full audit trails.

It is free and open source for individuals and NGOs. See [clonio.dev](https://clonio.dev) for more information.

---

## Installation

Download the binary for your platform from the [latest release](https://github.com/clonio-dev/clonio-cli/releases/latest):

| Platform            | Binary                   |
|---------------------|--------------------------|
| Linux x86_64        | `clonio-linux-x86_64`    |
| Linux aarch64       | `clonio-linux-aarch64`   |
| macOS Apple Silicon | `clonio-macos-aarch64`   |
| Any (PHP 8.5+)      | `clonio.phar`            |

The platform binaries are fully self-contained — no PHP required. The PHAR requires PHP 8.5 on the target machine (`php clonio.phar`) but is smaller and works on any platform.

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

### Composer (dev dependency)

Clonio can also be required as a dev dependency in any PHP project:

```bash
composer require --dev clonio-dev/clonio-cli
```

This makes `vendor/bin/clonio` available with the exact release version. See [docs/composer-distribution.md](docs/composer-distribution.md) for details, CI examples, and PHP version requirements.

---

## Commands

| Command | Description |
|---------|-------------|
| [`init`](docs/commands/init.md) | Bootstrap Clonio in the current directory |
| [`update`](docs/commands/update.md) | Update to the latest release |
| [`connection:add`](docs/commands/connection-add.md) | Add a new database connection |
| [`connection:update`](docs/commands/connection-update.md) | Update an existing database connection |
| [`connection:list`](docs/commands/connection-list.md) | List all configured database connections |
| [`connection:delete`](docs/commands/connection-delete.md) | Delete a saved database connection |
| [`connection:test`](docs/commands/connection-test.md) | Test one or all saved database connections |
| [`cloning:dump`](docs/commands/cloning-dump.md) | Inspect a database and generate a .cloning.yaml file |
| [`cloning:run`](docs/commands/cloning-run.md) | Transfer a database using a .cloning.yaml configuration |
| [`cloning:column:edit`](docs/commands/cloning-column-edit.md) | Edit a column strategy in a .cloning.yaml file |
| [`.cloning.yaml` format](docs/cloning-yaml.md) | Full reference for the cloning configuration file format |
| [`matchers:init`](docs/commands/cloning-matchers.md) | Write PII matcher baseline to clonio.pii-matchers.yaml |
| [`matchers:update`](docs/commands/cloning-matchers.md) | Add new baseline matchers to clonio.pii-matchers.yaml |
| [`matchers:list`](docs/commands/cloning-matchers.md) | Show effective PII matcher set |
| [`matchers:check`](docs/commands/cloning-matchers.md) | Test a column name against the matcher set |
| [`audit:add`](docs/commands/audit-channel.md) | Add an audit delivery channel |
| [`audit:update`](docs/commands/audit-channel.md) | Update an existing audit delivery channel |
| [`audit:delete`](docs/commands/audit-channel.md) | Delete an audit delivery channel |
| [`audit:list`](docs/commands/audit-channel.md) | List all configured audit delivery channels |
| [`cloning:verify-audit`](docs/commands/cloning-verify-audit.md) | Verify the integrity of a Clonio audit log |
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
