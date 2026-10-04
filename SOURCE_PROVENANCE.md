# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d4187bd017310bef2e0cbe6cf412592d1c4c2c89`
- Runtime source hash: `e74d701ca8bad931681a309b718e96535d24bfed1742f6faee1ba78cef7e5188`
- Matching release tag: `runtime-d4187bd017310bef2e0cbe6cf412592d1c4c2c89`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
