# syntax=docker/dockerfile:1.7
#
# Source-based, multi-stage image. Same Dockerfile drives `make docker-build`
# locally and the multi-arch publish in CI. No static-binary dependency, so the
# docker job can run in parallel with the platform binary builds.
#
# Stage 1 (build) installs composer + git + unzip to resolve vendor; only the
# /app tree (source + slimmed vendor) is carried into the runtime image.
#
# Layout inside the final image:
#   /app         — application source + vendor (read-only at runtime)
#   /workspace   — WORKDIR; user mounts their project here so clonio.json /
#                  .env / .cloning.yaml resolve against getcwd()
#

# ── Stage 1: build vendor ────────────────────────────────────────────────────
FROM php:8.5-cli-alpine AS build

RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install prod deps first against lockfile only so the layer cache survives source edits.
# --no-scripts: skip Laravel package discovery until source is in place.
# --no-autoloader: defer optimized autoload until after COPY . so app/ is mapped.
COPY composer.json composer.lock ./
# --ignore-platform-reqs: composer install only unpacks files; the runtime
# stage installs gd / pcntl / pdo_mysql / pdo_pgsql. Skipping the platform
# check here keeps the build stage free of PHP extension libraries.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-cache --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Strip vendor dead weight (~10-30MB): per-package tests/docs trees and dev-only
# loose files. We keep .php and bundled assets; only artifacts a CLI never reads
# at runtime are removed.
RUN set -eux; \
    find vendor -type d \( \
            -name tests -o -name Tests -o -name test \
         -o -name docs  -o -name doc   -o -name examples \
         -o -name .github \
        \) -prune -exec rm -rf {} +; \
    find vendor -type f \( \
            -name '*.md' -o -name '*.dist' \
         -o -name 'phpunit.xml*' \
         -o -name '.gitignore' -o -name '.gitattributes' -o -name '.editorconfig' \
        \) -delete; \
    # mpdf bundles ~87MB of TTF/OTF font families. Audit PDF renderer only uses
    # the DejaVu family (see App\Services\Audit\AuditLogRenderer::renderPdf).
    # Keep DejaVu* + the licence/info text files mpdf reads at runtime; drop the rest.
    find vendor/mpdf/mpdf/ttfonts -mindepth 1 \
        ! -name 'DejaVu*' \
        ! -name 'DejaVuinfo.txt' \
        -delete

# ── Stage 2: runtime ─────────────────────────────────────────────────────────
FROM php:8.5-cli-alpine

# ca-certificates — Guzzle-based audit adapters (S3, webhook, ntfy) need a CA bundle.
# tzdata — correct timestamps in audit logs and cloning dumps when TZ is set.
RUN apk add --no-cache ca-certificates tzdata

# Bundled in php:8.5-cli-alpine: ctype, curl, fileinfo, filter, iconv, mbstring,
# openssl, pdo, pdo_sqlite, phar, readline, session, sqlite3, tokenizer, zlib.
# install-php-extensions pulls in the matching system libs (libpng, libpq, etc.)
# and is removed after use to keep the runtime layer lean.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd pcntl pdo_mysql pdo_pgsql \
    && rm /usr/local/bin/install-php-extensions

COPY --from=build /app /app

WORKDIR /workspace
ENTRYPOINT ["php", "/app/clonio"]
