# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `33794ff1a695be904c4e26f597dd294c39e9d6a4`
- Runtime source hash: `1eeddcb1fd4dfcb337c1ab70baacd3a36e1acc42037124469806390399b50023`
- Matching release tag: `runtime-33794ff1a695be904c4e26f597dd294c39e9d6a4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
