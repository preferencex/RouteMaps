#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT"

fail() {
  printf 'RouteMaps packaging error: %s\n' "$*" >&2
  exit 1
}

[[ -f composer.lock ]] || fail 'composer.lock is required for deterministic packaging'
[[ -f package-lock.json ]] || fail 'package-lock.json is required for deterministic packaging'

for command_name in composer npm rsync zip unzip; do
  command -v "$command_name" >/dev/null 2>&1 || fail "required command not found: $command_name"
done

SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || date +%s)}"
export SOURCE_DATE_EPOCH
php build/make-pot.php
[[ -f languages/routemaps.pot ]] || fail 'languages/routemaps.pot is required'

composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
npm ci
npm run build

[[ -f vendor/autoload.php ]] || fail 'vendor/autoload.php missing after Composer install'
[[ -f assets/admin/routemaps-admin.js ]] || fail 'admin JavaScript build missing'
[[ -f assets/admin/routemaps-admin.css ]] || fail 'admin CSS build missing'
[[ -f assets/pwa/service-worker.js ]] || fail 'service worker build missing'

viewer_manifest=''
for candidate in assets/viewer/.vite/manifest.json assets/viewer/manifest.json; do
  if [[ -f "$candidate" ]]; then
    viewer_manifest="$candidate"
    break
  fi
done
[[ -n "$viewer_manifest" ]] || fail 'viewer Vite manifest missing'

version="$(sed -nE 's/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p' routemaps.php | head -n1)"
[[ -n "$version" ]] || fail 'plugin version could not be read from routemaps.php'

rm -rf dist
mkdir -p dist
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/routemaps"

rsync -a --delete --exclude-from="$ROOT/.distignore" "$ROOT/" "$stage/routemaps/"

required_paths=(
  'routemaps/routemaps.php'
  'routemaps/uninstall.php'
  'routemaps/vendor/autoload.php'
  'routemaps/assets/admin/routemaps-admin.js'
  'routemaps/assets/admin/routemaps-admin.css'
  'routemaps/assets/pwa/service-worker.js'
  'routemaps/languages/routemaps.pot'
)
for required in "${required_paths[@]}"; do
  [[ -e "$stage/$required" ]] || fail "required release path missing: $required"
done

archive="$ROOT/dist/routemaps-$version.zip"
# ZIP cannot represent timestamps before 1980. Clamp while keeping release output stable.
zip_epoch="$SOURCE_DATE_EPOCH"
if (( zip_epoch < 315532800 )); then zip_epoch=315532800; fi
find "$stage/routemaps" -exec touch -h -d "@$zip_epoch" {} +
(
  cd "$stage"
  find routemaps -type f -print | LC_ALL=C sort | zip -X -q "$archive" -@
)

listing="$ROOT/dist/routemaps-$version.contents.txt"
unzip -l "$archive" | tee "$listing"

entries="$(unzip -Z1 "$archive")"
for forbidden in \
  '/tests/' \
  '/assets-src/' \
  '/node_modules/' \
  '/build/' \
  '/dist/' \
  '/.git/' \
  '/phpstan-stubs/' \
  '/playwright-report/' \
  '/test-results/' \
  '/coverage/' \
  '/.phpstan-cache' \
  '/.phpunit.result.cache'; do
  if grep -Fq "$forbidden" <<<"$entries"; then
    fail "development-only path found in archive: $forbidden"
  fi
done

for required in "${required_paths[@]}"; do
  grep -Fxq "$required" <<<"$entries" || fail "archive missing required path: $required"
done

grep -Eq '^routemaps/assets/viewer/.+\.js$' <<<"$entries" || fail 'archive missing built viewer JavaScript'
grep -Eq '^routemaps/assets/viewer/.+\.css$' <<<"$entries" || fail 'archive missing built viewer CSS'
grep -Eq '^routemaps/assets/viewer/(\.vite/)?manifest\.json$' <<<"$entries" || fail 'archive missing viewer manifest'

printf 'RouteMaps package created: %s\n' "$archive"
