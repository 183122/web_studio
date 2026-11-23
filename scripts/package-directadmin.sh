#!/usr/bin/env bash
# Builds a ZIP for DirectAdmin/cPanel shared hosting:
#   ai-studio/    → upload to domains/<domain>/ai-studio   (Laravel app, vendor, shared packages)
#   public_html/  → upload into domains/<domain>/public_html
# Usage: scripts/package-directadmin.sh [output-dir]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/build}"
STAGE="$OUT/directadmin"
VERSION="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo dev)"

rm -rf "$STAGE" && mkdir -p "$STAGE/ai-studio" "$STAGE/public_html"

echo "==> Building studio and runtime bundles"
(cd "$ROOT" && npm run build -w apps/studio-web >/dev/null && npm run build -w apps/runtime-web >/dev/null)

echo "==> Copying application (without tests, dev files, secrets)"
API="$ROOT/apps/platform-api"
tar -C "$API" -cf - \
  --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./public \
  --exclude=./.env --exclude='./.env.*' --exclude='./database/*.sqlite' --exclude='./database/e2e-dns.json' \
  --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' --exclude='./storage/framework/testing' --exclude='./storage/app/tenant-assets/*' \
  --exclude='./storage/app/private/*' --exclude='./storage/app/public/*' \
  --exclude='./bootstrap/cache/*.php' --exclude=./phpunit.xml --exclude=./.phpunit.result.cache \
  . | tar -C "$STAGE/ai-studio" -xf -
mkdir -p "$STAGE/ai-studio/storage/"{logs,app/tenant-assets,app/private,app/public,framework/{cache/data,sessions,views}} "$STAGE/ai-studio/bootstrap/cache"
cp "$ROOT/deploy/directadmin/env.directadmin" "$STAGE/ai-studio/.env.directadmin"
# Defence in depth if the app folder is ever placed under a web root by mistake.
printf '<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n' > "$STAGE/ai-studio/.htaccess"

echo "==> Shared packages (schema, component registry, templates)"
mkdir -p "$STAGE/ai-studio/packages/app-schema" "$STAGE/ai-studio/packages/component-registry" "$STAGE/ai-studio/packages/templates"
cp -r "$ROOT/packages/app-schema/schema" "$STAGE/ai-studio/packages/app-schema/"
cp "$ROOT/packages/component-registry/registry.json" "$STAGE/ai-studio/packages/component-registry/"
cp -r "$ROOT/packages/templates/dist" "$STAGE/ai-studio/packages/templates/"

echo "==> Production dependencies (composer --no-dev)"
(cd "$STAGE/ai-studio" && COMPOSER_MAX_PARALLEL_HTTP=4 composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --no-scripts >/dev/null)
(cd "$STAGE/ai-studio" && php artisan package:discover >/dev/null)
find "$STAGE/ai-studio/vendor" -type d -name .git -prune -exec rm -rf {} +

echo "==> public_html (studio SPA + runtime bundle + front controller)"
cp -r "$ROOT/apps/studio-web/dist/." "$STAGE/public_html/"
cp -r "$API/public/runtime" "$STAGE/public_html/runtime"
for f in favicon.ico robots.txt; do [ -f "$API/public/$f" ] && cp "$API/public/$f" "$STAGE/public_html/"; done
find "$STAGE/public_html" -name '*.map' -delete
cp "$ROOT/deploy/directadmin/index.php" "$STAGE/public_html/index.php"
cp "$ROOT/deploy/directadmin/htaccess" "$STAGE/public_html/.htaccess"

cp "$ROOT/deploy/directadmin/DEPLOY-TH.md" "$STAGE/DEPLOY-TH.md"
echo "$VERSION" > "$STAGE/ai-studio/VERSION"

echo "==> Zipping"
ZIP="$OUT/ai-studio-cloud-directadmin-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr -X "$ZIP" ai-studio public_html DEPLOY-TH.md)
du -h "$ZIP"
echo "$ZIP"
