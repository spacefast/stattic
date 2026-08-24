#!/usr/bin/env bash
# Single entry point for the runtime test suite:
#   1. php -l on every PHP file under runtime/
#   2. pure-function unit tests (php)
#   3. native runtime test-tool build (cargo)
#   4. HTTP integration tests against php -S fixture roots (bun)
set -euo pipefail

RUNTIME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$RUNTIME_DIR/.." && pwd)"

command -v php >/dev/null || { echo "php is required (8.5 with sodium, curl and zip)" >&2; exit 1; }
php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 ? 0 : 1);' || {
  echo "PHP 8.5.x is required; found $(php -r 'echo PHP_VERSION;')" >&2
  exit 1
}
command -v bun >/dev/null || { echo "bun is required" >&2; exit 1; }
command -v cargo >/dev/null || { echo "cargo is required" >&2; exit 1; }

echo "==> php -l (all runtime PHP files)"
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || exit 1
done < <(find "$RUNTIME_DIR" -name '*.php' -print0)

echo "==> php unit tests"
php "$RUNTIME_DIR/tests/unit.php"

echo "==> native runtime test tools"
cd "$REPO_ROOT"
cargo build --locked -p stattic-runtime-compiler --bin stattic-runtime
runtime_target_dir="${CARGO_TARGET_DIR:-$REPO_ROOT/target}"
if [[ "$runtime_target_dir" != /* ]]; then
  runtime_target_dir="$REPO_ROOT/$runtime_target_dir"
fi
export SPACEFAST_RUNTIME_BIN="$runtime_target_dir/debug/stattic-runtime"

echo "==> runtime integration tests"
# Scoped to the runtime dir: from the repo root, `bun test runtime/tests` also
# matches the handbook's vendored source mirrors under
# apps/handbook-web/public/generated/src/runtime/tests/, which cannot resolve
# their imports and fail as phantom tests once the handbook is built.
cd "$RUNTIME_DIR"
test_args=(test)
if [[ -n "${SPACEFAST_BUN_COVERAGE_DIR:-}" ]]; then
  mkdir -p "$SPACEFAST_BUN_COVERAGE_DIR"
  test_args+=(
    --coverage
    --coverage-reporter=lcov
    "--coverage-dir=$SPACEFAST_BUN_COVERAGE_DIR"
  )
fi
if [[ -n "${SPACEFAST_RUNTIME_TEST_SHARD:-}" ]]; then
  [[ "$SPACEFAST_RUNTIME_TEST_SHARD" =~ ^[1-9][0-9]*/[1-9][0-9]*$ ]] || {
    echo "invalid SPACEFAST_RUNTIME_TEST_SHARD: $SPACEFAST_RUNTIME_TEST_SHARD" >&2
    exit 2
  }
  test_args+=("--shard=$SPACEFAST_RUNTIME_TEST_SHARD")
fi
test_args+=(tests --timeout 30000)
exec bun "${test_args[@]}"
