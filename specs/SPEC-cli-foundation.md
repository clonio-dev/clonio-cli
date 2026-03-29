# Clonio CLI — Foundation Specification

**Version:** 0.3
**Status:** Active
**Date:** 2026-03-29

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
├── specs/                  # Specification documents
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
./clonio app:build clonio        → builds/clonio (PHAR)
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

| Platform | Architecture            | Supported                    |
|----------|-------------------------|------------------------------|
| Linux    | x86_64                  | Yes                          |
| Linux    | aarch64                 | Yes                          |
| macOS    | x86_64 (Intel)          | Yes                          |
| macOS    | aarch64 (Apple Silicon) | Yes                          |
| Windows  | x86_64                  | Desired — out of scope for now |

> Windows builds are desirable but skipped for the initial release due to SPC constraints.

---

## 7. PHP Extensions (Baseline)

Embedded into the binary by default. Extend per command as needed:

```
ctype, curl, filter, iconv, mbstring, openssl, pcntl,
pdo, pdo_mysql, pdo_pgsql, pdo_sqlite,
phar, readline, sqlite3, tokenizer, zlib
```

> YAML and logging (Monolog) are handled by pure-PHP Composer packages (`symfony/yaml`, Monolog) — no C extensions required.

---

## 8. GitHub Actions Workflows

### 8.1 Triggers

| Event            | Workflow    | Action                                       |
|------------------|-------------|----------------------------------------------|
| Push to `main`   | `tests.yml` | Run full test suite                          |
| PR to `main`     | `tests.yml` | Run full test suite                          |
| Push of tag `v*` | `build.yml` | Run tests → build binaries → publish release |

### 8.2 Matrix Strategy (`build.yml`)

| Runner             | SPC Binary          | Output Binary          |
|--------------------|---------------------|------------------------|
| `ubuntu-latest`    | `spc-linux-x86_64`  | `clonio-linux-x86_64`  |
| `ubuntu-24.04-arm` | `spc-linux-aarch64` | `clonio-linux-aarch64` |
| `macos-latest`     | `spc-macos-aarch64` | `clonio-macos-aarch64` |
| `macos-13`         | `spc-macos-x86_64`  | `clonio-macos-x86_64`  |

### 8.3 Workflow Steps (`build.yml`, per runner)

1. Checkout repository (`fetch-depth: 0` for git version resolution)
2. Set up PHP 8.5 via `shivammathur/setup-php`
3. Install Composer dependencies (`--no-dev`)
4. Build PHAR via `./clonio app:build clonio --no-interaction`
5. Download SPC binary from GitHub Releases
6. Download PHP 8.5 sources + extensions (`spc download`)
7. Compile PHP micro SAPI (`spc build --build-micro`)
8. Combine PHAR + phpmicro → standalone binary (`spc micro:combine`)
9. Smoke test: `./clonio-<platform> --version`
10. Upload binary as GitHub Release asset (`softprops/action-gh-release`)

### 8.4 Caching

| Cache                  | Key                                  |
|------------------------|--------------------------------------|
| Composer deps          | `{OS}-composer-{composer.lock hash}` |
| Rector                 | `{OS}-rector-{composer.lock hash}`   |
| PHPStan                | `{OS}-phpstan-{composer.lock hash}`  |
| SPC downloaded sources | `spc-downloads-{OS}-php8.5-v1`       |
| SPC build output       | `spc-build-{OS}-php8.5-v1`           |

> Bump the `-v1` suffix when changing the extensions list or PHP version.

---

## 9. Command Infrastructure

- Commands are placed under `app/Commands/`
- Auto-discovered by Laravel Zero via `AppServiceProvider`
- Consistent error handling via Collision integration
- Optional components installable via `php artisan app:install` (Eloquent, Logging, HTTP Client, etc.)
- Individual commands are specified in their own SPEC files under `specs/`

---

## 10. Quality Assurance

| Area              | Measure                                            | Status |
|-------------------|----------------------------------------------------|--------|
| Tests             | PestPHP — parallel, runs in CI before every build  | Active |
| Unit coverage     | Min 85% (`pest --coverage --min=85`)               | Active |
| Type coverage     | Min 90% (`pest --type-coverage --min=90`)          | Active |
| Static analysis   | PHPStan level `max` via Larastan + bleedingEdge    | Active |
| Code style        | Laravel Pint (parallel)                            | Active |
| Refactoring lint  | Rector (Laravel ruleset + prepared sets)           | Active |
| Binary smoke test | `./clonio-<platform> --version` after each build   | Active |

---

## 11. Versioning & Releases

- Semantic versioning: `MAJOR.MINOR.PATCH`
- Application version resolved from git tags at runtime (`resolve('git.version')` in `config/app.php`)
- Git tags (`v1.0.0`) trigger the build/release workflow
- GitHub Releases include all four platform-specific binaries

---

## 12. Open Questions

- [ ] **Windows support:** Revisit after initial release — evaluate SPC Windows build support at that point
