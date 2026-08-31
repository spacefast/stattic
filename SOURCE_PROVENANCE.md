# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b05d7e5cdfb9873decd18c69bb25d9ff65d524cd`
- Runtime source hash: `2c0d4cb87095ca6ef356b65da89575d4cbc376a83d03593c8450cd6c9c7d20a2`
- Matching release tag: `runtime-b05d7e5cdfb9873decd18c69bb25d9ff65d524cd`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
