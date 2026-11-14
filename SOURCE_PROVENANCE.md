# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0360a0c845678430228fd209bc0398d421c893cd`
- Runtime source hash: `7fd90eea2d24c2b33f3be2deb25b1ab9eb43fd4326db0661719fd72916b10e62`
- Matching release tag: `runtime-0360a0c845678430228fd209bc0398d421c893cd`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
