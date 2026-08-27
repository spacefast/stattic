# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c1d769e547f9e47c6cfc881c095320e4248d2db2`
- Runtime source hash: `d8ed001af5bb84167cc166aefc743a6ea417d2300e854493263230bfe0300916`
- Matching release tag: `runtime-c1d769e547f9e47c6cfc881c095320e4248d2db2`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
