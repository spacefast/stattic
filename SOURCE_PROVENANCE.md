# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d9ae96633b74ad0716cb84cdb6ea7a4a3535769a`
- Runtime source hash: `cae798c656e848c0cca16f437ee993e2162d462f2bf2f6cedae10244b467b5a9`
- Matching release tag: `runtime-d9ae96633b74ad0716cb84cdb6ea7a4a3535769a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
