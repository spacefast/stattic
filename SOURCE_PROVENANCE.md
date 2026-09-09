# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `08f1e1e62da48171ac9086f0fc1816d7d133246d`
- Runtime source hash: `bf2263b68f0fc99b2833669558f1f73f9f30d9d59a3c226223feeed776b25655`
- Matching release tag: `runtime-08f1e1e62da48171ac9086f0fc1816d7d133246d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
