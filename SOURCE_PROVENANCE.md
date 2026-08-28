# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `94e8504e87cc5f0a2382d44a6e49391d06dccf49`
- Runtime source hash: `188898860f93027185a9d29834f6a597b58f1ec6cbe570ee8994850c10209988`
- Matching release tag: `runtime-94e8504e87cc5f0a2382d44a6e49391d06dccf49`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
