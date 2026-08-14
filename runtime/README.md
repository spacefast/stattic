# Spacefast Engine

Spacefast Engine is the open-source PHP runtime that serves static spaces from precomputed
artifacts: versions, route pointers, redirects, headers, access policy, and proxy routes.
It is the local half of the Spacefast product — it runs anywhere PHP runs, and a committed
version tree plus this engine is a complete self-hosted site. Exit is real.

External PRs are welcome. Supporting freedom on the web is the point.

> Pre-1.0 notice: contracts are unstable. Management/upload API shapes, artifact schemas,
> and storage layout can change without compatibility shims until 1.0.

## How It Serves

The runtime has two modes:

- **Serving**: route requests for a hostname to the active committed version using only
  local compiled artifacts. The public hot path never calls the Spacefast API, never parses
  `_redirects`/`_headers`/`sf.jsonc` at request time, and never scans directories.
- **Management**: accept scoped version create/finalize/delete, route pointer updates,
  tombstones, repair, delete, and journal drain from a trusted control plane,
  authenticated with EdDSA (Ed25519) JWTs.

The runtime vocabulary is only **versions and routes**. A route pointer
(`spaces/<spaceId>/routes/<routeName>.json`) points at a version; cloud concepts like
channels compile down to route pointers before they reach the runtime. The runtime never
learns about users, teams, plans, billing, or domain ownership.

## Install

### WP.Cloud (first-party)

Runtime archives are published as GitHub release assets; the control plane never serves
their bytes. Provisioning resolves the latest promoted release, lands the CLI-only resident
`installer.php` over SSH, and installs it immediately. Existing post-migration boxes update
through their authenticated `/engine/update` management route. Calls from the removed
pre-migration pull updater receive inert `200` responses while
`SPACEFAST_LEGACY_RUNTIME_UPDATES_FROZEN=true`; those responses contain no release metadata.
The installer is not an HTTP entrypoint. Bundle contents are defined by
`engine-manifest.json`, never by scanning directories. Engine install never touches committed
space storage or route indexes.

### Self-host

Requirements: PHP 8.2+ with `sodium`, `curl`, and `zip` extensions, plus the bundled Linux native executable (`stattic-runtime`). The official engine ZIP includes it with executable permissions. Local development may override it with `SPACEFAST_RUNTIME_BIN`. Unicode paths work without `ext-intl`; when available, the runtime uses it as the NFC fast path and otherwise uses its bundled normalizer.

1. Run `installer.php` against an engine ZIP. It stores the complete engine under an immutable
   `htdocs/.stattic/releases/<release>/` directory and atomically publishes the relative path
   in `.stattic/active-release`. The loader reads this small data file once per request, so an
   atomic rename becomes visible without depending on PHP OPcache or realpath-cache timing.
2. Route ordinary requests through the installed `index.php`. Every installed public PHP
   entrypoint is the same loader: it pins the active release once, then executes only that
   release. WP.Cloud's `custom-redirects.php` loader returns for the canonical direct
   `/__spacefast/*.php` entrypoints after its environment bootstrap finishes. The loader is
   reinstalled whenever the payload's loader bytes differ from the installed ones
   (`.stattic/loader-version` holds their sha256), so a loader fix reaches a converged box.
3. Deny direct web access to `.stattic/**` at the webserver level. The engine also
   denies it itself, defense in depth is still good practice.
4. Provide configuration (see below).
5. Serve committed versions: push them through the create → upload → finalize flow.

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
| `SPACEFAST_API_BASE_URL`        | Private control-plane transport. Used for JWKS refresh and callbacks only; never emitted to public pages.                                                                                                                    |
| `SPACEFAST_BROWSER_API_URL`     | Browser-safe public API origin for the same-host SDK. HTTPS in production; loopback HTTP is accepted for local development.                                                                                                  |
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
GET  /__spacefast/api.php?route=/spaces/{spaceId}/versions/{versionId}/files&view=source|served&path=&public_only=&channel=&prefix=&q=&cursor=&limit=
PUT  /__spacefast/api.php?route=/spaces/{spaceId}/routes/{routeName}
GET  /__spacefast/api.php?route=/spaces/{spaceId}/functions/logs&limit=&cursor=&requestId=&handlerName=
PUT  /__spacefast/api.php?route=/spaces/{spaceId}/tombstones
POST /__spacefast/api.php?route=/spaces/{spaceId}/repair
POST /__spacefast/api.php?route=/spaces/{spaceId}/delete

PUT  /__spacefast/upload.php?route=/spaces/{spaceId}/blobs/{sha256}
POST /__spacefast/upload.php?op=fetch&upload_id={deploySessionId}&path={canonicalObjectPath}   stage a file fetched from an HTTPS URL
```

Management JWTs (`aud = "stattic-runtime-management"`) carry `runtime_instance_id`,
`operation_id`, `action`, `exp`, `nbf`, `jti`, and action-specific scope (`space_id`,
`version_id`, `route_name`). Upload JWTs (`aud = "stattic-runtime-upload"`) are
session-scoped; each content-addressed `PUT` is authorized against the session's declared
manifest, while source URL fetch retains its session/path route. The two
`.../versions/{versionId}/file*` routes are the read-only
file-fetch surface for the scan pipeline; they take a short-TTL
`aud = "stattic-runtime-file-fetch"` JWT pinned to the space, version, and path or hash.

`PUT .../routes/{routeName}` accepts `version_id`, optional `config`, and optional
hostname intent (`production_hostnames`, `noindex_production_hostnames`,
`version_hostnames`, `host_canonical_redirects`). Canonical access is projected in
`config.authorization`. Owner access has no separate deny/firewall policy; platform
safety and provider takedown controls remain operator-owned.

## Storage Layout

```text
htdocs/.stattic/active-release              atomic relative path to the active release
htdocs/.stattic/loader-version              sha256 of the installed public loader payload
htdocs/.stattic/releases/<release>/         immutable managed engine, native binary, manifest
htdocs/.stattic/storage
  runtime/uploads/                          staged upload sessions
  runtime/jwks.json                         JWKS cache
  runtime/jti/                              management JWT replay cache
  runtime/callbacks/                        local callback journal
  runtime/journal.jsonl                     management event journal (rolls aside
                                            to journal-<stamp>.jsonl at 8 MiB)
  runtime/function-logs/<spaceId>/          tenant Functions output, one file per UTC day
  spaces/<spaceId>/versions/<versionId>/    committed files + compiled serving artifacts
  spaces/<spaceId>/routes/<routeName>.json  mutable route pointers
  spaces/<spaceId>/policy.json              deny-only Firewall policy
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
classes), canonical access (private/team/public roots, path overrides, Links,
People, claim preview, immutable-version hosts), declared upload sessions (manifest validation, path
policy, batch tar, chunked parts/complete), header operations, and proxy egress pinning
at finalize.

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
