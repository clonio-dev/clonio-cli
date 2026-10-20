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
| `composer test:unit`       | PestPHP with coverage (min 95%)           |
| `composer test:type-coverage` | Type coverage check (min 99%)          |
| `composer test:types`      | PHPStan static analysis (level max)       |
| `composer test:lint`       | Pint code style + Rector dry-run          |

### Mutation testing

`composer test:mutate` runs Pest mutation testing on the code you select. It is not part of `composer test`, because mutating the whole app takes several minutes. Arguments after `--` are passed to Pest:

```bash
# One class (file path)
composer test:mutate -- --path=app/Services/Cloning/FrameworkTableCatalog.php

# Limit the tests that run against the mutants (faster)
composer test:mutate -- --path=app/Services/Cloning/FrameworkTableCatalog.php \
  tests/Unit/Services/Cloning/FrameworkTableCatalogTest.php

# A whole directory, in parallel
composer test:mutate -- --path=app/Services/Cloning --parallel

# Fail below a minimum score
composer test:mutate -- --path=app/Services/Cloning --min=80
```

Notes:

- Select the code with `--path` (file or directory) or `--class` (class or namespace). Without a selection, Pest prints usage hints and mutates nothing. Do not combine `--class` with `--everything`: `--everything` ignores the filter and mutates all ~100 files.
- The script runs with `--covered-only`. Pest skips mutants on lines without line coverage. Constant arrays (such as the table lists in `FrameworkTableCatalog`) never report line coverage, so without this flag they count as untested and lower the score.
- Results are cached between runs. Add `--no-cache` to force a fresh run.
- A coverage driver (pcov or Xdebug) is required.

### Whole app (CI)

```bash
composer test:mutate:all
```

Mutates every file in parallel and fails below a score of 100%. The `mutation` job in `.github/workflows/tests.yml` runs it on every pull request. New code therefore needs tests that kill all its mutants. Mark a mutant that cannot be killed (equivalent, or not observable in tests) on its line, naming only the mutator, with a short reason in a comment above:

```php
// Outside a PHAR (as in tests) both Phar::running(true|false) return ''.
$pharPath = $this->pharPath ?? Phar::running(false); // @pest-mutate-ignore: FalseToTrue
```

## Linting / auto-fix

```bash
composer lint
```

Runs Rector and Pint in fix mode (modifies files in place).
