#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT"

usage() {
  cat <<'EOF'
Usage: build/bootstrap-release-env.sh [--check|--generate-locks|--install|--prepare]

  --check          Verify release tools, PHP extensions and committed lockfiles.
  --generate-locks Generate composer.lock and package-lock.json transactionally.
  --install        Install locked PHP/Node dependencies and Playwright Chromium.
  --prepare        Generate missing locks, then install dependencies.
EOF
}

fail() {
  printf 'RouteMaps release environment error: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

check_php_extensions() {
  local missing=()
  local ext
  for ext in dom zip json mbstring; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing+=("$ext")
  done
  if ((${#missing[@]})); then
    fail "missing PHP extensions: ${missing[*]}"
  fi
}

check_tools() {
  local command_name
  for command_name in php composer node npm rsync zip unzip git; do
    require_command "$command_name"
  done
  check_php_extensions
}

check_locks() {
  [[ -f composer.lock ]] || fail 'composer.lock is missing; run --generate-locks in a networked environment'
  [[ -f package-lock.json ]] || fail 'package-lock.json is missing; run --generate-locks in a networked environment'
}

generate_locks() {
  check_tools

  local tmp
  tmp="$(mktemp -d)"
  trap 'rm -rf "${tmp:-}"' EXIT
  cp composer.json package.json "$tmp/"

  (
    cd "$tmp"
    composer update --no-install --no-interaction --no-progress --prefer-dist
    npm install --package-lock-only --ignore-scripts --no-audit --no-fund
  )

  [[ -s "$tmp/composer.lock" ]] || fail 'Composer did not create composer.lock'
  [[ -s "$tmp/package-lock.json" ]] || fail 'npm did not create package-lock.json'

  # Copy only after both managers have completed successfully. A failed second
  # resolver therefore never leaves a half-updated dependency state behind.
  cp "$tmp/composer.lock" "$ROOT/composer.lock"
  cp "$tmp/package-lock.json" "$ROOT/package-lock.json"
  printf 'Generated composer.lock and package-lock.json. Review and commit both files together.\n'

  rm -rf "$tmp"
  trap - EXIT
}

install_locked_dependencies() {
  check_tools
  check_locks
  composer install --no-interaction --no-progress --prefer-dist
  npm ci --no-audit --no-fund
  npx playwright install chromium
}

mode="${1:---check}"
case "$mode" in
  --check)
    check_tools
    check_locks
    printf 'RouteMaps release environment prerequisites are available.\n'
    ;;
  --generate-locks)
    generate_locks
    ;;
  --install)
    install_locked_dependencies
    ;;
  --prepare)
    check_tools
    if [[ ! -f composer.lock || ! -f package-lock.json ]]; then
      generate_locks
    fi
    install_locked_dependencies
    ;;
  -h|--help)
    usage
    ;;
  *)
    usage >&2
    exit 2
    ;;
esac
