# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `85d93ee29c81a9063d9bc8367ea230194613b3d7`
- Runtime source hash: `d492045105e8745fec183516d2f77db4c3b4f83332d32fcf7e7d229dbf9d480d`
- Matching release tag: `runtime-85d93ee29c81a9063d9bc8367ea230194613b3d7`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
