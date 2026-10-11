# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7aaa03242c2b1c40e60a6bead581f6e89d6a6e70`
- Runtime source hash: `56e10498a23b321885df44f8516cabdbbcf19d9e5616c568ca1e91352dff9a9a`
- Matching release tag: `runtime-7aaa03242c2b1c40e60a6bead581f6e89d6a6e70`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
