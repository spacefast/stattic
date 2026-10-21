# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a71a8b148bae0403ca241b5f93dc527a5531fc28`
- Runtime source hash: `392e38bb0788879e677a779d1c036606b90d4ba9fba266e0b5083595a7f28f75`
- Matching release tag: `runtime-a71a8b148bae0403ca241b5f93dc527a5531fc28`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
