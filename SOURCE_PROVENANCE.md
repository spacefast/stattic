# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2861244e162291d771225a69d4300923d4993957`
- Runtime source hash: `d1b6658bc88bb4692f41be4f3aa38cc572265168df329f0f4fbfbcf98d3eb510`
- Matching release tag: `runtime-2861244e162291d771225a69d4300923d4993957`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
