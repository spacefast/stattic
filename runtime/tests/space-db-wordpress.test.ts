// Space data against real WordPress behind a persistent object cache: a row
// written through the data routes is what WordPress reads next, not its cached
// copy. Credentials are intentionally disposable and used only by this
// isolated stack.
//
// REQUIRES: docker for the database and WordPress images, and a PHP built with
// mysqli.
import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import {
  spaceDbRowsCreatedSchema,
  spaceDbRowsDeletedSchema,
  spaceDbRowsPageSchema,
  spaceDbRowsUpdatedSchema,
} from "../../packages/common/src/contracts/space-db.ts";
import { api, startRuntime } from "./harness.ts";
import { MYSQL_SETUP_TIMEOUT_MS, startMysqlContainer } from "./mysql-container.ts";
import { installWordpress } from "./zero-wordpress.ts";

const SPACE_ID = "spc_space_db_wordpress";

const jsonSchema = z.json();
type Json = z.infer<typeof jsonSchema>;

// Core's cache with its option and user groups kept in a file between
// requests, as a memcached drop-in keeps them on a server. Those groups hold
// only arrays, scalars and plain objects.
const OBJECT_CACHE = `<?php
require_once ABSPATH . WPINC . '/class-wp-object-cache.php';

const SPACEFAST_TEST_KEPT_GROUPS = array( 'options', 'users', 'user_meta', 'userlogins', 'useremail', 'userslugs' );

function spacefast_test_cache_file() {
	return WP_CONTENT_DIR . '/object-cache.data';
}
function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$saved = is_file( spacefast_test_cache_file() ) ? unserialize( (string) file_get_contents( spacefast_test_cache_file() ), array( 'allowed_classes' => array( 'stdClass' ) ) ) : null;
	$GLOBALS['wp_object_cache']->cache = is_array( $saved ) ? $saved : array();
	register_shutdown_function( static function () {
		$kept = array_intersect_key( $GLOBALS['wp_object_cache']->cache, array_flip( SPACEFAST_TEST_KEPT_GROUPS ) );
		file_put_contents( spacefast_test_cache_file(), serialize( $kept ) );
	} );
}
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) { return $GLOBALS['wp_object_cache']->add( $key, $data, $group, (int) $expire ); }
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) { return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, (int) $expire ); }
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { return $GLOBALS['wp_object_cache']->set( $key, $data, $group, (int) $expire ); }
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found ); }
function wp_cache_delete( $key, $group = '' ) { return $GLOBALS['wp_object_cache']->delete( $key, $group ); }
function wp_cache_incr( $key, $offset = 1, $group = '' ) { return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group ); }
function wp_cache_decr( $key, $offset = 1, $group = '' ) { return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group ); }
function wp_cache_flush() { return $GLOBALS['wp_object_cache']->flush(); }
function wp_cache_close() { return true; }
function wp_cache_add_global_groups( $groups ) { $GLOBALS['wp_object_cache']->add_global_groups( $groups ); }
function wp_cache_add_non_persistent_groups( $groups ) {}
function wp_cache_switch_to_blog( $blog_id ) { $GLOBALS['wp_object_cache']->switch_to_blog( $blog_id ); }
function wp_cache_reset() {}
`;

