# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b0710209efac03f82f2701ab056d4e1fdb7a8525`
- Runtime source hash: `95447c80cdba38f5511d86d617b98a9151754e44dcf5c53db7f438f3163bbe36`
- Matching release tag: `runtime-b0710209efac03f82f2701ab056d4e1fdb7a8525`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
