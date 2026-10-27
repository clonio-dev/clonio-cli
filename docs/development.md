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

### Whole app

```bash
composer test:mutate:all
```

Mutates every file in parallel and fails below a score of 100%. CI does not run it (a full run takes too long), so run it locally before merging larger changes. New code therefore needs tests that kill all its mutants. Mark a mutant that cannot be killed (equivalent, or not observable in tests) on its line, naming only the mutator, with a short reason in a comment above:

```php
// Outside a PHAR (as in tests) both Phar::running(true|false) return ''.
$pharPath = $this->pharPath ?? Phar::running(false); // @pest-mutate-ignore: FalseToTrue
```

## Linting / auto-fix

```bash
composer lint
```

Runs Rector and Pint in fix mode (modifies files in place).

## Docker (no local PHP)

Instead of installing PHP and Composer locally, you can run everything in a
container. The only requirement is Docker with Compose v2.

The image is the `dev` stage of the `Dockerfile`: `php:8.5-cli-alpine` with the
same PHP extensions as the published Docker image, plus Composer, git and Xdebug.
The repository is bind-mounted to `/app`, so changes on the host are visible
immediately.

| Make target        | Raw command (e.g. on Windows without make)                      |
|--------------------|-----------------------------------------------------------------|
| `make dev-build`   | `docker compose build php`                                      |
| `make dev-install` | `docker compose run --rm php composer install`                  |
| `make dev-shell`   | `docker compose run --rm php sh`                                |
| `make dev-test`    | `docker compose run --rm -e XDEBUG_MODE=coverage php composer test` |
| `make dev-lint`    | `docker compose run --rm php composer lint`                     |

Any other command works the same way, e.g.
`docker compose run --rm php php clonio connection:test`.

Notes:

- **File ownership**: the container entrypoint detects the owner of the
  repository and runs the command as that user, so `vendor/`, caches and
  coverage reports belong to you on Linux, macOS and Windows alike. Under
  rootless Docker/Podman the command runs as root, which maps back to your user.
- **Always use `run --rm`**, not `docker compose exec`: `exec` bypasses the
  entrypoint and would run as root.
- **Xdebug** is loaded but off (`XDEBUG_MODE=off`) to keep PHPStan and tests
  fast. `make dev-test` enables coverage mode; pass `-e XDEBUG_MODE=debug` for
  step debugging.
- **Windows**: keep the checkout inside the WSL2 filesystem (not `/mnt/c/...`)
  — bind mounts from Windows drives are slow.
