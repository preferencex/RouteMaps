#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT"

command -v docker >/dev/null 2>&1 || { echo 'RouteMaps E2E requires Docker.' >&2; exit 1; }
[[ -d node_modules/@wordpress/env ]] || { echo 'Run npm ci before RouteMaps E2E.' >&2; exit 1; }

WP_ENV_HOME="${WP_ENV_HOME:-$RUNNER_TEMP/routemaps-wp-env}"
export WP_ENV_HOME
ENV_FILE="${ROUTEMAPS_E2E_ENV_FILE:-$RUNNER_TEMP/routemaps-e2e.env}"
mkdir -p "$(dirname "$ENV_FILE")"

wpenv() {
  npm exec --no -- wp-env --config=.wp-env.e2e.json "$@"
}

cleanup() {
  wpenv stop >/dev/null 2>&1 || true
}
trap cleanup EXIT

wpenv start --update

# Pretty permalinks are required by the /routemaps/* viewer rewrites.
wpenv run cli wp rewrite structure '/%postname%/'
wpenv run cli wp rewrite flush --hard

seed_output="$(wpenv run cli wp eval-file wp-content/plugins/routemaps/tests/E2E/ci-seed.php)"
printf '%s\n' "$seed_output" | grep '^ROUTEMAPS_E2E_' > "$ENV_FILE"
[[ -s "$ENV_FILE" ]] || { echo 'RouteMaps E2E fixture seed did not produce environment variables.' >&2; exit 1; }

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

CI=true npx playwright test
