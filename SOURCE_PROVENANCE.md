# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0d59b41fa3502d417afa28f7f6e62f99c18f7cff`
- Runtime source hash: `e3217744a802cf81d6357c00ff3e2fab06b98236641bbf82f0e105b3af46cc1f`
- Matching release tag: `runtime-0d59b41fa3502d417afa28f7f6e62f99c18f7cff`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
