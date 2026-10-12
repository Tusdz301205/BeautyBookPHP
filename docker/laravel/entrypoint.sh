#!/bin/sh
set -eu

# Named volumes survive rebuilds. Update them when the dependency lock changes.
lock_hash=$(sha256sum composer.lock | cut -d ' ' -f 1)
installed_hash=$(cat vendor/.beautybook-lock 2>/dev/null || true)
if [ "$lock_hash" != "$installed_hash" ]; then
    composer install --prefer-dist --no-interaction --no-scripts
    # Laravel clears old provider manifests before discovering the installed packages.
    composer run-script post-autoload-dump --no-interaction
    printf '%s\n' "$lock_hash" > vendor/.beautybook-lock
fi

exec docker-php-entrypoint "$@"
