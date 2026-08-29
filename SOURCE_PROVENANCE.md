# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d4145bd7cbcf07ce0d7f0d47ef6ae31513c72dad`
- Runtime source hash: `3c05caf4f194877c1610cadee306d3b2a1ec2da86884779be69855bc7ec83bf4`
- Matching release tag: `runtime-d4145bd7cbcf07ce0d7f0d47ef6ae31513c72dad`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
