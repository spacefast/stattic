# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3fe6dd6cfe77986ca3ce280a9d23a4a09657320d`
- Runtime source hash: `e295e8e615f10c34dd47e67f956e3b18d35fcca05d7c2643fb38c40bc6bbd425`
- Matching release tag: `runtime-3fe6dd6cfe77986ca3ce280a9d23a4a09657320d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
