# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ca9dc35e5d815e84f639a0bf45e7628b94bef9a4`
- Runtime source hash: `7540c679906a9ba627ec6918986c6d3678a6f773867a385495ca551764ab8875`
- Matching release tag: `runtime-ca9dc35e5d815e84f639a0bf45e7628b94bef9a4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
