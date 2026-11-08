# Spacefast Engine

Spacefast Engine is the open-source PHP runtime that serves static spaces from precomputed
artifacts: versions, route pointers, redirects, headers, access policy, and proxy routes.
It is the local half of the Spacefast product — it runs anywhere PHP runs, and a committed
version tree plus this engine is a complete self-hosted site. Exit is real.

External PRs are welcome. Supporting freedom on the web is the point.

Authored native PHP Functions are disabled across HTTP, CLI invoke, and cron
dispatch. Their routes return `503 tenant_php_unavailable` with
`Cache-Control: no-store` before tenant code executes. PHP-FPM retains raw
request credentials outside mutable PHP globals, so header scrubbing cannot
make an in-process tenant handler safe. Re-enabling PHP Functions requires a
separate execution boundary that receives no platform credentials. Static
content, Zero, Functions workers, proxy routes, and non-PHP cron and invoke
targets remain available. Their request maps
remove `X-SF-Authorization` and platform cookies while preserving application
`Authorization` and Users app cookies.

Released runtimes must keep serving existing artifacts while the control plane
and CLI advance. Management/upload protocols, stored artifacts, and credentials
must remain readable during rollout. New features may require an engine capability;
that does not invalidate an existing publication. See the
[release compatibility check](../RUNNING.md#release-compatibility).

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

## Space mail

Space mail is sent once through the site's `wp_mail` transport. The runtime
returns whether the transport accepted the message. A positive daily allowance
and the primary domain in the route config grant email to a Space.
Any valid mailbox at that exact domain can send. Aliases and subdomains cannot.
The route's sender domain, carried in a signed grant, authorizes each send. The
runtime cannot recheck Atomic's current primary under FPM, so a stale route can
send from a demoted domain until its refresh lands.
Mail needs no application database or control-plane mailbox, queue or drainer.

Functions and Zero require `from`; the PHP helper defaults to
`noreply@<primary-domain>`. Replies can go to a different `replyTo` address.
Email remains gated by the existing email feature and daily recipient allowance.
See [RUNNING.md](../RUNNING.md#space-mail-on-wp-cloud) for DNS and provider limits.

The direct-mail cutover discards pending messages in the old Space mail outbox.
It does not replay them through the new transport. Platform account and
notification email belongs to the control plane and is never routed through this transport.
The host-owned `/__spacefast/mail.php` entrypoint requires a signed, metered
Space grant; there is no unmetered production entrypoint.

## Shared HTML source

HTML pages may reference shared fragments with exactly this comment syntax:

```html
<!--#include virtual="/parts/header.html" -->
```

The filename contains one or more ASCII letters, digits, `_` or `-`, followed by
`.html`. Paths are relative to the uploaded site root. Quotes, spaces and keyword
case are literal; nested directories and alternate directive forms are outside
this contract. Fragments may use the same syntax to reference other fragments.

Only comments beginning with the exact `<!--#include virtual="/parts/` prefix
opt into expansion. Other forms, including legacy SSI comments, stay literal.
Malformed references within that claimed prefix, missing fragments, cycles and
exceeded expansion bounds fail publication visibly.

Finalization expands actual HTML comment nodes once before normal rendering and
decoration, preserving surrounding bytes. Strings in scripts, styles and
attributes remain literal, as do unrelated comments. Referenced fragments are
private compile inputs; unreferenced files under `parts/` remain ordinary pages.
Include-bearing pages compile each publish, while ordinary pages retain their
incremental output reuse. Reads, nesting, expansion and staged-path access are
bounded. Visitors receive complete HTML through the existing serving path.

Each channel's template overrides run through the same complete content pipeline
as the base: include expansion, Markdown/block rendering, layouts, theme styles,
HTML decoration and preview/icon URL handling. The declared-template limit applies
before page generation; dependent pages consume the normal publication budgets,
not extra template slots. Derived variants participate in the existing
template-output purge contract.

File-only channel overrides reuse compiled base output. Channels that change
rendering inputs retain only the source bytes the pipeline reads, using immutable
links where supported; unrelated media stays in the CAS. Unchanged ordinary pages
reuse base served identities when the existing rendering-context digest matches.
Common base warnings are emitted once, and channel-specific diagnostics identify
their route in the receipt.

This is a producer-neutral source contract, with no producer detection, extra
manifest or client-side assembly.

## Install

### WP.Cloud (first-party)

Runtime archives are published as GitHub release assets; the control plane never serves
their bytes. Provisioning resolves the latest promoted release, lands the CLI-only resident
`installer.php` over SSH, and installs it immediately. Existing boxes update through their
authenticated `/engine/update` management route.
The installer is not an HTTP entrypoint. Bundle contents are defined by
`engine-manifest.json`, never by scanning directories. Engine install never touches committed
space storage or route indexes.

### Self-host

Requirements: PHP 8.5 with `sodium`, `curl`, and `zip` extensions, plus the bundled Linux native executable (`stattic-runtime`). The engine rejects every other PHP minor: Spacefast owns one runtime platform and uses PHP 8.5 APIs directly. The official engine ZIP includes the native executable with executable permissions. Local development may override it with `SPACEFAST_RUNTIME_BIN`. Unicode paths work without `ext-intl`; when available, the runtime uses it as the NFC fast path and otherwise uses its bundled normalizer.

1. Run `installer.php` against an engine ZIP. It stores the complete engine under an immutable
   `htdocs/.stattic/releases/<release>/` directory and atomically publishes the relative path
   in `.stattic/active-release`. The loader reads this small data file once per request, so an
   atomic rename becomes visible without depending on PHP OPcache or realpath-cache timing.
2. Route ordinary requests through the installed `index.php`. Every installed public PHP
   entrypoint is the same loader: it pins the active release once, then executes only that
   release. WP.Cloud's `custom-redirects.php` loader returns for the canonical direct
   `/__spacefast/*.php` entrypoints after its environment bootstrap finishes. The loader is
   reinstalled whenever the payload's loader bytes differ from the installed ones
   (`.stattic/loader-version` holds their sha256), so a loader fix reaches a synced box.
3. Deny direct web access to `.stattic/**` at the webserver level. The engine also
   denies it itself, defense in depth is still good practice.
4. Provide configuration (see below).
5. Serve committed versions: push them through the create → upload → finalize flow.

Public serving needs only committed versions, the engine, and route pointers. It never
depends on the Spacefast API.

On WP Cloud the engine can also run from the Spacefast chroot, where every site on the client
shares one copy: `/scripts/sf-engine-<revision>/` with its binary at
`/usr/local/bin/sf-bin-<revision>/stattic-runtime`, and each environment's
`sf-engine-<environment>-{latest,previous}` and `sf-bin-<environment>-{latest,previous}` links
beside them. A site opts in when its WP Cloud persistent data names one of those channels in
`SPACEFAST_RUNTIME_ENGINE_SOURCE`, for example `chroot/production/latest`. The control plane
sets it through the WP Cloud API. The loaders, and the control plane's scheduled and SSH
commands, still resolve the site's own release first and ask its
`engine/shared/engine-selection.php` which engine to run. The site's install root keeps its storage and its publication lock either
way, and a missing or incomplete chroot falls back to the site's release. Health and the
engine-update receipt report `engine_source`. Health also reports `engine_source_reason`
(`chroot`, `no_persistent_data`, `unselected`, `chroot_missing`, `chroot_engine_unsupported` or
`chroot_binary_missing`) and the `chroot_revision` the selected channel resolved to, so a site
that selects the chroot and still runs its own release says why.

## Configuration

Config values resolve through `Atomic_Persistent_Data`. On WP.Cloud, the control
plane stores the canonical `SPACEFAST_*` values as persistent data and the
runtime entrypoints execute as direct PHP files, after WP.Cloud has finished
defining the DB constants needed to decrypt that data. Local tests and e2e shims
inject the same Atomic shape before runtime PHP is loaded.

| Key                             | Purpose                                                                                                                     |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `SPACEFAST_API_BASE_URL`        | Private control-plane transport. Used for JWKS refresh and callbacks only; never emitted to public pages.                   |
| `SPACEFAST_BROWSER_API_URL`     | Browser-safe public API origin for the same-host SDK. HTTPS in production; loopback HTTP is accepted for local development. |
| `SPACEFAST_RUNTIME_INSTANCE_ID` | This runtime's identity. Every management/upload JWT must be scoped to it.                                                  |
| `SPACEFAST_RUNTIME_JWKS_PATH`   | Optional. Path to a local JWKS file for self-hosted runtimes that should never call the API.                                |
| `SPACEFAST_RUNTIME_JWKS_B64`    | Optional. Base64-encoded inline JWKS JSON. WP.Cloud receives this through persistent data.                                  |

When no local JWKS is provisioned, the runtime fetches and caches
`<SPACEFAST_API_BASE_URL>/.well-known/spacefast-runtime-jwks.json`.

### PHP error reporting

Every engine entrypoint and the installer enforce `error_reporting(E_ALL)` and
`log_errors=On`. The provider or self-hosted PHP configuration still owns the
error-log destination. `display_errors` stays off so warnings, paths, and stack
details never become part of a visitor response or a machine-readable CLI
receipt. Runtime PHP does not use the `@` error-control operator: recoverable
failures may still return a structured fallback, but their PHP diagnostics are
always available in the configured error log.

## Management API

Management routes use the site's primary hostname. Their JWT audience, action,
runtime-instance, operation, and replay checks are the authorization boundary;
the hostname is not a credential.

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

`authorization.teamId` and `authorization.membershipEpoch` (both optional) let a Grant
with `audience.kind = "team"` admit any `member:<id>` authority the control plane signs
for that team. The Grant's generation digest is
`sha256("<grantId>:<generation>:<membershipEpoch>")`, so moving the epoch retires every
member session at its next revalidation; adding a member needs no projection write.
Grants expanded per member (`external` audiences with issuer `spacefast-membership`)
keep working unchanged.

## Security observations

The control plane stores authenticated API-key-to-Space exchanges, managed outbound
metadata, and recorded visitor-session lifecycle changes in `activity_events`.
These `security.*` codes reuse the activity catalog and bypass notification and
webhook fan-out. They are excluded from team-plan deletion; the audit journal's
security-class retention work owns the eventual retention policy. There is no
new table or migration. Dashboard activity reads still apply their plan window;
investigations that need older records must use the stored journal.

An authenticated `sfa_` exchange records stable credential, grant, subject, creator,
Space and runtime IDs, result, status, time, duration, and hashed request correlation.
Forwarded IP and user-agent data use domain-separated HMACs under the application
auth key; rotation changes those pseudonyms. Country is restricted to two uppercase
letters, and the record identifies the network evidence as runtime-forwarded.
Unknown credentials and invalid serving hosts rejected before authentication have
no authenticated exchange record. No credential, cookie, header value, URL path,
query string, or request/response body is retained by these observations.

Runtime observations cover the shared PHP HTTP adapter (including platform relay
traffic), proxy policy/DNS denials, and Zero's native fetch host. They carry only
destination host, method, permission, result, status, duration, and bytes where
observed. PHP uses cURL's measured transfer bytes. Zero reports a buffered response
length, not wire bytes, and leaves request bytes unknown. Redirect hops are not
separate records. Direct PHP/WordPress network primitives bypass this adapter;
this is not a complete egress inventory. A killed native runner cannot return
its observations. Zero retains at most 32 observations per invocation and reports
the remaining count to the runtime.

Session observations cover durable issuance, cookie renewal, record deletion,
degradation, failed record deletion, and handoff rotation. Their IDs hash the Space and a random session
ID, never an access-bearing cookie/token. Outbound records use the same session
hash and request correlation where available. Stateless anonymous sessions and
requests with no managed outbound call have no per-request security record.
Logging does not change access rules: a signed cookie's hot path does not consult
the revocation record; cold revalidation occurs at the existing 900-second claim
TTL, generation changes, and stale authority checks. Record deletion therefore
does not mean an already issued cookie stops working immediately.

The runtime's canonical record store and striped locks reserve at most 1,200
observation attempts per Space per hour, shared across workers and versions.
Reservations persist before journal appends and are not refunded on append failure.
Each reservation retains one metadata-only unfinished append in its Space's budget
record. The next writer or drain recovers it into the canonical journal using the
original observation ID; a crash after append can replay that ID, which intake
deduplicates. If recovery cannot append, it persists a dropped count instead.
Unreadable or corrupt counters refuse admission. Both budget and journal locks
are nonblocking: journal contention increments the durable dropped count; budget
contention creates one exclusive gap marker per Space/hour without the budget
lock. It records a minimum of one lost observation, not an exact count. The
drain retries these markers; the maintenance retention owner expires them after
24 hours, including undelivered markers during a long outage, to bound storage.
Failure to retain a marker emits the storage-failure diagnostic. Delivery uses the existing
runtime journal and authenticated management drain. API intake validates strict
observation schemas, runtime placement and version ownership, then persists before
acknowledgement. Malformed runtime observations, stale placement/version ownership,
and timestamps more than five minutes ahead are discarded and acknowledged so they
cannot block later journal entries. `security.telemetry_discarded` warns with a
fixed reason and trusted runtime ID, plus a validated event digest when available;
it never includes the rejected payload. These warnings share the production logger's
line budget and are not durable gap rows. Infrastructure failures remain pending
for retry. Postgres's shared counters cap runtime/Functions rows together at
1,200 per Space per hour. Exchange rows have a separate 1,200-per-Space hourly
budget. Gap markers are additional bounded rows. Beyond a budget, individual
destinations/identities are lost; gaps are explicitly recorded, not sampled silently.

Producer loss reports are cumulative, so retries must not be added together.
Postgres stores the maximum producer count and separate counts of API delivery
attempts dropped by intake; a lost-ack replay can increment the latter again.
Exchange overflow counters count attempts by outcome. Gap rows identify their
counter through `counterDigest`; the canonical counter rows retain these counts
for 30 days after their last update. Immutable gap rows contain only a minimum
at marker creation. Local storage failure cannot retain an exact count: it emits
`security.telemetry_storage_failed` without exception text and lets serving continue.
Security observations are excluded from the default customer activity feed;
an explicit security event code filter retrieves them. Rotated runtime journal
generations are kept, so undrained evidence survives a long outage.

Drain and acknowledgement requests must declare `security_telemetry: true`.
An older reader receives `409 runtime_event_reader_incompatible` before events
are returned or a cursor is committed. Deploy the supporting control plane before
activating this runtime or the Functions producer. Keep that reader support in any
control-plane rollback while newer producers or retained security records exist;
downgrading the runtime removes its compatibility guard. Restore a compatible
reader to resume a blocked drain rather than acknowledging records manually.

## WordPress API

A Space with managed content exposes WordPress's own REST API at `/wp-json/`. There is no
Spacefast-shaped wrapper around it and no per-endpoint proxy: the request reaches
WordPress, and WordPress answers.

One edge rule makes it reachable. A request to `/wp-json/**` (or `/?rest_route=`, the
spelling WordPress uses without pretty permalinks) is admitted by the **same access engine
that serves the Space's pages** — the same Grants, the same fences, the same denials. It is
never wider than the page-serving policy, because there is no second policy. A credential
presented in `X-SF-Authorization` is resolved to a principal, and its Grant capabilities
project the WordPress role for that one request (`content.publish` → `editor`,
`access.manage` → `administrator`). Without a credential a public Space gives WordPress's
normal unauthenticated answer, and a protected Space refuses exactly as it refuses a page.

`Authorization` is deliberately untouched — it belongs to the customer's application and to
WordPress's own auth schemes.

THE canonical example. Copy it verbatim wherever this is documented:

```bash
curl -H "X-SF-Authorization: Bearer $SPACEFAST_TOKEN" \
  https://your-space.spacefast.app/wp-json/wp/v2/posts
```

The `sfm_` token returned by `sf share token create` works directly in this
header for private pages and Zero requests. No separate exchange call is needed.
Its Grant must cover the requested path and serving target. Zero writes also
require the corresponding write capability. The runtime exchanges the token for
a short-lived host-bound proof and checks the current Grants for each request.
Keep the token out of URLs.

Discovery is WordPress's own: `/wp-json/` is a self-describing index, and every collection
publishes its schema at `OPTIONS /wp-json/wp/v2/<collection>`.

## Storage Layout

```text
htdocs/.stattic/active-release              atomic relative path to the active release
htdocs/.stattic/loader-version              sha256 of the installed public loader payload
htdocs/.stattic/releases/<release>/         immutable managed engine, native binary, manifest
htdocs/.stattic/storage
  runtime/uploads/                          staged upload sessions
  runtime/jwks.json                         JWKS cache
  runtime/jti/                              management JWT replay cache
  runtime/journal.jsonl                     management event journal (rolls aside
                                            to journal-<stamp>.jsonl at 8 MiB)
  spaces/<spaceId>/versions/<versionId>/    committed files + compiled serving artifacts
  spaces/<spaceId>/routes/<routeName>.json  mutable route pointers
  spaces/<spaceId>/policy.json              deny-only Firewall policy
  spaces/<spaceId>/content/releases/<rev>/  compiled Payload content releases, with
                                            content/active-release naming the live one
  spaces/<spaceId>/content-media/           Space media the content API serves
  routes/current.json, routes/previous.json active + superseded route pointers
  routes/shards/<name>.php                  host lookup shards the pointers name
```

## Static byte ranges

The PHP serving fallback supports one `bytes` range per GET, including suffix
and open-ended ranges. It returns 206 with `Content-Range` and the selected
`Content-Length`, or 416 with `Content-Range: bytes */<length>` when no bytes
satisfy the range. HEAD ignores Range and reports the full representation length.
Malformed or multipart ranges receive the complete representation.

ETag preconditions run before range selection. If-Range requires the current
strong ETag; a weak, stale, or date validator receives the full representation
because this lane does not emit Last-Modified. PHP emits `private, no-store` for
partial responses. On recognized-extension public URLs, wp.cloud can replace the
wire cache policy and handle ranges itself. The direct provider contract checks
the partial bytes, a complete GET at the same URL, and a later range after the
cache warms. Private accelerated assets use the `;sf-private` alias to preserve
authorization and `private, no-store`. On the X-Accel lane, Nginx continues to own
validators, ranges, and HEAD.

## Native finalization

The Rust finalizer owns upload materialisation, configuration, routing,
Markdown and Gutenberg rendering, layouts, `theme.json`, HTML decoration,
template artifacts, and Zero compilation. PHP authenticates the management
request, invokes the installed native binary, commits activation state, and
serves the generated artifacts. There is no PHP compiler or fallback lane.

Exact Zero endpoints compile into a response-table action keyed by path. Its
`endpoints` map keeps each HTTP method's handler identity, artifact, execution
mode, and schema hash together. Its `methods` list controls the 405 `Allow`
response. Dispatch selects the request method, with HEAD falling back to GET.
Pattern endpoints keep using the compiled Zero route buckets.

The native compiler and PHP dispatcher ship in the same engine release. Install
that release before finalizing versions with method-indexed actions. Top-level
handler fields preserve rollback reads (GET when present, otherwise the first
handler). Existing stored actions remain readable; republish affected versions
to regenerate the handlers that an older finalizer overwrote.

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

Requirements: PHP 8.5 with `sodium` and `zip`, and bun. Deterministic; no network
beyond localhost. Safe for CI.

## Safety Invariants

- User uploads are never executed. PHP-like files are inert bytes served as text/download.
- `.htaccess`, `.user.ini`, `__spacefast`, `.spacefast/storage`, `.spacefast/engine`
  (plus the `.stattic/...` spellings of the engine's own install root), and engine
  filenames are rejected at upload and blocked at serve time.
- Proxy egress validates resolved upstream IPs and pins connections to the validated
  addresses: loopback, link-local, cloud-metadata, RFC1918/ULA, and Spacefast-internal
  hosts are denied at request time (anti-DNS-rebinding).
- Unknown route/lookup action types fail closed.
