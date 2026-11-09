# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `db41cea9e58140fb7a51d775d8e746cced943556`
- Runtime source hash: `6efbcf8670824278d5aded6b470b1b804c6045d1672d4e9cd74ff2a25e23207e`
- Matching release tag: `runtime-db41cea9e58140fb7a51d775d8e746cced943556`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
