#!/usr/bin/env bash
# Everything the runtime integration tests need before they start: the PHP
# toolkit, the Zero dashboard with the PHP lint and unit gates over it, and the
# native runtime binary. run.sh calls this; CI runs it as ci:runtime-prepare.
set -euo pipefail

RUNTIME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$RUNTIME_DIR/.." && pwd)"
cd "$REPO_ROOT"

# Prepare the shared pinned library before independent workers read it. A cold
# checkout should fetch once, rather than every shard racing the release host.
echo "==> WordPress PHP toolkit"
bun "$REPO_ROOT/scripts/fetch-wp-php-toolkit.mjs"

# The PHP gates lint the dashboard build's generated PHP, so they follow it.
# The native build shares nothing with that chain and runs beside it.
php_gates() {
  # Build the WordPress dashboard shipped in the engine manifest.
  echo "==> Zero dashboard plugin"
  bun "$REPO_ROOT/zero/scripts/build.ts" >/dev/null

  echo "==> php -l (all runtime PHP files)"
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || exit 1
  done < <(find "$RUNTIME_DIR" -name '*.php' -print0)

  echo "==> php unit tests"
  php "$RUNTIME_DIR/tests/unit.php"
}

php_gates &
php_gates_pid=$!
echo "==> native runtime test tools"
cargo_status=0
cargo build --locked -p stattic-runtime-compiler --bin stattic-runtime || cargo_status=$?
php_gates_status=0
wait "$php_gates_pid" || php_gates_status=$?
[[ "$cargo_status" = 0 ]] || exit "$cargo_status"
[[ "$php_gates_status" = 0 ]] || exit "$php_gates_status"
