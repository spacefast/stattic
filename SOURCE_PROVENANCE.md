# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5c0ec773688ba19d43dc5a015c955d705dd250cb`
- Runtime source hash: `d1b6658bc88bb4692f41be4f3aa38cc572265168df329f0f4fbfbcf98d3eb510`
- Matching release tag: `runtime-5c0ec773688ba19d43dc5a015c955d705dd250cb`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
