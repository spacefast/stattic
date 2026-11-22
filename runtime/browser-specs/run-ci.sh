#!/usr/bin/env bash
# Runs the runtime browser proof in a loopback-only network namespace
# (confined.sh). Turbo's ci:runtime-browser calls it directly.
#
# It builds nothing and downloads nothing: everything it needs must already be
# in this checkout, and run.ts refuses to start, inside the namespace, when an
# input is missing, naming the command that makes it. In CI, ci:runtime-prepare
# (runtime/tests/prepare.sh) fetches the pinned WordPress toolkit and builds the
# native runtime and the Zero dashboard, and turbo builds the hosted dashboard
# (`@spacefast/my#build`) and the skill artifacts (spacefast#generate) first.
# Locally:
#
#   bash runtime/tests/prepare.sh            # toolkit, native runtime, Zero dashboard
#   bun run --cwd apps/my build:hosted       # the hosted dashboard
#   bash runtime/browser-specs/run-ci.sh [spec]
#
# (SPACEFAST_RUNTIME_BIN names a native runtime built elsewhere from this
# checkout's native inputs.)
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

# The proof runs only with loopback-only networking. Say so at once on a host
# that cannot give it one.
unshare -rn true || {
  echo "run-ci.sh: unprivileged user and network namespaces (unshare -rn) are not" \
    "available here, and the browser proof will not run with host networking." >&2
  exit 3
}

if [[ -n "${CI:-}" ]]; then
  # This task runs beside the integration suite, which reports coverage from the
  # instrumented binary ci:runtime-prepare built. Never rebuild it here: a plain
  # cargo build could replace it uninstrumented while the suite still runs it.
  runtime_binary="$(bash runtime/tests/runtime-binary.sh)"
  # Keep this run's Rust profiles and PHP coverage apart from the suite's, so
  # the suite's report and PHP entrypoint guard read only its own requests.
  profile_dir="$(mktemp -d)"
  export LLVM_PROFILE_FILE="$profile_dir/browser-%p-%m.profraw"
  export SPACEFAST_PHP_COVERAGE_DIR="$repo_root/.ci-coverage/runtime/php-browser"
  mkdir -p "$SPACEFAST_PHP_COVERAGE_DIR"
elif [[ -n "${SPACEFAST_RUNTIME_BIN:-}" ]]; then
  # A binary the caller already built from this checkout's native inputs.
  runtime_binary="$SPACEFAST_RUNTIME_BIN"
else
  # The debug build prepare.sh made; run.ts names the command when there is none.
  runtime_binary="$(bash runtime/tests/runtime-binary.sh 2>/dev/null)" || runtime_binary=""
fi

SPACEFAST_RUNTIME_BIN="$runtime_binary" \
  bash runtime/browser-specs/confined.sh bun --no-install runtime/browser-specs/run.ts "$@"

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
