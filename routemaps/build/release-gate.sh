#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT"

fail() {
  printf 'RouteMaps release gate error: %s\n' "$*" >&2
  exit 1
}

bash build/bootstrap-release-env.sh --check

if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
  fail 'release gate requires a clean Git worktree; commit or remove local changes first'
fi

composer quality
npm test -- --run
npm run build
if [[ -n "${CI:-}" ]]; then
  bash build/ci-e2e.sh
else
  npx playwright test
fi
bash build/package.sh

archive="$(find dist -maxdepth 1 -type f -name 'routemaps-*.zip' -print | LC_ALL=C sort | tail -n1)"
[[ -n "$archive" && -f "$archive" ]] || fail 'release ZIP was not created'

sha="$(sha256sum "$archive" | awk '{print $1}')"
commit="$(git rev-parse HEAD)"
printf 'RouteMaps release gate PASS\n'
printf 'Commit: %s\n' "$commit"
printf 'Archive: %s\n' "$archive"
printf 'SHA256: %s\n' "$sha"
