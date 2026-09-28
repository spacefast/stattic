# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `90cddec3e94f93f2fbcd76a715e664c43e011f1b`
- Runtime source hash: `43a3670aa6b1d507ac7b2c0f943261d1452b8fb3a70a93ade8a618a597bae5e9`
- Matching release tag: `runtime-90cddec3e94f93f2fbcd76a715e664c43e011f1b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
