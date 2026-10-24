# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `35f35a649aada7a650fca67124bd7bd55a5fbf93`
- Runtime source hash: `d7f2adfc95bbdd78019bd3330dfa4796f796e52759ed38f02c5a8f07d2d46a38`
- Matching release tag: `runtime-35f35a649aada7a650fca67124bd7bd55a5fbf93`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
