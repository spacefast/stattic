# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `dd6b70dc2f5a5fef917aff8c2224a51bda7cac57`
- Runtime source hash: `563d17ec4b1336365cadf63d47b9293a0575208a5a3318bb60a920541bc44d62`
- Matching release tag: `runtime-dd6b70dc2f5a5fef917aff8c2224a51bda7cac57`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
