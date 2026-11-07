# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0acae50fc854253b2b02ed64197348e5a6261b94`
- Runtime source hash: `c23e73ca0a3a2174fabdea967ff76a91decca16f20c2d15467ed089945f80d02`
- Matching release tag: `runtime-0acae50fc854253b2b02ed64197348e5a6261b94`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
