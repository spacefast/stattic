# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fedaffb90995c4abd5411e731156eed65b30e4fc`
- Runtime source hash: `d87977e9afee685466ec2ddbc5685e44b3b7761db73451657a13b65e3adb3d40`
- Matching release tag: `runtime-fedaffb90995c4abd5411e731156eed65b30e4fc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
