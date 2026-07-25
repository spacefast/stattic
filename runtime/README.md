# Stattic Runtime

Stattic Runtime is the PHP serving engine for precomputed Stattic bundles. Spacefast uses
it as its managed serving plane, but the Runtime and its compiled artifact contract are
portable. It serves versions, route pointers, redirects, headers, access policy and proxy
routes without parsing source on the public request path.

External PRs are welcome. Supporting freedom on the web is the point.

> Pre-1.0 notice: contracts are unstable. Management/upload API shapes, artifact schemas,
> and storage layout can change without compatibility shims until 1.0.

## How It Serves

The runtime has three modes:

- **Serving**: route requests for a hostname to the active committed version using only
  local compiled artifacts. The public hot path never calls the Spacefast API, never parses
  `_redirects`/`_headers`/`sf.jsonc` at request time, and never scans directories.
- **Bundle admission**: verify and install a `stattic.bundle.v1` payload without Rust,
  `proc_open`, or any server-side build.
- **Management**: accept scoped version create/finalize/delete, route pointer updates,
  tombstones, repair, delete, import/export, and journal drain from a trusted control
  plane, authenticated with EdDSA (Ed25519) JWTs.

The runtime vocabulary is only **versions and routes**. A route pointer
(`spaces/<spaceId>/routes/<routeName>.json`) points at a version; cloud concepts like
channels compile down to route pointers before they reach the runtime. The runtime never
learns about users, teams, plans, billing, or domain ownership.

## Install

### WP.Cloud (first-party)

The control plane builds the engine bundle (`runtime-engine.zip`, served at
`/v1/internal/runtime-engine.zip`) and installs it through `installer.php`. The bundle
contents are defined by `engine-manifest.json`, never by scanning directories. Engine
install never touches committed space storage or route indexes.

### Self-host

`portable-static` bundle admission requires PHP 8.2+ and no native executable. Source
finalization and Zero are separate optional capabilities during the pre-1.0 extraction.

```sh
php -d disable_functions=proc_open,exec,shell_exec,system,passthru \
  runtime/bin/admit.php --bundle ./site.stattic --storage ./.stattic/storage
```

1. Copy the engine into your web root: engine files live under `htdocs/.stattic/engine`,
   private storage under `htdocs/.stattic/storage` (create it empty).
2. Route ordinary requests to the entrypoint shim. `index.php` is an alias of
   `entrypoint-shim.php`, which requires `.stattic/engine/init.php`.
   `custom-redirects.php` also loads that engine, but returns for the canonical
   `/__spacefast/*.php` entrypoints so WP.Cloud can execute those files directly
   after its own environment bootstrap finishes.
3. Deny direct web access to `.stattic/**` at the webserver level. The engine also
   denies it itself, defense in depth is still good practice.
4. Provide configuration (see below).
5. Serve committed versions: either import an export archive through the management API
   or push versions through the normal create → upload → finalize flow.

Public serving needs only committed versions, the engine, and route pointers. It never
depends on the Spacefast API.

## Configuration

Config values resolve through `Atomic_Persistent_Data`. On WP.Cloud, the control
plane stores the canonical `SPACEFAST_*` values as persistent data and the
runtime entrypoints execute as direct PHP files, after WP.Cloud has finished
defining the DB constants needed to decrypt that data. Local tests and e2e shims
inject the same Atomic shape before runtime PHP is loaded.

| Key                             | Purpose                                                                                                                                                                                                                      |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SPACEFAST_API_BASE_URL`        | Control-plane base URL. Used for JWKS refresh and callbacks only; never for public serving.                                                                                                                                  |
| `SPACEFAST_MANAGEMENT_HOSTNAME` | The only hostname that accepts `/__spacefast/api.php?route=...` and `/__spacefast/upload.php?op=...`. Public hosts reject management paths before JWT parsing; ordinary public paths on the management hostname fail closed. |
| `SPACEFAST_RUNTIME_INSTANCE_ID` | This runtime's identity. Every management/upload JWT must be scoped to it.                                                                                                                                                   |
| `SPACEFAST_RUNTIME_JWKS_PATH`   | Optional. Path to a local JWKS file for self-hosted runtimes that should never call the API.                                                                                                                                 |
| `SPACEFAST_RUNTIME_JWKS_B64`    | Optional. Base64-encoded inline JWKS JSON. WP.Cloud receives this through persistent data.                                                                                                                                   |

When no local JWKS is provisioned, the runtime fetches and caches
`<SPACEFAST_API_BASE_URL>/.well-known/spacefast-runtime-jwks.json`.

## Management API

All management routes live on the management hostname only.

```text
GET  /__spacefast/health.php                              public-safe, no auth
GET  /__spacefast/api.php?route=/state
POST /__spacefast/api.php?route=/events/drain
POST /__spacefast/api.php?route=/spaces/{spaceId}/versions
GET  /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/uploads     upload-session status, used for resume
POST /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/finalize
POST /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/delete
GET  /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/file&path={path}
GET  /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/files/by-hash/{sha256}
GET  /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/scan-manifest
PUT  /__spacefast/api.php?route=/spaces/{spaceId}/routes/{routeName}
PUT  /__spacefast/api.php?route=/spaces/{spaceId}/tombstones
POST /__spacefast/api.php?route=/spaces/{spaceId}/repair
POST /__spacefast/api.php?route=/spaces/{spaceId}/delete

