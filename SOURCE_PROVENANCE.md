# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `28c929ad90777cb1e71ad8f0314b4f6467158bf9`
- Runtime source hash: `faca09cb3e289e93e411c971e06bad0b0a006a3d69cd7e8de31549fa86d64ac1`
- Matching release tag: `runtime-28c929ad90777cb1e71ad8f0314b4f6467158bf9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
