# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `87bafb69c4d040beb177f5e715714d147bee86c3`
- Runtime source hash: `0c87024ee542f012cfdc1a347b5569eda21e37acfcd20b6ffb5ab2224960093a`
- Matching release tag: `runtime-87bafb69c4d040beb177f5e715714d147bee86c3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
