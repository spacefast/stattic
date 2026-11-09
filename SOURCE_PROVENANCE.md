# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b0fb997bf85fe93a602bb8f7adb361fca8b5c51d`
- Runtime source hash: `f23b1d9af82a97355ad2d74283a3cbd3456f323750c0fc55d17e6861e5e8cfc3`
- Matching release tag: `runtime-b0fb997bf85fe93a602bb8f7adb361fca8b5c51d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
