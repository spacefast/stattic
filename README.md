# Stattic

Stattic is a file-based Builder and PHP Runtime for Markdown, Gutenberg block
markup, block themes and prebuilt static sites.

Spacefast is the managed cloud control plane for Stattic. It adds hosted builds,
artifact storage, domains, placement, activation, rollback and operations, but
it does not own a second compiler or artifact language.

## Current foundation

This repository was extracted with history from the production Spacefast
runtime after its Rust-only finalizer cutover. It currently contains:

- `stattic`, the standalone Builder CLI;
- `stattic-runtime-core`, the canonical Rust compiler;
- `stattic-runtime-wasi`, the browser/Node preparation surface;
- `stattic-runtime-compiler`, the temporary Spacefast-compatible native host;
- `stattic-zero-runner`, the optional executable runtime;
- the PHP Stattic Runtime and compiler-free bundle admission;
- `@stattic/routing`, the TypeScript/WASI host package.

The repository is pre-1.0. Some internal artifact names still carry historical
Spacefast vocabulary while the portable contracts are extracted.

## Build a Markdown site

```sh
cargo run -p stattic -- build examples/hello \
  --output target/hello.stattic \
  --space-id local \
  --version-id v1
```

The output is a directory-form `stattic.bundle.v1` artifact. It contains a
closed manifest, deterministic content identity and compiled Runtime payload.
The bundle output must be outside the source directory.

Inspect and verify it without executing its contents:

```sh
cargo run -p stattic -- inspect target/hello.stattic
```

Admit it into the PHP Runtime without Rust or process execution:

```sh
php -d disable_functions=proc_open,exec,shell_exec,system,passthru \
  runtime/bin/admit.php \
  --bundle target/hello.stattic \
  --storage target/runtime-storage
```

`bundle-receipt.json` is the durable proof that the admitted version needs no
server build and no Rust finalizer.

## Test

```sh
cargo test --workspace
php -d disable_functions=proc_open,exec,shell_exec,system,passthru \
  runtime/tests/bundle-admission.php
```

The larger PHP runtime suite is retained from Spacefast and will be reduced to
portable Runtime contracts as the extraction proceeds.

## Architecture

See [docs/architecture.md](docs/architecture.md),
[docs/bundle-v1.md](docs/bundle-v1.md), and the
[Spacefast adapter contract](docs/spacefast-adapter.md).

## Publishing status

This bootstrap intentionally has no Git remote and introduces no new license
declaration. Repository ownership, public location and licensing must be chosen
before publication.
