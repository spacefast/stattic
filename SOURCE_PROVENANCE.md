# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `535d18d5a66f76bc1562cfeb91f4c57ec557ff54`
- Runtime source hash: `6e9b320343c1cb9fb5dd5ae50e1f2d65029a69936e3882489c4e304937b7b46c`
- Matching release tag: `runtime-535d18d5a66f76bc1562cfeb91f4c57ec557ff54`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
