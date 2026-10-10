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
cd "$REPO_ROOT"
cargo_status=0
cargo build --locked -p stattic-runtime-compiler --bin stattic-runtime || cargo_status=$?
php_gates_status=0
wait "$php_gates_pid" || php_gates_status=$?
[[ "$cargo_status" = 0 ]] || exit "$cargo_status"
[[ "$php_gates_status" = 0 ]] || exit "$php_gates_status"
runtime_target_dir="${CARGO_TARGET_DIR:-$REPO_ROOT/target}"
if [[ "$runtime_target_dir" != /* ]]; then
  runtime_target_dir="$REPO_ROOT/$runtime_target_dir"
fi
export SPACEFAST_RUNTIME_BIN="$runtime_target_dir/debug/stattic-runtime"

echo "==> runtime integration tests"
# Scoped to the runtime dir so the suite runs from one working directory.
cd "$RUNTIME_DIR"
test_args=(test)
if [[ -n "${SPACEFAST_BUN_COVERAGE_DIR:-}" ]]; then
  mkdir -p "$SPACEFAST_BUN_COVERAGE_DIR"
  test_args+=(
    --coverage
    --coverage-reporter=lcov
  )
fi
workers="${SPACEFAST_RUNTIME_TEST_WORKER_COUNT:-1}"
[[ "$workers" =~ ^[1-8]$ ]] || {
  echo "invalid SPACEFAST_RUNTIME_TEST_WORKER_COUNT: $workers" >&2
  exit 2
}
# The worker pool waits with `wait -n`; fail before any build on older bash.
[[ "$workers" = 1 ]] || (( BASH_VERSINFO[0] > 4 || (BASH_VERSINFO[0] == 4 && BASH_VERSINFO[1] >= 3) )) || {
  echo "SPACEFAST_RUNTIME_TEST_WORKER_COUNT > 1 needs bash 4.3 or newer" >&2
  exit 2
}
if [[ -n "${SPACEFAST_RUNTIME_TEST_SHARD:-}" ]]; then
  [[ "$workers" = 1 ]] || {
    echo "SPACEFAST_RUNTIME_TEST_SHARD requires one worker" >&2
    exit 2
  }
  [[ "$SPACEFAST_RUNTIME_TEST_SHARD" =~ ^[1-9][0-9]*/[1-9][0-9]*$ ]] || {
    echo "invalid SPACEFAST_RUNTIME_TEST_SHARD: $SPACEFAST_RUNTIME_TEST_SHARD" >&2
    exit 2
  }
  test_args+=("--shard=$SPACEFAST_RUNTIME_TEST_SHARD")
fi
test_args+=(--timeout 30000)
if [[ "$workers" = 1 ]]; then
  if [[ -n "${SPACEFAST_BUN_COVERAGE_DIR:-}" ]]; then
    test_args+=("--coverage-dir=$SPACEFAST_BUN_COVERAGE_DIR")
  fi
  exec bun "${test_args[@]}" tests
fi

# Lint, PHP units and the instrumented compiler build run once. Independent
# Bun processes divide the files and release their application state on exit.
# A few files dominate the suite, and a fixed shard per worker left half the
# workers idle while the slowest shards finished. Cut three shards per worker
# and start the next one whenever a worker frees up.
# Files that spend most of their time holding requests in flight on purpose.
# Each starts first in its own process, so the suite's floor never waits
# behind other shards, wherever Bun's file order puts the file.
long_files=(tests/admission.test.ts)
reports=()
running=0
status=0
launch() {
  local name="$1"
  shift
  if [[ "$running" -ge "$workers" ]]; then
    wait -n || status=1
    running=$((running - 1))
  fi
  local args=("${test_args[@]}" "$@")
  if [[ -n "${SPACEFAST_BUN_COVERAGE_DIR:-}" ]]; then
    local coverage="$SPACEFAST_BUN_COVERAGE_DIR/$name"
    mkdir -p "$coverage"
    args+=("--coverage-dir=$coverage")
    reports+=("$coverage/lcov.info")
  fi
  bun "${args[@]}" &
  running=$((running + 1))
}
ignore_args=()
for file in "${long_files[@]}"; do
  launch "$(basename "$file" .test.ts)" "./$file"
  ignore_args+=("--path-ignore-patterns=$file")
done
shard_count=$((workers * 3))
for ((shard = 1; shard <= shard_count; shard++)); do
  launch "shard-$shard" "${ignore_args[@]}" "--shard=$shard/$shard_count" tests
done
for ((; running > 0; running--)); do
  wait -n || status=1
done
if [[ "${#reports[@]}" -gt 0 ]]; then
  for report in "${reports[@]}"; do test -s "$report" || status=1; done
  cat "${reports[@]}" > "$SPACEFAST_BUN_COVERAGE_DIR/lcov.info"
  rm -- "${reports[@]}"
fi
exit "$status"
