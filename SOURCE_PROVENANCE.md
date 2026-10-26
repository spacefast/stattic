# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bf39b6e3a7a374ba775ddb530f14523945d6e6da`
- Runtime source hash: `57a27dbe8007b9c950c3189cc125cc67e38421d886e59a643fe1b312b390d6c7`
- Matching release tag: `runtime-bf39b6e3a7a374ba775ddb530f14523945d6e6da`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
