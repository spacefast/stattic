# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8c8d5fab49e3a6a50de468a2960316001d4b0a0b`
- Runtime source hash: `a679ce0fbabca28ed18a4a56dd8f47ad59e3e56e6f5c34b26b6ed1210fb161c0`
- Matching release tag: `runtime-8c8d5fab49e3a6a50de468a2960316001d4b0a0b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
