# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `478044a148ac9e550a4bf23815eff83f9afee85a`
- Runtime source hash: `354903708316adeff4cce4fcc627471826d476d087624ce2cf4f6ca5cf26876c`
- Matching release tag: `runtime-478044a148ac9e550a4bf23815eff83f9afee85a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
