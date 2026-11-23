# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `dfd71003b528ceb523f1ebf210631a2cb21d6286`
- Runtime source hash: `b6615dedd26064fe6a066a19d2ae6ee54a24181f8fea1a84d2c91ebdc24a8a35`
- Matching release tag: `runtime-dfd71003b528ceb523f1ebf210631a2cb21d6286`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
