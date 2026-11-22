# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a6deb422ca9df013fb63d2959f51fff95c48611f`
- Runtime source hash: `ef33b1698f06003236cb67c80fa83fb45156fbb46cca7dbde4791b21ffa888fc`
- Matching release tag: `runtime-a6deb422ca9df013fb63d2959f51fff95c48611f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
