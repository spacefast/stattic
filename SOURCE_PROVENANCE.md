# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `87aebf1b962a6adf921f4c43c9b4075a2abc21cc`
- Runtime source hash: `b936bf15cc10494dda092b6788b02ff429dcf71f99a5547a708709b158059453`
- Matching release tag: `runtime-87aebf1b962a6adf921f4c43c9b4075a2abc21cc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
