#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

# shellcheck source=scripts/lib/cargo-llvm-cov.sh
source scripts/lib/cargo-llvm-cov.sh
mkdir -p .ci-coverage/runtime/tests
llvm_cov_begin "${RUNNER_TEMP:-$repo_root/.ci-results}/runtime-rust-coverage-env.sh"

SPACEFAST_BUN_COVERAGE_DIR="$repo_root/.ci-coverage/runtime/tests" \
  bash runtime/tests/run.sh

llvm_cov_report .ci-coverage/crates/runtime-integration/lcov.info
test -s .ci-coverage/runtime/tests/lcov.info

# The entrypoint guard below only works over PHP request coverage. CI must
# never skip it silently — a runner without pcov would otherwise let a new
# entrypoint ship routed everywhere and probed nowhere.
if [[ -z "${SPACEFAST_PHP_COVERAGE_DIR:-}" ]]; then
  if [[ -n "${CI:-}" ]]; then
    echo "SPACEFAST_PHP_COVERAGE_DIR is unset: CI runs require PHP coverage so the entrypoint guard executes" >&2
    exit 1
  fi
else
  php_source_index=".ci-coverage/runtime/php-sources.txt"
  find .ci-coverage/runtime/php -name lcov.info -size +0c \
    -exec grep -h '^SF:' {} + | sort -u > "$php_source_index"
  # Every runtime entrypoint must be exercised by a real request, so a new one
  # cannot be routed everywhere and probed nowhere.
  # BEGIN GENERATED runtime entrypoints — DO NOT EDIT
  # Source: runtime/engine-manifest.json (aliases under __spacefast/).
  # Regenerate: bun run check:runtime-entrypoints -- --write
  entrypoint_sources=(
    runtime/custom-redirects.php
  )
  # END GENERATED runtime entrypoints
  # Serving files every public request traverses. They are not entrypoints, but
  # they are just as load-bearing.
  for source in "${entrypoint_sources[@]}" \
    runtime/engine/runtime/spacefast-sdk.php \
    runtime/engine/runtime/zero-routes.php; do
    grep -Fqx "SF:$repo_root/$source" "$php_source_index"
  done
fi
