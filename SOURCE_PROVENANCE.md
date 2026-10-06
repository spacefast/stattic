# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c0fd3972b4139313a5e2f1c4361364ddeb702aab`
- Runtime source hash: `357678c87a59898813a149795f4362e74390eff2cb35ffd51817f30800ffaa18`
- Matching release tag: `runtime-c0fd3972b4139313a5e2f1c4361364ddeb702aab`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
