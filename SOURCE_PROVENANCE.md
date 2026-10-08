# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `12caf6b2fd39ad3038eee449c774ad072152f285`
- Runtime source hash: `48e0649d3da6a2543cafb1479a179e487df66a25ad626f1932a4a453799daa7a`
- Matching release tag: `runtime-12caf6b2fd39ad3038eee449c774ad072152f285`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