PUT  /__spacefast/upload.php?op=file&upload_id={deploySessionId}&path={canonicalObjectPath}
PUT  /__spacefast/upload.php?op=file&upload_id={deploySessionId}&path={canonicalObjectPath}&part_number={partNumber}
POST /__spacefast/upload.php?op=file&upload_id={deploySessionId}&path={canonicalObjectPath}&complete=1
POST /__spacefast/upload.php?op=fetch&upload_id={deploySessionId}&path={canonicalObjectPath}   stage a file fetched from an HTTPS URL
POST /__spacefast/upload.php?op=batch&upload_id={deploySessionId}
```

Management JWTs (`aud = "stattic-runtime-management"`) carry `runtime_instance_id`,
`operation_id`, `action`, `exp`, `nbf`, `jti`, and action-specific scope (`space_id`,
`version_id`, `route_name`). Upload JWTs (`aud = "stattic-runtime-upload"`) are
session-scoped; each `PUT` is authorized against the session's declared manifest, not a
per-file token. The two `.../versions/{versionId}/file*` routes are the read-only
file-fetch surface for the scan pipeline; they take a short-TTL
`aud = "stattic-runtime-file-fetch"` JWT pinned to the space, version, and path or hash.

`PUT .../routes/{routeName}` accepts `version_id`, optional `config`, and optional
hostname intent (`production_hostnames`, `noindex_production_hostnames`,
`version_hostnames`, `host_canonical_redirects`). Access rules live in the unified
`config.policy` document; password verifier maps live in `config.secrets`.

## Export And Import

Exports and imports are chunked management jobs, so large spaces move without long
blocking requests:

- `POST .../exports` creates an export job; `POST .../exports/{id}/step` advances it;
  `GET .../exports/{id}/archive` downloads the finished ZIP.
- `POST .../imports` creates an import job (optionally with a control-plane-minted
  `version_id_map`); `PUT .../imports/{id}/archive` attaches the archive;
  `POST .../imports/{id}/step` materializes versions.

The archive contains `spacefast_export_v1/spacefast.json` (format, runtime schema, source
space id, export id, created time, version ids) plus the committed version trees under
`spacefast_export_v1/versions/<versionId>/`. That is the only layout imports accept.
Exports carry no ownership, billing, or
domain data — a downloaded export plus this engine is a complete self-hosted site.

## Storage Layout

```text
htdocs/.stattic/engine                      engine code (replaced atomically on install)
htdocs/.stattic/storage
  runtime/uploads/                          staged upload sessions
  runtime/jwks.json                         JWKS cache
  runtime/jti/                              management JWT replay cache
  runtime/callbacks/                        local callback journal
  runtime/journal.jsonl                     management event journal
  spaces/<spaceId>/versions/<versionId>/    committed files + compiled serving artifacts
  spaces/<spaceId>/routes/<routeName>.json  mutable route pointers
  spaces/<spaceId>/access-policy.json       compiled space access policy
  routes/current.php, routes/generations/   active route generations
```

## Native finalization

The Rust finalizer owns upload materialisation, configuration, routing,
Markdown and Gutenberg rendering, layouts, `theme.json`, HTML decoration,
template artifacts, and Zero compilation. PHP authenticates the management
request, invokes the installed native binary, commits activation state, and
serves the generated artifacts. There is no PHP compiler or fallback lane.

## Testing

The runtime has a self-contained test suite in `tests/`. One way to run it:

```sh
runtime/tests/run.sh
```

The entry script gates in order:

1. `php -l` on every PHP file under `runtime/`;
2. `php tests/unit.php` — pure-function unit tests (proxy egress policy, the
   platform-managed header denylist and placeholder expansion, upload path policy,
   PHP-like safety policy);
3. `bun test runtime/tests` — HTTP integration tests. Each test file installs the
   engine from `engine-manifest.json` into a temp web root, serves it with `php -S`
   on localhost, and drives the real management/upload/serving APIs with Ed25519
   JWTs minted by the harness (`tests/harness.ts`).

Coverage: routing precedence (redirects/rewrites/SPA/nearest-404/directories/robots
classes), access policy (password, basic auth, immutable-version hosts, plan
restrictions, reason codes), upload sessions (declared + open, caps, path policy,
batch tar, chunked parts/complete, finalize-derived manifests), export/import
(roundtrip, ID remap, access-policy carry, zip-bomb and inert-PHP guards), header
operations, and proxy egress pinning at finalize.

Requirements: PHP 8.2+ with `sodium` and `zip`, and bun. Deterministic; no network
beyond localhost. Safe for CI.

## Safety Invariants

- User uploads are never executed. PHP-like files are inert bytes served as text/download.
- `.htaccess`, `.user.ini`, `__spacefast`, `.spacefast/storage`, `.spacefast/engine`
  (plus the legacy `.stattic/...` spellings), and engine filenames are rejected at
  upload and blocked at serve time.
- Proxy egress validates resolved upstream IPs and pins connections to the validated
  addresses: loopback, link-local, cloud-metadata, RFC1918/ULA, and Spacefast-internal
  hosts are denied at request time (anti-DNS-rebinding).
- Unknown route/lookup action types fail closed.
