# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `919db7ce295998a096f83ff6dd239e62c8207d7a`
- Runtime source hash: `f87c9a5f526e14f6527fec9e2ea9fd2ae827c478cb15d7706852cb29de822bba`
- Matching release tag: `runtime-919db7ce295998a096f83ff6dd239e62c8207d7a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
