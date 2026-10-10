#!/usr/bin/env bash
# Runs the runtime browser proof. Turbo's ci:runtime-browser calls it directly:
# spacefast#generate restored the skill artifacts, and ci:runtime built the
# native runtime and the Zero dashboard in this checkout. A cached ci:runtime
# restores only coverage, so build whatever is missing instead of assuming it.
# Outside CI the dashboard is always rebuilt, so a local run never reuses a
# stale one.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

cargo_target_dir="${CARGO_TARGET_DIR:-$repo_root/target}"
if [[ "$cargo_target_dir" != /* ]]; then
  cargo_target_dir="$repo_root/$cargo_target_dir"
fi
# A no-op when ci:runtime already built this binary.
cargo build --locked -p stattic-runtime-compiler --bin stattic-runtime

# CI starts from a fresh checkout, so an existing dashboard came from ci:runtime
# in this run. Locally the gitignored directory can be stale or half-written.
if [[ -z "${CI:-}" || ! -f runtime/wordpress/zero-dashboard/zero-dashboard.php ]]; then
  bun zero/scripts/build.ts >/dev/null
fi

database_url="${SPACEFAST_TEST_DATABASE_ADMIN_URL:-postgres://stattic:stattic@127.0.0.1:25432/stattic}"
DATABASE_URL="$database_url" bun run --cwd apps/control-plane db:migrate
SPACEFAST_RUNTIME_BIN="${SPACEFAST_RUNTIME_BIN:-$cargo_target_dir/debug/stattic-runtime}" \
  SPACEFAST_TEST_DATABASE_URL="$database_url" \
  SPACEFAST_TEST_REDIS_URL="${SPACEFAST_TEST_REDIS_ADMIN_URL:-redis://127.0.0.1:26379}" \
  bun runtime/browser-specs/run.ts
