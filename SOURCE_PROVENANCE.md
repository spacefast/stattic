# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b708bfd26d15601e744f58685fe885824f8741d1`
- Runtime source hash: `a200191f3b962ba391212af46ee0d515f4b52d4d3e2272c1d671525d0c151404`
- Matching release tag: `runtime-b708bfd26d15601e744f58685fe885824f8741d1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
