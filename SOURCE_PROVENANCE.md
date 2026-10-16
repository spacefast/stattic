# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4670567c3f1e515b17498ae5395ece2541c1d6d0`
- Runtime source hash: `07c316654f22becde0030961aad10cb8a0352cbb93c434c9f379c43d16ff60fc`
- Matching release tag: `runtime-4670567c3f1e515b17498ae5395ece2541c1d6d0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
