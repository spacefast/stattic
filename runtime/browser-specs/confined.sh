#!/usr/bin/env bash
# Runs a command with no network but loopback, and nothing left behind.
#
#   confined.sh <command...>
#
# What this is for: ACCIDENTAL egress. The code under test (the control plane,
# the PHP engines, Chromium, a CLI) may by default or by mistake dial a real
# hostname; inside this boundary it cannot. A process that deliberately looks
# for a way out is out of scope: the host filesystem stays visible, so a host
# Unix socket (or a descriptor passed over one) is a way out, and so is
# server-side work a database superuser asks the host's Postgres to do.
#
# The command runs in new user, network, PID and mount namespaces
# (`unshare -rn --pid --fork --kill-child --mount-proc`, no privileges). A new
# network namespace starts with one device, loopback, and no route: nothing
# reaches another machine, whatever API, socket or library a process uses. The
# PID namespace means the kernel kills every process the command started (PHP
# workers in their own process groups included) when it exits or is killed.
# The command gets stdin from /dev/null and its output through a pipe.
#
# The one thing it may reach outside is the test Postgres, at exactly
# 127.0.0.1:25432 (the shared dev Postgres, RUNNING.md) or 127.0.0.1:5432 (CI's
# service container). Its one TCP port is bridged in over a Unix socket file and
# carries nothing else. The command creates its own database through it and
# drops only that one (run.ts).
#
# The scratch directory (`$SPACEFAST_BROWSER_RUN_DIR`) is removed on every exit
# path.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
relay="$repo_root/runtime/browser-specs/loopback-relay.ts"

admin_url="${SPACEFAST_TEST_DATABASE_ADMIN_URL:-postgres://stattic:stattic@127.0.0.1:25432/stattic}"
if [[ "$admin_url" =~ ^postgres(ql)?://[A-Za-z0-9._~%-]+(:[A-Za-z0-9._~%-]*)?@127\.0\.0\.1:(25432|5432)/[A-Za-z0-9_]+$ ]]; then
  database_port="${BASH_REMATCH[3]}"
else
  echo "confined.sh: SPACEFAST_TEST_DATABASE_ADMIN_URL must be" \
    "postgres://<user>[:<password>]@127.0.0.1:<25432|5432>/<database>, the local test" \
    "Postgres, with no query options; the proof reaches nothing else" >&2
  exit 2
fi
# The fixtures never take a database from the caller: run.ts creates one.
unset SPACEFAST_TEST_DATABASE_URL DATABASE_URL PGHOST PGHOSTADDR PGPORT PGSERVICE PGSERVICEFILE

if ! unshare -rn true 2>/dev/null; then
  echo "confined.sh: this host does not allow unprivileged user and network namespaces" \
    "(unshare -rn). The browser proof runs only with loopback-only networking; it" \
    "will not run with host networking." >&2
  exit 3
fi

run_dir="$(mktemp -d "${TMPDIR:-/tmp}/spacefast-browser-run.XXXXXX")"
outside_relay=""
cleanup() {
  [[ -n "$outside_relay" ]] && kill "$outside_relay" 2>/dev/null || true
  [[ -n "$outside_relay" ]] && wait "$outside_relay" 2>/dev/null || true
  rm -rf "$run_dir"
}
trap cleanup EXIT

# Outside: the socket file forwards to 127.0.0.1:<port>, and only there.
database_socket="$run_dir/database.sock"
bun --no-install "$relay" unix-to-tcp "$database_socket" "$database_port" </dev/null &
outside_relay=$!
for _ in $(seq 1 100); do
  [[ -S "$database_socket" ]] && break
  sleep 0.05
done
[[ -S "$database_socket" ]] || { echo "confined.sh: the database relay did not start" >&2; exit 1; }

export SPACEFAST_BROWSER_RUN_DIR="$run_dir"
export SPACEFAST_TEST_DATABASE_ADMIN_URL="$admin_url"
export TMPDIR="$run_dir"
unshare -rn --pid --fork --kill-child --mount-proc bash -euo pipefail -c '
  relay="$1"; database_socket="$2"; database_port="$3"; shift 3
  ip link set lo up
  # Inside: the database port, forwarded to the socket file. Ready once the
  # kernel lists its listening socket; nothing dials Postgres to find out.
  bun --no-install "$relay" tcp-to-unix "$database_port" "$database_socket" &
  port_hex=$(printf "%04X" "$database_port")
  for _ in $(seq 1 100); do
    awk -v p=":$port_hex" "\$2 ~ p\"\$\" && \$4 == \"0A\" { found = 1 } END { exit !found }" /proc/self/net/tcp && break
    sleep 0.05
  done
  "$@"
' confined "$relay" "$database_socket" "$database_port" "$@" </dev/null 2>&1 | cat
