# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `762b39b22b5c4d8004c859e258670dd20d22bd40`
- Runtime source hash: `aed64bdf3d6634c634f67de51774a4ad5e731f2e7881af7565ce7761c5fad1d7`
- Matching release tag: `runtime-762b39b22b5c4d8004c859e258670dd20d22bd40`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
