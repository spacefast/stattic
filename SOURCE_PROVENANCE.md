# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `eba3270dc8d492be0993d75c2c7944363d406ad3`
- Runtime source hash: `d5dcea2a3b193161989eb01c4db8339be470a63b1abe7322bb7ea7085415016b`
- Matching release tag: `runtime-eba3270dc8d492be0993d75c2c7944363d406ad3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
