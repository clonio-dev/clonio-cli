# Clonio CLI — Technical Specification

**Version:** 0.2
**Status:** Active
**Date:** 2026-03-29
**Translated from:** PRD-CLI-Tool.md (German)

---

## 1. Goal

Build a cross-platform CLI application on top of **Laravel Zero** (v12) that is distributable as a fully self-contained binary — no PHP installation required on the target system. The build and release process runs fully automated via **GitHub Actions**.

---

## 2. Background

Clonio transfers production databases to test and development environments with automatic anonymization, fake data generation, and full audit trails. It is free and open source for individuals and NGOs.

PHP CLI tools traditionally require a PHP runtime on the target machine. By using **Static PHP CLI (SPC)** with the **phpmicro** SAPI, the application is compiled into a single, self-contained binary with no external dependencies.

---

## 3. Target Audience

- Developers and DevOps teams integrating the tool into their workflows
- End users without PHP knowledge or a local PHP installation

---

## 4. Tech Stack

| Component        | Technology               | Version       |
|------------------|--------------------------|---------------|
| Language         | PHP                      | 8.5           |
| Framework        | Laravel Zero             | 12.x          |
| PHAR Build       | Laravel Zero App Builder | bundled       |
| Binary Compiler  | Static PHP CLI (SPC)     | latest stable |
| CI/CD            | GitHub Actions           | —             |
| Distribution     | GitHub Releases          | —             |

---

## 5. System Architecture

### 5.1 Project Structure

Standard Laravel Zero layout:

```
clonio-cli/
├── app/
│   ├── Commands/           # Artisan commands
│   └── Providers/
├── bootstrap/
├── config/
├── resources/
│   └── ascii-art/          # ASCII art assets
├── tests/
│   ├── Feature/
│   └── Unit/
├── builds/                 # Generated PHAR output (git-ignored)
├── specs/                  # PRD and spec documents
├── .github/
│   └── workflows/
│       ├── tests.yml       # CI: test on push/PR to main
│       └── build.yml       # Release: build binaries + publish on v* tag
├── composer.json
└── clonio                  # Application entry point
```

### 5.2 Build Pipeline

```
Source code
    │
    ▼
composer install --no-dev
    │
    ▼
php artisan app:build clonio     → builds/clonio (PHAR)
    │
    ▼
spc download --with-php=8.5      → PHP sources + extensions
    │
    ▼
spc build --build-micro          → phpmicro.sfx (per platform)
    │
    ▼
spc micro:combine                → Standalone binary
    │
    ▼
GitHub Release Asset
```

---

## 6. Platform Support

| Platform | Architecture            | Supported                      |
|----------|-------------------------|--------------------------------|
| Linux    | x86_64                  | Yes                            |
| Linux    | aarch64                 | Yes                            |
| macOS    | x86_64 (Intel)          | Yes                            |
| macOS    | aarch64 (Apple Silicon) | Yes                            |
| Windows  | x86_64                  | Desired but not in scope yet   |

> Windows builds are desirable but skipped for the initial release due to SPC constraints.

---

## 7. PHP Extensions (Baseline)

Embedded into the binary by default. Extend as needed per command requirements:

```
bcmath, ctype, curl, dom, fileinfo, filter, iconv,
mbstring, openssl, pcntl, pdo, phar, posix, readline,
simplexml, tokenizer, xml, xmlreader, xmlwriter,
zip, zlib, sodium
```

---

## 8. GitHub Actions Workflows

### 8.1 Triggers

| Event               | Workflow      | Action                                  |
|---------------------|---------------|-----------------------------------------|
| Push to `main`      | `tests.yml`   | Run full test suite                     |
| PR to `main`        | `tests.yml`   | Run full test suite                     |
| Push of tag `v*`    | `build.yml`   | Run tests → build binaries → publish release |

### 8.2 Matrix Strategy (`build.yml`)

