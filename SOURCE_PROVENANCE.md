# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `9a1052bbbb7542287a45b15d54962aba65d810a0`
- Runtime source hash: `baa7a7e45d966c5a36d8c86c130f6b3751c393508f6a7f63c4260c4bf5ba2e98`
- Matching release tag: `runtime-9a1052bbbb7542287a45b15d54962aba65d810a0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
