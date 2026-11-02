# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `eec91528dcab58cfa2ee06a74d1904a8c351c9d1`
- Runtime source hash: `a2959a2f147251ff86932fea7c90b7e11975fed25b12620c1962dec1763755e4`
- Matching release tag: `runtime-eec91528dcab58cfa2ee06a74d1904a8c351c9d1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
