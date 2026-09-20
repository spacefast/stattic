# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `34331681795a4cbe47cef05ef5baf8d773ba32f7`
- Runtime source hash: `1bbf04345a8b697a126f10da660555ae380b2becf3247ba89a41c1437401bddb`
- Matching release tag: `runtime-34331681795a4cbe47cef05ef5baf8d773ba32f7`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
