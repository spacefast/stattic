# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `633d153d1d48dab2413a9156f4db1eab3be30db2`
- Runtime source hash: `4583d8f8c1c4abec1f4e25e676907f66c08fc4ee84b4f65d98414ec9538a64bc`
- Matching release tag: `runtime-633d153d1d48dab2413a9156f4db1eab3be30db2`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the Rust runtime workspace, PHP engine, runtime tests,
generated protocol sources, and the build scripts that produce the native and
WASI artifacts. It excludes the Spacefast control plane, dashboards, JavaScript
package consumers, infrastructure configuration, credentials, and build outputs.
