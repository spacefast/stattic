# Zero all-features fixture

This fixture is a checked example of the current Zero runtime surface. It keeps
two payloads in sync:

- `api-finalize-request.json` uses the public runtime finalize API shape.
- `runtime-compiler-finalize-input.json` uses the Rust compiler input shape so
  the same endpoints can be compiled locally.

The example covers exact and dynamic Zero endpoints, run-handler artifacts, all
capability flags, compiled QuickJS bytecode, endpoint and run index generation,
DB metadata, and migration generation. Run handlers compile to private
`zero/runs` artifacts; they are not PHP route actions.
