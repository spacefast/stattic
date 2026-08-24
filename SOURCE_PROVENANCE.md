# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `618a0ec11c5755795d59c17f43210749d1f0bada`
- Runtime source hash: `ef3e1f8ee57214b251643274204ea0b2ad445b92e098f6670b7079ddc75f3b63`
- Matching release tag: `runtime-618a0ec11c5755795d59c17f43210749d1f0bada`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