test(
  "an option or user written through Space data is what WordPress reads next",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-space-db-wordpress-"));
    const db = await startMysqlContainer({
      namePrefix: "sf-space-db-wordpress",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    const rt = await startRuntime({
      env: { PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}` },
    });
    try {
      const wp = await installWordpress(rt, work, db, "space-db-wordpress.test", {
        plugins: false,
      });
      const content = path.join(rt.root, "wp-content");
      mkdirSync(content, { recursive: true });
      writeFileSync(path.join(content, "object-cache.php"), OBJECT_CACHE);

      const call = async <T>(route: string, action: string, body: Json, schema: z.ZodType<T>) => {
        const response = await api(
          rt,
          "POST",
          `/__spacefast/api.php/spaces/${SPACE_ID}/db/${route}`,
          action,
          { space_id: SPACE_ID },
          body,
        );
        const text = await response.text();
        if (response.status !== 200) throw new Error(`${response.status}: ${text}`);
        return schema.parse(JSON.parse(text));
      };
      const read = () =>
        z
          .tuple([z.string(), z.union([z.string(), z.literal(false)])])
          .parse(
            JSON.parse(
              wp("eval", "echo json_encode([get_option('blogname'), get_option('sf_probe')]);"),
            ),
          );

      // The first read fills the cache: every autoloaded option, and the
      // absence of `sf_probe`.
      expect(read()).toEqual(["Zero local proof", false]);

      const blogname = await call(
        "rows/query",
        "space_db_rows_query",
        { table: "wp_options", where: { option_name: "blogname" } },
        spaceDbRowsPageSchema,
      );
      const optionId = z.number().parse(blogname.rows[0]?.option_id);
      await call(
        "rows/update",
        "space_db_rows_update",
        {
          table: "wp_options",
          rows: [{ key: { option_id: optionId }, set: { option_value: "Renamed" } }],
        },
        spaceDbRowsUpdatedSchema,
      );
      const added = await call(
        "rows/insert",
        "space_db_rows_insert",
        {
          table: "wp_options",
          rows: [{ option_name: "sf_probe", option_value: "hello", autoload: "off" }],
        },
        spaceDbRowsCreatedSchema,
      );
      expect(read()).toEqual(["Renamed", "hello"]);

      // That read cached `sf_probe` under its own name.
      await call(
        "rows/delete",
        "space_db_rows_delete",
        { table: "wp_options", keys: added.keys },
        spaceDbRowsDeletedSchema,
      );
      expect(read()).toEqual(["Renamed", false]);

      // A user's capabilities are meta WordPress caches with the user, so a
      // stale copy would keep authorizing the old role.
      const canManage = () =>
        z
          .boolean()
          .parse(JSON.parse(wp("eval", "echo json_encode(user_can(1, 'manage_options'));")));
      expect(canManage()).toBe(true);
      const capabilities = await call(
        "rows/query",
        "space_db_rows_query",
        { table: "wp_usermeta", where: { user_id: 1, meta_key: "wp_capabilities" } },
        spaceDbRowsPageSchema,
      );
      await call(
        "rows/update",
        "space_db_rows_update",
        {
          table: "wp_usermeta",
          rows: [
            {
              key: { umeta_id: z.number().parse(capabilities.rows[0]?.umeta_id) },
              set: { meta_value: 'a:1:{s:10:"subscriber";b:1;}' },
            },
          ],
        },
        spaceDbRowsUpdatedSchema,
      );
      expect(canManage()).toBe(false);

      // A taxonomy's tree is kept per taxonomy, and term IDs that differ from
      // their term_taxonomy IDs must still clear the right one. The terms are
      // the Space's, as WordPress sees only those when it serves the Space.
      await call(
        "rows/insert",
        "space_db_rows_insert",
        {
          table: "wp_terms",
          rows: [
            { term_id: 1000, name: "Parent", slug: "parent" },
            { term_id: 1001, name: "Child", slug: "child" },
          ],
        },
        spaceDbRowsCreatedSchema,
      );
      await call(
        "rows/insert",
        "space_db_rows_insert",
        {
          table: "wp_termmeta",
          rows: [1000, 1001].map((termId) => ({
            term_id: termId,
            meta_key: "_spacefast_space_id",
            meta_value: SPACE_ID,
          })),
        },
        spaceDbRowsCreatedSchema,
      );
      await call(
        "rows/insert",
        "space_db_rows_insert",
        {
          table: "wp_term_taxonomy",
          rows: [
            { term_taxonomy_id: 2000, term_id: 1000, taxonomy: "category", description: "" },
            { term_taxonomy_id: 2001, term_id: 1001, taxonomy: "category", description: "" },
          ],
        },
        spaceDbRowsCreatedSchema,
      );
      const children = () =>
        z
          .array(z.number())
          .parse(JSON.parse(wp("eval", "echo json_encode(get_term_children(1000, 'category'));")));
      expect(children()).toEqual([]);
      await call(
        "rows/update",
        "space_db_rows_update",
        {
          table: "wp_term_taxonomy",
          rows: [{ key: { term_taxonomy_id: 2001 }, set: { parent: 1000 } }],
        },
        spaceDbRowsUpdatedSchema,
      );
      expect(children()).toEqual([1001]);
    } finally {
      rt.stop();
      db.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 120_000,
);
