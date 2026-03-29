# Development Guide

## Requirements

- PHP 8.5
- Composer

## Setup

```bash
composer install
```

## Running locally

```bash
php clonio <command>
```

## Building a local PHAR

```bash
composer build
```

This runs `./clonio app:build clonio --no-interaction` and produces `builds/clonio`.

The version embedded in the PHAR is resolved from the latest git tag via `git describe`. If no tag exists yet, it will fall back to `UNRELEASED`.

> The `builds/` directory is git-ignored. The PHAR is not committed to the repository.

## Testing

Run the full test suite:

```bash
composer test
```

Individual stages:

| Command                    | What it does                              |
|----------------------------|-------------------------------------------|
| `composer test:unit`       | PestPHP with coverage (min 85%)           |
| `composer test:type-coverage` | Type coverage check (min 90%)          |
| `composer test:types`      | PHPStan static analysis (level max)       |
| `composer test:lint`       | Pint code style + Rector dry-run          |

## Linting / auto-fix

```bash
composer lint
```

Runs Rector and Pint in fix mode (modifies files in place).
