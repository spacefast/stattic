#!/usr/bin/env bash
# Runs the runtime browser proof. Turbo's ci:runtime-browser calls it directly:
# spacefast#generate restored the skill artifacts, and ci:runtime-prepare built
# the native runtime and the Zero dashboard in this checkout. Outside CI it
# builds both itself, so a local run never reuses a stale dashboard.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

cargo_target_dir="${CARGO_TARGET_DIR:-$repo_root/target}"
if [[ "$cargo_target_dir" != /* ]]; then
  cargo_target_dir="$repo_root/$cargo_target_dir"
fi
if [[ -n "${CI:-}" ]]; then
  # This task runs beside the integration suite, which reports coverage from the
  # instrumented binary ci:runtime-prepare built. Never rebuild it here: a plain
  # cargo build could replace it uninstrumented while the suite still runs it.
  [[ -x "$cargo_target_dir/debug/stattic-runtime" ]] || {
    echo "ci:runtime-prepare did not build stattic-runtime" >&2
    exit 1
  }
  # Keep this run's Rust profiles and PHP coverage apart from the suite's, so
  # the suite's report and PHP entrypoint guard read only its own requests.
  profile_dir="$(mktemp -d)"
  export LLVM_PROFILE_FILE="$profile_dir/browser-%p-%m.profraw"
  export SPACEFAST_PHP_COVERAGE_DIR="$repo_root/.ci-coverage/runtime/php-browser"
  mkdir -p "$SPACEFAST_PHP_COVERAGE_DIR"
else
  cargo build --locked -p stattic-runtime-compiler --bin stattic-runtime
fi

# CI starts from a fresh checkout, so an existing dashboard came from
# ci:runtime-prepare in this run. Locally the gitignored directory can be stale
# or half-written.
if [[ -z "${CI:-}" || ! -f runtime/wordpress/zero-dashboard/zero-dashboard.php ]]; then
  bun zero/scripts/build.ts >/dev/null
fi

database_url="${SPACEFAST_TEST_DATABASE_ADMIN_URL:-postgres://stattic:stattic@127.0.0.1:25432/stattic}"
DATABASE_URL="$database_url" bun run --cwd apps/control-plane db:migrate
SPACEFAST_RUNTIME_BIN="${SPACEFAST_RUNTIME_BIN:-$cargo_target_dir/debug/stattic-runtime}" \
  SPACEFAST_TEST_DATABASE_URL="$database_url" \
  SPACEFAST_TEST_REDIS_URL="${SPACEFAST_TEST_REDIS_ADMIN_URL:-redis://127.0.0.1:26379}" \
  bun runtime/browser-specs/run.ts

if [[ -n "${CI:-}" ]]; then
  # The proof's PHP coverage must land in its own directory, not in the one the
  # suite's entrypoint guard reads. Fail if it went anywhere else.
  browser_shard="$(find "$SPACEFAST_PHP_COVERAGE_DIR" -name lcov.info -size +0c \
    -exec grep -Fqx "SF:$repo_root/runtime/custom-redirects.php" {} \; -print -quit)"
  [[ -n "$browser_shard" ]] || {
    echo "browser proof PHP coverage is missing from $SPACEFAST_PHP_COVERAGE_DIR" >&2
    exit 1
  }
fi
