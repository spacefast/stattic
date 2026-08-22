# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8a1798f5b50d5caca1caea8ac4d6b6480b5fece5`
- Runtime source hash: `ba436db23f7e5d873830b2051786d277367d1de32a4a84d45e053dbb4471ea1a`
- Matching release tag: `runtime-8a1798f5b50d5caca1caea8ac4d6b6480b5fece5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