| Runner               | SPC Binary            | Output Binary          |
|----------------------|-----------------------|------------------------|
| `ubuntu-latest`      | `spc-linux-x86_64`    | `clonio-linux-x86_64`  |
| `ubuntu-24.04-arm`   | `spc-linux-aarch64`   | `clonio-linux-aarch64` |
| `macos-latest`       | `spc-macos-aarch64`   | `clonio-macos-aarch64` |
| `macos-13`           | `spc-macos-x86_64`    | `clonio-macos-x86_64`  |

### 8.3 Workflow Steps (`build.yml`, per runner)

1. Checkout repository (`fetch-depth: 0` for git version resolution)
2. Set up PHP 8.5 via `shivammathur/setup-php`
3. Install Composer dependencies (`--no-dev`)
4. Build PHAR via `php artisan app:build clonio --no-interaction`
5. Download SPC binary from GitHub Releases
6. Download PHP 8.5 sources + extensions (`spc download`)
7. Compile PHP micro SAPI (`spc build --build-micro`)
8. Combine PHAR + phpmicro → standalone binary (`spc micro:combine`)
9. Smoke test: `./clonio-<platform> --version`
10. Upload binary as GitHub Release asset (`softprops/action-gh-release`)

### 8.4 Caching

| Cache               | Key                                             |
|---------------------|-------------------------------------------------|
| Composer deps       | `{OS}-composer-{composer.lock hash}`            |
| Rector              | `{OS}-rector-{composer.lock hash}`              |
| PHPStan             | `{OS}-phpstan-{composer.lock hash}`             |
| SPC downloaded sources | `spc-downloads-{OS}-php8.5-v1`              |
| SPC build output    | `spc-build-{OS}-php8.5-v1`                      |

> Bump the `-v1` suffix in cache keys when changing the extensions list or PHP version.

---

## 9. Commands

### 9.1 Implemented

| Command | Description |
|---------|-------------|
| `about` | Displays Clonio logo (with shadow) and a short product description |

### 9.2 Planned

| Command    | Description |
|------------|-------------|
| `init`     | Initializes a local config file (JSON format) in the current directory |
| `validate` | Validates a given config file against the expected schema |
| `transfer` | Main command — executes the DB cloning process (anonymization, fake data, audit trail) |
| `version`  | Checks whether the currently installed binary is up to date with GitHub Releases |

### 9.3 Command Infrastructure

- Commands are placed under `app/Commands/`
- Auto-discovered by Laravel Zero via `AppServiceProvider`
- Consistent error handling via Collision integration
- Optional components installable via `php artisan app:install` (Eloquent, Logging, HTTP Client, etc.)

---

## 10. Quality Assurance

| Area             | Measure                                              | Status |
|------------------|------------------------------------------------------|--------|
| Tests            | PestPHP — parallel, runs in CI before every build    | Active |
| Unit coverage    | Min 85% (`pest --coverage --min=85`)                 | Active |
| Type coverage    | Min 90% (`pest --type-coverage --min=90`)            | Active |
| Static analysis  | PHPStan level `max` via Larastan + bleedingEdge      | Active |
| Code style       | Laravel Pint (parallel)                              | Active |
| Refactoring lint | Rector (Laravel ruleset + prepared sets)             | Active |
| Binary smoke test | `./clonio-<platform> --version` in CI after build   | Active |

---

## 11. Versioning & Releases

- Semantic versioning: `MAJOR.MINOR.PATCH`
- Application version is resolved from git tags at runtime (`resolve('git.version')` in `config/app.php`)
- Git tags (`v1.0.0`) trigger the build/release workflow
- GitHub Releases include all four platform binaries

---

## 12. Open Questions

- [ ] **`version` command:** Should it compare against GitHub Releases API, or a separate version endpoint?
- [ ] **`init` command:** What fields does the JSON config contain? (connection strings, anonymization rules, target environments, etc.)
- [ ] **`transfer` command scope:** Sync schema only, data only, or both? Incremental or full clone?
- [ ] **Windows support:** Revisit after initial release — evaluate SPC Windows build support at that point
