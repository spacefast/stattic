# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `37594c72827e3bc1b387b1e9339d974b82edcfe9`
- Runtime source hash: `f23b1d9af82a97355ad2d74283a3cbd3456f323750c0fc55d17e6861e5e8cfc3`
- Matching release tag: `runtime-37594c72827e3bc1b387b1e9339d974b82edcfe9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
