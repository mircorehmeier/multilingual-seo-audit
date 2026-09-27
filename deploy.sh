#!/bin/sh
set -eu

APP_DIR="${APP_DIR:-$HOME/seo-audit}"
cd "$APP_DIR"

echo "==> multilingual-seo-audit Laravel deploy"
echo "==> Working directory: $(pwd)"

PHP_BIN=""

for VERSION in 8.5 8.4; do
    CANDIDATE="/opt/plesk/php/$VERSION/bin/php"
    if [ -x "$CANDIDATE" ]; then
        PHP_BIN="$CANDIDATE"
        break
    fi
done

if [ -z "$PHP_BIN" ] && command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
fi

if [ -z "$PHP_BIN" ]; then
    echo "ERROR: PHP 8.3+ was not found."
    exit 1
fi

echo "==> PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' || {
    echo "ERROR: This Laravel build requires PHP 8.4.1 or newer."
    exit 1
}

COMPOSER_PHAR=""
for CANDIDATE in     /usr/lib/plesk-9.0/composer.phar     /usr/lib64/plesk-9.0/composer.phar     /usr/local/psa/var/modules/composer/composer.phar
do
    if [ -f "$CANDIDATE" ]; then
        COMPOSER_PHAR="$CANDIDATE"
        break
    fi
done

if [ -z "$COMPOSER_PHAR" ]; then
    echo "ERROR: Plesk Composer could not be found."
    exit 1
fi

if [ ! -f .env ]; then
    cp .env.example .env
    echo "==> Created .env from .env.example"
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rw storage bootstrap/cache || true

echo "==> Installing Composer dependencies"
"$PHP_BIN" -d memory_limit=1024M "$COMPOSER_PHAR" install     --no-dev     --prefer-dist     --no-interaction     --no-progress     --optimize-autoloader

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    echo "==> Generating Laravel application key"
    "$PHP_BIN" artisan key:generate --force
fi

echo "==> Clearing old caches"
"$PHP_BIN" artisan optimize:clear

echo "==> Caching production configuration and views"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache

COMMIT_SHA="unknown"
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    COMMIT_SHA="$(git rev-parse --short HEAD)"
fi

printf 'ok - laravel 0.6.0\ncommit: %s\n' "$COMMIT_SHA" > public/deploy-status.txt

echo "==> Laravel deploy complete"
