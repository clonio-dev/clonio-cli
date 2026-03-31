# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is Clonio CLI?

Clonio is a PHP CLI tool (built on Laravel Zero) for safely transferring production databases to development/test environments with automatic anonymization, fake data generation, and audit trails. It compiles to self-contained binaries via Static PHP Compiler (SPC) for Linux x86_64, Linux ARM64, and macOS ARM64.

## Common Commands

```bash
# Development
composer install
php clonio <command>

# Testing
composer test              # Full suite: type coverage, unit tests, PHPStan, lint
composer test:unit         # Pest with coverage (min 75%)
composer test:type-coverage  # Type coverage check (min 90%)
composer test:types        # PHPStan static analysis (level max)
composer test:lint         # Pint + Rector dry-run

# Run a single test file
./vendor/bin/pest tests/Unit/BinaryResolverTest.php
./vendor/bin/pest tests/Unit/BinaryResolverTest.php --filter="it resolves"

# Linting / code style
composer lint              # rector + pint --parallel (auto-fix)

# Build PHAR binary
composer build             # Outputs to builds/clonio

# Version / release management
make current               # Show current version
make patch / minor / major # Bump version and push git tag
```

## Companion Repository

The actual business logic (database transfer, anonymization, etc.) lives in the companion Laravel web app repository:

- **GitHub**: https://github.com/clonio-dev/clonio
- **Local path**: `/Users/rok/workspace/clonio-dev/clonio`

When implementing new CLI commands, look there first for:
- **Services** (`app/Services/`) — reusable business logic to port or call
- **Actions** (`app/Actions/`) — discrete operations (create, transfer, anonymize, etc.)
- **Models & Data** (`app/Models/`, `app/Data/`) — data structures and Eloquent models
- **Tests** (`tests/Feature/`, `tests/Unit/`) — existing test patterns and coverage to mirror in this CLI

The CLI repo is the command-line surface; the companion repo is the implementation source of truth.

## Architecture

### Framework & Entry Point
- **Laravel Zero** (`laravel-zero/framework ^12.1`) — minimal Laravel for CLI apps
- Entry point: `clonio` (PHP script) → `bootstrap/app.php` → Symfony Console
- Commands auto-discovered from `app/Commands/`; configured in `config/commands.php`

### Key Layers

**Commands** (`app/Commands/`) — CLI surface. Use `$this->artisan()` in feature tests.

**Services** (`app/Services/`) — Business logic injected into commands:
- `Services/Update/BinaryResolver` — detects platform (OS + architecture) and execution mode (PHAR vs. PHP micro SAPI standalone binary); maps `arm64 → aarch64`, `darwin → macos`
- `Services/Art/AsciiArtService` — renders ASCII art from `resources/ascii-art/`

**Providers** (`app/Providers/AppServiceProvider`) — service bindings; handles log path differences between PHAR and micro SAPI runtimes

### Binary Build Pipeline
The build workflow (`.github/workflows/build.yml`) triggers on `v*` tags:
1. Runs the full test suite
2. Builds 3 standalone binaries in parallel using SPC (`clonio-linux-x86_64`, `clonio-linux-aarch64`, `clonio-macos-aarch64`) — each is a PHAR merged with a PHP micro SAPI
3. Creates a GitHub release with all binaries and an auto-generated changelog

### Execution Mode Detection
The app runs in two modes and must handle both:
- **Standalone binary** (PHP micro SAPI): `\Phar::running()` returns empty; use `$_SERVER['SCRIPT_FILENAME']`
- **PHAR**: `\Phar::running()` returns the phar path

### Filesystem / Storage
Never use raw `file_get_contents` / `file_put_contents` for application files. Use the **`Storage` facade** (`Illuminate\Support\Facades\Storage`) instead.

`config/filesystems.php` sets the `local` disk root to `getcwd()`:
```php
'local' => ['driver' => 'local', 'root' => getcwd()],
```
This is intentional: PHAR and SPC binaries are read-only archives. Any file that must be written at runtime (e.g. `clonio.json`) must go to the user's working directory, not inside the binary. `Storage::put('clonio.json', $content)` writes to `cwd/clonio.json` regardless of how the binary was packaged.

In tests, isolate filesystem operations with `Storage::fake('local')` (no temp directory management needed). `Storage::path('file')` still returns a real path on disk so `chmod()` works normally.

### Static Analysis & Code Style
- **PHPStan** via Larastan at level max (`phpstan.neon`)
- **Rector** for code modernization with Laravel rulesets (`rector.php`); cache at `/tmp/rector`
- **Pint** for PSR-12 code style

## Testing

- Framework: **PestPHP v4**
- Feature tests: `tests/Feature/Commands/` — test CLI commands via `$this->artisan()`
- Unit tests: `tests/Unit/` — test services with Mockery for dependencies
- Coverage minimum: 75% (unit), 90% (type coverage)
- All tests extend `Tests\TestCase`

## Specs and Docs

- Use markdown for documentation and specs
- Specifications can be found in the `specs/` directory
- Documentation is in the `docs/` directory
