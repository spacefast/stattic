# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `02383eadd9ecf07c615d93cab6184112d1f259a5`
- Runtime source hash: `cc93f80c7992136e210565792ea70672bc582c8a6dbcea07220a1388de976cea`
- Matching release tag: `runtime-02383eadd9ecf07c615d93cab6184112d1f259a5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
