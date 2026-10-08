# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `38b53a0c71c61df065728277fe3cf1c1c2a01087`
- Runtime source hash: `2983042b42eeb989a0c34f6dca65b7c4289768a739bb75c63398212fc87f69c9`
- Matching release tag: `runtime-38b53a0c71c61df065728277fe3cf1c1c2a01087`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
