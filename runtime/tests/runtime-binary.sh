#!/usr/bin/env bash
# Names the native runtime binary the runtime suites run in this checkout.
#
#   runtime-binary.sh
#     Prints its path, and fails when there is none yet.
#   runtime-binary.sh --packaged
#     Succeeds when this run tests the packaged binary, so nothing compiles.
#
# CI restores the packaged binary main built from exactly this checkout's
# native inputs (.github/actions/runtime-binary). A run that found one tests
# it, and tests/prepare.sh skips a minute and a half of compiling a debug
# build nothing would use. Coverage runs instrument their own build, and a
# developer host can hold a packaged binary from any earlier build, so both
# always use the debug build.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
packaged="$repo_root/runtime/bin/stattic-runtime"
target_dir="${CARGO_TARGET_DIR:-$repo_root/target}"
if [[ "$target_dir" != /* ]]; then
  target_dir="$repo_root/$target_dir"
fi
debug="$target_dir/debug/stattic-runtime"

uses_packaged=false
if [[ -n "${CI:-}" && -z "${CI_COVERAGE:-}" && -f "$packaged" ]]; then
  uses_packaged=true
fi

if [[ "${1:-}" == --packaged ]]; then
  [[ "$uses_packaged" == true ]]
  exit
fi

if [[ "$uses_packaged" == true ]]; then
  # Caches and artifacts keep the bytes but not the executable mode.
  chmod +x "$packaged"
  echo "$packaged"
elif [[ -x "$debug" ]]; then
  echo "$debug"
else
  echo "no native runtime binary: tests/prepare.sh has not built $debug" >&2
  exit 1
fi
