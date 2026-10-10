#!/usr/bin/env bash
# Turbo's ci:runtime-prepare. It builds the native runtime with the same
# coverage instrumentation ci:runtime reports on, and needs none of the
# workspace packages, so Turbo runs it beside their builds.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

# shellcheck source=scripts/lib/cargo-llvm-cov.sh
source scripts/lib/cargo-llvm-cov.sh
llvm_cov_begin "${RUNNER_TEMP:-$repo_root/.ci-results}/runtime-rust-coverage-env.sh"

# The workspace builds this runs beside are a serial chain that gates the
# suite; at a lower priority the native build takes only the CPU they leave.
nice -n 10 bash runtime/tests/prepare.sh
