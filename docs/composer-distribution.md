# Composer Distribution (Dev Dependency)

Clonio CLI can be installed as a Composer dev dependency in any PHP project. This makes `vendor/bin/clonio` available with the exact release version.

## Installation

```bash
composer require --dev clonio-dev/clonio-cli
```

This installs the PHAR binary as `vendor/bin/clonio`. You can then call it directly or from CI:

```bash
vendor/bin/clonio cloning:run production.cloning.yaml --target local-dev
```

## Version

The installed version matches the Composer package version (i.e. the git tag used to release the package). Run `vendor/bin/clonio --version` to confirm.

## How It Works

The `bin` entry in `composer.json` points to `clonio`. When Composer installs the package it creates a `vendor/bin/clonio` stub that executes this file.

## PHP Version Requirement

Clonio CLI requires PHP ≥ 8.5. Verify your PHP version:

```bash
php --version
```

## CI Usage

```yaml
# GitHub Actions
- name: Install Clonio
  run: composer require --dev clonio-dev/clonio-cli

- name: Clone staging database
  run: vendor/bin/clonio cloning:run production.cloning.yaml --target staging --ci
  env:
    APP_KEY: ${{ secrets.CLONIO_APP_KEY }}
```

## Notes

- Platform-specific standalone binaries (`clonio-linux-x86_64`, etc.) are built via GitHub Actions and attached to releases; they are **not** committed to git and are not available via Composer.
- For global installation or use without Composer, download a binary from the [latest release](https://github.com/clonio-dev/clonio-cli/releases/latest).
