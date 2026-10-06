# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7bb3c1fb59f1c89faa2c71c083d24592ff67d3da`
- Runtime source hash: `bef70ef92ffc52f9cbc994a2be5c9aa3f8f1e2599085fe67ad6c6bcb2faf70ca`
- Matching release tag: `runtime-7bb3c1fb59f1c89faa2c71c083d24592ff67d3da`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
