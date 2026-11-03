# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7eaa80495c51835183b62a9b9faa00383d98bd4a`
- Runtime source hash: `1e1034564fbaf263dbed089ed9a3563b0c0dd4a5b2714ad3032318c038429b1a`
- Matching release tag: `runtime-7eaa80495c51835183b62a9b9faa00383d98bd4a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
