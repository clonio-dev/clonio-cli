# Composer Distribution (Dev Dependency)

Clonio CLI is published on [Packagist](https://packagist.org/packages/clonio-dev/clonio-cli) and can be installed as a Composer dev dependency in any PHP project.

## Installation

```bash
composer require --dev clonio-dev/clonio-cli
```

This makes `vendor/bin/clonio` available with the exact release version:

```bash
vendor/bin/clonio --version
# Clonio v1.2.3
```

## Version Resolution

When Clonio runs from `vendor/bin/clonio`, it determines its version using the following chain:

1. **VERSION file** — baked into standalone binaries and PHARs at build time; not present in the Composer-installed package.
2. **`git describe`** — works during local development in the cloned repository; not available inside `vendor/`.
3. **Composer `InstalledVersions`** — reads the version from `composer.lock` / `installed.php`. This is the mechanism that provides the version when installed as a dependency.

Because the first two sources are unavailable inside a `vendor/` installation, the version always comes from Composer's own metadata — which matches the git tag used to release the package.

## How It Works

The `bin` entry in `composer.json` points to the `clonio` PHP script in the repository root:

```json
{
  "bin": ["clonio"]
}
```

When Composer installs the package it creates a `vendor/bin/clonio` proxy script that executes this file. The `clonio` entry point detects whether it's running from a project root or from inside `vendor/` and loads the correct autoloader:

```php
require file_exists(__DIR__.'/vendor/autoload.php')
    ? __DIR__.'/vendor/autoload.php'
    : __DIR__.'/../../autoload.php';
```

No standalone binary or PHAR is involved — the CLI runs as plain PHP using the host project's Composer autoloader.

## PHP Version Requirement

Clonio CLI requires **PHP ≥ 8.5**. Verify your PHP version:

```bash
php --version
```

## CI Usage

### GitHub Actions

```yaml
- name: Install Clonio
  run: composer require --dev clonio-dev/clonio-cli

- name: Clone staging database
  run: vendor/bin/clonio cloning:run production.cloning.yaml --target staging --ci
  env:
    APP_KEY: ${{ secrets.CLONIO_APP_KEY }}
```

### GitLab CI

```yaml
cloning:
  image: php:8.5-cli
  before_script:
    - composer install --no-interaction
  script:
    - vendor/bin/clonio cloning:run production.cloning.yaml --target staging --ci
```

## Updating

```bash
composer update clonio-dev/clonio-cli
vendor/bin/clonio --version
```

Composer will pull the latest version that satisfies your version constraint. The `--version` output reflects the new release.

## Comparison with Standalone Binaries

| Method | Requires PHP | Self-contained | Version source |
|--------|-------------|----------------|----------------|
| Standalone binary | No | Yes | VERSION file (baked in) |
| PHAR | PHP 8.5 | Yes | VERSION file (baked in) |
| Composer package | PHP 8.5 | No (needs `composer install`) | Composer `InstalledVersions` |

Platform-specific standalone binaries (`clonio-linux-x86_64`, etc.) are built via GitHub Actions and attached to [releases](https://github.com/clonio-dev/clonio-cli/releases/latest). They are **not** committed to git and are not available via Composer.

## Packagist Auto-Update

The build workflow (`.github/workflows/build.yml`) automatically notifies Packagist when a new release is published. This requires two repository secrets:

| Secret | Description |
|--------|-------------|
| `PACKAGIST_USERNAME` | Your Packagist username |
| `PACKAGIST_API_TOKEN` | API token from [Packagist → Settings → API Token](https://packagist.org/profile/) |

If the secrets are not configured, the Packagist notification step will fail silently and Packagist will rely on its periodic polling (which can take up to 12 hours).

Alternatively, configure a [GitHub webhook](https://packagist.org/about#how-to-update-packages) on the repository for instant updates without relying on the workflow.
