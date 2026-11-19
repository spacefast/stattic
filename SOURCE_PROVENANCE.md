# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2e7503b29617d70cad377822d86a85dbf42e9c26`
- Runtime source hash: `fb5d80bc8e7f3a96b009f33a671a078217e9a83811c8f21d783470a160ac94bf`
- Matching release tag: `runtime-2e7503b29617d70cad377822d86a85dbf42e9c26`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
