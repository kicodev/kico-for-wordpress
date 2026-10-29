#!/bin/sh
# Builds kico-for-wordpress.zip with the bundled MCP Adapter and the
# namespaced SQL parser (sql-parser/build.sh): composer and php-scoper run in
# Docker when they are not installed, so no PHP is needed here.
#   wordpress/build.sh [out-dir]   (default: dist/downloads)
# The zip holds what runs and the licenses, nothing else: no Composer files,
# tools (bin/), docs, tests or dotfiles, and vendor/ refuses web requests.
# It writes an unsigned manifest (the version only, which offers no update);
# signed releases come from wordpress/release.ts.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
out=${1:-"$here/../dist/downloads"}
mkdir -p "$out"
out=$(cd "$out" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cp -r "$here/kico-for-wordpress" "$work/"
p="$work/kico-for-wordpress"
rm -rf "$p/vendor" "$p/vendor-scoped"
"$here/sql-parser/build.sh" "$p/vendor-scoped/sql-parser" >/dev/null
if command -v composer >/dev/null 2>&1; then
  (cd "$p" && composer install --no-dev --no-interaction --quiet --optimize-autoloader)
else
  docker run --rm -u "$(id -u):$(id -g)" -v "$p:/app" -e COMPOSER_HOME=/tmp composer:2 install --no-dev --no-interaction --quiet --optimize-autoloader
fi

# Hygiene: only code and licenses.
rm -f "$p/composer.json" "$p/composer.lock" "$p/.gitignore"
find "$p/vendor" -depth \( -type d \( -name bin -o -name docs -o -name doc -o -name generator -o -name tests -o -name test -o -name .github \) \) -exec rm -rf {} +
find "$p" \( -name '.*' ! -name '.htaccess' ! -name '.' \) -exec rm -rf {} +
find "$p/vendor" -type f \( -name '*.md' ! -iname 'LICENSE*' \) -delete
find "$p/vendor" -type f \( -name 'phpunit.xml*' -o -name 'phpcs.xml*' -o -name 'phpstan.neon*' -o -name 'psalm.xml*' -o -name 'composer.json' -o -name 'composer.lock' -o -name 'package.json' -o -name 'Makefile' \) -delete
# Direct requests to vendor/ get nothing: Apache refuses them, and every folder has an empty index.
cat > "$p/vendor/.htaccess" <<'HT'
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>
HT
# The scoped SQL parser likewise: its files only declare classes, but nothing there is for the web.
cp "$p/vendor/.htaccess" "$p/vendor-scoped/.htaccess"
find "$p" -type d -exec sh -c 'test -e "$1/index.php" || printf "<?php\n// Silence.\n" > "$1/index.php"' _ {} \;

# The content check: nothing that should not ship.
bad=$(cd "$work" && find kico-for-wordpress | grep -E '(^|/)(bin|docs?|tests?|generator|\.github)(/|$)|/composer\.(json|lock)$|/\.git|\.md$' | grep -viE '/LICENSE[^/]*$' || true)
if [ -n "$bad" ]; then
  echo "kico-for-wordpress.zip would have files it should not ship:" >&2
  echo "$bad" >&2
  exit 1
fi
version=$(sed -n 's/^ \* Version: *//p' "$p/kico-for-wordpress.php" | head -1)
(cd "$work" && rm -f "$out/kico-for-wordpress.zip" && zip -qrX "$out/kico-for-wordpress.zip" kico-for-wordpress)
# A manifest without a signature: sites offer no update from it. wordpress/release.ts writes the signed one.
printf '{"version":"%s"}\n' "$version" > "$out/kico-for-wordpress.json"
echo "$out/kico-for-wordpress.zip ($version)"
