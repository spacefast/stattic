# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d5ceeaf3715e9a173c1f0dd2b2def475b521d65b`
- Runtime source hash: `514786524537b1dcf9b25f1724149b6fac71ee780deda86987b13ce7a720ad23`
- Matching release tag: `runtime-d5ceeaf3715e9a173c1f0dd2b2def475b521d65b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
