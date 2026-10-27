#!/bin/sh
#
# Entrypoint of the `dev` image stage. Runs the given command as the owner of
# the bind-mounted repository, so files created in the container (vendor/,
# caches, coverage) belong to the host user on every platform:
#
#   Linux (rootful Docker)           — host uid/gid, e.g. 1000:1000
#   macOS (Docker Desktop, OrbStack) — host uid/gid, e.g. 501:20
#   Windows (WSL2 filesystem)        — WSL user, usually 1000:1000
#   rootless Docker/Podman, Windows drive mounts
#                                    — 0:0: stay root, which maps back to the
#                                      host user there
#
set -e

uid=$(stat -c %u /app)
gid=$(stat -c %g /app)

if [ "$uid" = 0 ]; then
    exec "$@"
fi

if ! getent group "$gid" > /dev/null; then
    addgroup -g "$gid" dev
fi
group=$(getent group "$gid" | cut -d: -f1)

if ! getent passwd "$uid" > /dev/null; then
    adduser -D -u "$uid" -G "$group" -h /home/dev -s /bin/sh dev
fi
user=$(getent passwd "$uid" | cut -d: -f1)

# Named volume — created root-owned on first use.
mkdir -p "$COMPOSER_CACHE_DIR"
chown "$uid:$gid" "$COMPOSER_CACHE_DIR"

exec su-exec "$user:$group" "$@"
