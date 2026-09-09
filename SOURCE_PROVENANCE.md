# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ec14be251b5d7ff07da1ca8265d9119d1ba9d10a`
- Runtime source hash: `5e7f5e70caab15a4be560d8fad21295bd0c8e6179f7d12818568c06dd5834e35`
- Matching release tag: `runtime-ec14be251b5d7ff07da1ca8265d9119d1ba9d10a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
