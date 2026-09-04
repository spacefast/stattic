# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `939ef325bf24e067c36c8a84952d0044201ceffb`
- Runtime source hash: `451edcaf9b98d83608eba0288b68c336b8d3911332855d6fd7fd8c2ed39457e2`
- Matching release tag: `runtime-939ef325bf24e067c36c8a84952d0044201ceffb`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
