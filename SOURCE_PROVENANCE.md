# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fb4d4a0101c1ceac5cacfa07f40bd6d8fa104070`
- Runtime source hash: `f9246c0f4dd4f6b0b41dbd86b60a963b8fe44715d203c9c888f4278ef5466085`
- Matching release tag: `runtime-fb4d4a0101c1ceac5cacfa07f40bd6d8fa104070`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
