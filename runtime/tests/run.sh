#!/usr/bin/env bash
# Single entry point for the runtime test suite:
#   1. php -l on every PHP file under runtime/
#   2. pure-function unit tests (php)
#   3. native runtime test-tool build (cargo)
#   4. HTTP integration tests against php -S fixture roots (bun)
set -euo pipefail

RUNTIME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$RUNTIME_DIR/.." && pwd)"

command -v php >/dev/null || { echo "php is required (8.2+ with sodium and zip)" >&2; exit 1; }
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
cargo build --locked -p stattic-runtime-compiler -p stattic-zero-runner
export SPACEFAST_RUNTIME_FINALIZER_BIN="$REPO_ROOT/target/debug/stattic-runtime-compiler"
export SPACEFAST_ZERO_RUNNER="$REPO_ROOT/target/debug/stattic-zero-runner"

echo "==> runtime integration tests"
# Scoped to the runtime dir on purpose: from the repo root, `bun test
# runtime/tests` also glob-matches the handbook's vendored source mirrors
# under apps/handbook-web/public/generated/src/runtime/tests/, which cannot
# resolve their imports and fail as phantom tests once the handbook is built.
cd "$RUNTIME_DIR"
exec bun test tests --timeout 30000
