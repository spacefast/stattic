# Licensing

SpacePress is licensed by component. The rule: anything that runs inside
WordPress is GPL-2.0-or-later, because WordPress plugins are derivative works
of WordPress. Everything else is MIT.

- MIT: full text in [`LICENSE`](LICENSE).
- GPL-2.0-or-later: full text in
  [`licenses/GPL-2.0-or-later.txt`](licenses/GPL-2.0-or-later.txt).

## GPL-2.0-or-later

| Path | What |
|---|---|
| `migrate/zero/wp/` | the offline WordPress importer, licensed under GPL-2.0-or-later |
| `runtime/SpacePress/` | the PHP runtime, shipped as the `spacepress-runtime` plugin |
| `runtime/identity/` | the `spacepress-identity` plugin and its screens |
| `runtime/admin/` | the `spacepress-admin` plugin and its screens, emitted unless `admin: false` |
| `runtime/wp-plugin/` | the `spacepress-runtime` and `spacepress-app` plugin templates |

and every plugin that `spacepress build` and `spacepress plugin` emit from them.

## MIT

| Path | What |
|---|---|
| `src/`, `crates/`, `build.rs`, `Cargo.toml`, `Cargo.lock` | the Rust compiler and CLI |
| `runtime/client/` | the typed TypeScript client |
| `types/` | the `"spacepress"` type declarations |
| `packages/` | the npm packages, except the bundled WordPress code in `@spacepress/blocks` (below) |
| `examples/`, `www/`, `skills/`, `scripts/`, `tests/` | examples, the website and documentation, agent skills, tooling and tests |

and everything else not listed above.

## How the two meet

The compiler binary embeds the GPL runtime files as opaque bytes and writes
them into the plugins it emits: the runtime unchanged, the plugin headers with
their version placeholders filled in. It never links against or derives from
them; where a command needs the runtime, it runs it as a separate PHP process.
The runtime is to the compiler what an installer's payload is to the
installer: distributed together, but separate works. The compiler's sources
stay MIT, and because the packaged crate carries the embedded runtime,
`Cargo.toml` declares the package as `MIT AND GPL-2.0-or-later`.

The plugins written under `sp/dist/wp-content/plugins` (`spacepress-runtime`,
`spacepress-identity`, `spacepress-admin`, `spacepress-app-<name>`) are
GPL-2.0-or-later and each carries the license text. Files the compiler
generates from your `spacepress.config.ts` and `sp/` (`schema.php`,
`functions.php`, migrations, `sp/_generated/db.d.ts`,
`sp/_generated/openapi.json`, `sp/_generated/client/types.ts` and `schema.ts`)
are yours. The client's `index.ts`, `react.ts` and `README.md` are copied from
`runtime/client/` and stay MIT; the copy ships with its LICENSE. An app that
declares a `store()` also gets `commerce.ts`, `cart-store.ts` and
`cart-react.ts`, copied from the same directory under the same terms; that
LICENSE then names the Woo Store SDK they are adapted from (see below).

## npm packages

| Package | License |
|---|---|
| `spacepress` | MIT. It downloads the compiler archive, which carries this file, both license texts and every upstream notice. |
| `create-spacepress` | MIT |
| `@spacepress/astro` | MIT |
| `@spacepress/blocks` | `MIT AND GPL-2.0-or-later`: its own code (`bin/`, `dist/`) is MIT; `vendor/wp.cjs` bundles the WordPress block packages (`@wordpress/blocks`, `@wordpress/block-library`, `@wordpress/element` and their dependencies), which are GPL-2.0-or-later, and is distributed under GPL-2.0-or-later. The package ships both texts and `vendor/NOTICE.md`, which lists every bundled package, its version, license and source. |

## Third-party code

Third-party code keeps its own license and is not relicensed by this file:

| Path | What |
|---|---|
| `runtime/SpacePress/vendor-prefixed/` | WordPress PHP toolkit (GPL-2.0-or-later) and the libraries it bundles |
| `runtime/identity/vendor-prefixed/` | passkey, JWT and TOTP libraries |
| `runtime/identity/build/` | the bundled identity screens |
| `runtime/admin/build/` | the bundled admin screens |
| `examples/emdash/` | examples adapted from EmDash (MIT, Copyright Cloudflare Inc.); each keeps its upstream license file |
| `migrate/src/zero/client08/preact/` | Preact (MIT), for imported client pages |

| `runtime/client/commerce.ts`, `runtime/client/cart-store.ts`, `runtime/client/cart-react.ts` | the Woo cart client, its optimistic store and its React hooks, adapted from Spacefast's Woo Store SDK ([spacefast/woo-store-sdk](https://github.com/spacefast/woo-store-sdk), MIT): the cart contracts, the error handling and the optimistic quantity and removal updates follow `@woo/storefront-core` (`packages/core/src/contracts.ts`, `transport.ts` and `resources/cart.ts`). That repository has no root LICENSE file; each of its packages declares MIT in its `package.json`. |
| `tests/fixtures/openapi-3.1.schema.json` | the OpenAPI Initiative's JSON Schema for OpenAPI 3.1 documents (Apache-2.0), unmodified; a test validates the generated `openapi.json` with it and no build ships it |

Each vendored runtime directory carries a `THIRD-PARTY-LICENSES.txt` listing
every package and its license. Rust dependencies are under their own
permissive licenses, recorded in each crate's metadata and license files
(`Cargo.lock` names the crates and versions); every release archive retains
them under `licenses/rust/`.
