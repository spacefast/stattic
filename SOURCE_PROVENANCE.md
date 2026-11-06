# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8f6945a96e8a3acf6b53ca34526bcdc3e27883aa`
- Runtime source hash: `7540c679906a9ba627ec6918986c6d3678a6f773867a385495ca551764ab8875`
- Matching release tag: `runtime-8f6945a96e8a3acf6b53ca34526bcdc3e27883aa`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
