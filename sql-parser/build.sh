#!/usr/bin/env sh
# Builds vendor-scoped/sql-parser: phpMyAdmin's SQL parser (GPL-2.0-or-later)
# namespaced as Kico\Vendor\PhpMyAdmin\SqlParser with php-scoper, for
# row-level snapshots (includes/class-kico-sql.php).
#   wordpress/sql-parser/build.sh [out-dir]   (default: kico-for-wordpress/vendor-scoped/sql-parser)
# Needs PHP 8.2+ and Composer; without them it runs itself in composer:2.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
out=${1:-"$here/../kico-for-wordpress/vendor-scoped/sql-parser"}
mkdir -p "$out"
out=$(cd "$out" && pwd)
if ! command -v composer >/dev/null 2>&1 || ! php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
  exec docker run --rm -u "$(id -u):$(id -g)" -v "$here:/s" -v "$out:/out" -e COMPOSER_HOME=/tmp/composer composer:2 /s/build.sh /out
fi
export COMPOSER_HOME=${COMPOSER_HOME:-/tmp/composer-home}
cd "$here"
composer install --no-dev --no-interaction --quiet
scoper=$(command -v php-scoper || true)
if [ -z "$scoper" ]; then
  composer global require --no-interaction --quiet humbug/php-scoper:^0.18
  scoper="$COMPOSER_HOME/vendor/bin/php-scoper"
fi
# php-scoper replaces its output folder; build in a temporary one and copy.
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
"$scoper" add-prefix --config scoper.inc.php --output-dir "$tmp/out" --force --no-interaction --quiet
rm -rf "$out"/*
cp -R "$tmp/out/." "$out/"
test -f "$out/src/Parser.php"
echo "built $out"
