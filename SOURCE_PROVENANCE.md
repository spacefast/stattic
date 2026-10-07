# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6ae5e610c6b13c5f8390eed97569a9bf7abb3f81`
- Runtime source hash: `cc93f80c7992136e210565792ea70672bc582c8a6dbcea07220a1388de976cea`
- Matching release tag: `runtime-6ae5e610c6b13c5f8390eed97569a9bf7abb3f81`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
