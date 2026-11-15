# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7ac25ddf5adda52092c2890eaf5d2d5cc190c70b`
- Runtime source hash: `df89948e062c13f35bcf7b5ce35a5eb0440e1403db8f006bce816162daedba00`
- Matching release tag: `runtime-7ac25ddf5adda52092c2890eaf5d2d5cc190c70b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
