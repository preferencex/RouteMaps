#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
REPO_ROOT="$(cd "$ROOT/.." && pwd -P)"
required=(
  "$REPO_ROOT/.github/workflows/routemaps-release.yml"
  "$ROOT/.wp-env.e2e.json"
  "$ROOT/build/ci-e2e.sh"
  "$ROOT/tests/E2E/ci-seed.php"
  "$ROOT/tests/E2E/ci-mail-capture/ci-mail-capture.php"
)
for file in "${required[@]}"; do
  [[ -f "$file" ]] || { echo "missing:$file" >&2; exit 1; }
done

grep -Fq '11.15.0' "$ROOT/package.json" || { echo 'wp-env version not pinned' >&2; exit 1; }
grep -Fq 'woocommerce.10.3.5.zip' "$ROOT/.wp-env.e2e.json" || { echo 'WooCommerce version not pinned' >&2; exit 1; }
grep -Fq 'wordpress-6.8.3.zip' "$ROOT/.wp-env.e2e.json" || { echo 'WordPress version not pinned' >&2; exit 1; }
grep -Fq 'build/bootstrap-release-env.sh --generate-locks' "$REPO_ROOT/.github/workflows/routemaps-release.yml" || { echo 'lock generation missing' >&2; exit 1; }
grep -Fq 'build/release-gate.sh' "$REPO_ROOT/.github/workflows/routemaps-release.yml" || { echo 'release gate missing' >&2; exit 1; }
grep -Fq "'ci/**'" "$REPO_ROOT/.github/workflows/routemaps-release.yml" || { echo 'CI branch trigger missing' >&2; exit 1; }
grep -Fq 'build/ci-e2e.sh' "$ROOT/build/release-gate.sh" || { echo 'CI E2E runner not wired into release gate' >&2; exit 1; }

echo 'RELEASE CI SMOKE PASS'
