# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3ac17a058f60de428191c30a7563f0dca799bb74`
- Runtime source hash: `54b2a8d939ffd7ab3369eec0a2042c2b20e18253629dce85ff8cc3a151109b9f`
- Matching release tag: `runtime-3ac17a058f60de428191c30a7563f0dca799bb74`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
