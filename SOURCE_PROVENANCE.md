# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fb705f835491797d00647d0a128723b49166ccc2`
- Runtime source hash: `e834e7d228c55bda9c251d504478c343266b720bc0d5d377f63ead06420c56f0`
- Matching release tag: `runtime-fb705f835491797d00647d0a128723b49166ccc2`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
