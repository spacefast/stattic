# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `094e982f9e66c478c184d87eb42456996a9ef2b2`
- Runtime source hash: `31f16c97af3241fecd66bcddde226d7b9c60cfbf1e0d334a636458846b11f91d`
- Matching release tag: `runtime-094e982f9e66c478c184d87eb42456996a9ef2b2`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
