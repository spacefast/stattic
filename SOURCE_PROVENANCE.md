# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4db5ce9573c8d6d4e81a161cb8757c24118294f4`
- Runtime source hash: `543c6745d862215e383c2c93b3655665ac109e527bc40f543bace5d9e90970d0`
- Matching release tag: `runtime-4db5ce9573c8d6d4e81a161cb8757c24118294f4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
