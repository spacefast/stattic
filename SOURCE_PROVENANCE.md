# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2ad4ea71394f4462f2719dbcb925ea1a7e9491b1`
- Runtime source hash: `060bd95a40ba7af6357e47b5101e720306fdb68c9a40a1b16ed70dcf20b0b9d2`
- Matching release tag: `runtime-2ad4ea71394f4462f2719dbcb925ea1a7e9491b1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
