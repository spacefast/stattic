# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e1be03f7ea2971b32ed610737de1e3285a11c670`
- Runtime source hash: `b1d757a36ec63006fb77675b066dc5cb1e43b98e7ba6da145c13be47343c7b5c`
- Matching release tag: `runtime-e1be03f7ea2971b32ed610737de1e3285a11c670`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
