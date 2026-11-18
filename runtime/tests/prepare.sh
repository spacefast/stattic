#!/usr/bin/env bash
# Everything the runtime integration tests need before they start: the PHP
# toolkit, the Zero dashboard, the PHP lint and unit gates, and the native
# runtime binary. run.sh calls this; CI runs it as ci:runtime-prepare.
set -euo pipefail

RUNTIME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$RUNTIME_DIR/.." && pwd)"
cd "$REPO_ROOT"
node scripts/prepare-typedpress.ts

# Prepare the shared pinned library before independent workers read it. A cold
# checkout should fetch once, rather than every shard racing the release host.
echo "==> WordPress PHP toolkit"
bun "$REPO_ROOT/scripts/fetch-wp-php-toolkit.mjs"

# The lint covers the PHP the dashboard build generates, so it follows that
# build. The unit tests read only checked-in PHP and the native build shares
# nothing with either, so all three run side by side.
dashboard_and_lint() {
  # Build the WordPress dashboard shipped in the engine manifest.
  echo "==> Zero dashboard plugin"
  bun "$REPO_ROOT/legacy/zero-0.8/dashboard/scripts/build.ts" >/dev/null

  echo "==> php -l (all runtime PHP files)"
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || exit 1
  done < <(find "$RUNTIME_DIR" -name '*.php' -print0)
}

dashboard_and_lint &
dashboard_and_lint_pid=$!
echo "==> php unit tests"
php "$RUNTIME_DIR/tests/unit.php" &
php_unit_pid=$!
cargo_status=0
if bash "$RUNTIME_DIR/tests/runtime-binary.sh" --packaged; then
  echo "==> native runtime: testing the restored packaged binary"
else
  echo "==> native runtime test tools"
  # Install the pinned toolchain under the shared lock first: a bare cargo call
  # lets rustup's proxy install it unlocked, racing the WASI build's install on
  # a runner that lacks it ("detected conflict: 'bin/cargo'").
  node -e 'import("./scripts/lib/rust-toolchain.mjs").then((m) => m.ensureRustTarget("wasm32-wasip1"))'
  cargo build --locked -p stattic-runtime-compiler --bin stattic-runtime || cargo_status=$?
fi
dashboard_and_lint_status=0
wait "$dashboard_and_lint_pid" || dashboard_and_lint_status=$?
php_unit_status=0
wait "$php_unit_pid" || php_unit_status=$?
[[ "$cargo_status" = 0 ]] || exit "$cargo_status"
[[ "$dashboard_and_lint_status" = 0 ]] || exit "$dashboard_and_lint_status"
[[ "$php_unit_status" = 0 ]] || exit "$php_unit_status"
