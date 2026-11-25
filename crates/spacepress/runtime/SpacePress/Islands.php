<?php

declare(strict_types=1);

namespace SpacePress;

/**
 * Compiled `"use client"` components on a page (docs/decisions/2026-11-08-tsx-to-php.md,
 * generator B). The server HTML of each one calls context() for its root element, which records
 * its namespace; enqueue() then loads the store module of every namespace the page rendered, and
 * nothing for pages that rendered none.
 *
 * WordPress's own directive pass (`wp_interactivity_process_directives`) never runs on these
 * elements: the compiler already printed every value, with JavaScript's own formatting.
 */
final class Islands
{
    /** @var array<string, true> namespaces rendered by this request */
    private static array $used = [];

    /**
     * The `data-wp-context` value of a component's root: its state and props as JSON, in the shape
     * the store reads with getContext(). Records that the page needs the namespace's store.
     *
     * @param array<string, mixed> $context
     */
    public static function context(string $namespace, array $context): string
    {
        self::$used[$namespace] = true;
        return (string) Js::jsonStringify(new JsObject($context));
    }

    /**
     * The server render of a reactive list or conditional's items: each top-level element marked
     * `data-wp-each-child="<namespace>::<path>"`, so the Interactivity runtime takes the elements
     * over as the template's children instead of rendering a second copy (WordPress 6.9 +).
     */
    public static function eachChild(mixed $rendered, string $source): Html
    {
        $html = Html::child($rendered);
        if ($html === '' || !class_exists(\WP_HTML_Tag_Processor::class)) {
            return Html::raw($html);
        }
        $p = new \WP_HTML_Tag_Processor($html);
        $depth = 0;
        while ($p->next_token()) {
            if ($p->get_token_type() !== '#tag') {
                continue;
            }
            if ($p->is_tag_closer()) {
                $depth--;
                continue;
            }
            if ($depth === 0) {
                $p->set_attribute('data-wp-each-child', $source);
            }
            if (!$p->has_self_closing_flag() && !in_array($p->get_tag(), self::VOID, true)) {
                $depth++;
            }
        }

        return Html::raw($p->get_updated_html());
    }

    /** HTML void elements: they have no closer, so they never open a level. */
    private const VOID = ['AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR'];

    /**
     * The `<template>` a reactive list or conditional renders from in the browser: `$each` is the
     * directive's name (`data-wp-each--item`), `$path` the state it reads, `$key` how each item is
     * keyed. Its content is the item's element with every browser-computed value left empty.
     */
    public static function template(string $each, string $path, mixed $content, ?string $key = null): Html
    {
        $attributes = ' ' . $each . '="' . Html::escape($path) . '"' . ($key === null ? '' : ' data-wp-each-key="' . Html::escape($key) . '"');

        return Html::raw('<template' . $attributes . '>' . Html::child($content) . '</template>');
    }

    /** @return list<string> */
    public static function used(): array
    {
        return array_keys(self::$used);
    }

    /** The script module id of the app's typed client in the browser. */
    public const CLIENT = '@spacepress/client';

    /**
     * Registers the app's typed client (`sp/_generated/client/client.js`, built into the app
     * plugin) as the script module `@spacepress/client`, for modules that import it. Registering doesn't
     * load it: a page loads it only when a module it enqueues depends on it.
     */
    public static function registerClient(): bool
    {
        $plugin = Wp\Runtime::plugin();
        if ($plugin === null || !function_exists('wp_register_script_module') || !is_file($plugin->dir . '/sp/app/client/spacepress-client.js')) {
            return false;
        }
        // The file has a fixed name: its version is a digest of its bytes, written by `spacepress build`.
        $version = is_file($plugin->dir . '/sp/app/client-version.php') ? (string) require $plugin->dir . '/sp/app/client-version.php' : null;
        // The client runs each operation as an Ability at core's run route, with what the page prints
        // for it ({@see clientData()}): it needs no other module or script.
        wp_register_script_module(self::CLIENT, $plugin->url('sp/app/client/spacepress-client.js'), [], $version);
        // WordPress prints a module's data only on a page that imports the module. The Interactivity
        // router swaps a later page in without its head, so a page with a router region prints it
        // itself when nothing on it imports the client ({@see printClientData()}).
        add_filter('script_module_data_' . self::CLIENT, [self::class, 'clientData']);
        static $hooked = false;
        if (!$hooked) {
            $hooked = true;
            add_action('wp_footer', [self::class, 'printClientData'], 1);
        }

        return true;
    }

    /**
     * {@see clientData()} on a page with a router region where no module imports the client: the
     * first page's data is what a client created after a client-side navigation reads (and what
     * `spacepress export --site` points at the hosted site). A page that does import it gets
     * WordPress's own copy.
     */
    public static function printClientData(): void
    {
        if (self::$clientUsed || !Render\Navigation::active()) {
            return;
        }
        $data = self::clientData([]);
        wp_print_inline_script_tag(
            (string) wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES),
            ['type' => 'application/json', 'id' => 'wp-script-module-data-' . self::CLIENT],
        );
    }

    /**
     * What `createClient()` reads on the page (`wp-script-module-data-@spacepress/client`): the REST
     * root as a path on this site, the app API's namespace, and for a signed-in visitor the REST
     * nonce. A signed-out render is cached, prebuilt and exported, so it carries no nonce and no
     * origin (`spacepress export --site` points `root` at the hosted site).
     *
     * @return array{root: string, api: string, nonce?: string, nonceEndpoint?: string, nonceSession?: string}
     */
    public static function clientData(array $data): array
    {
        $root = rest_url();
        $parts = wp_parse_url($root);
        $relative = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $data += ['root' => $relative, 'api' => '/' . Wp\Facade::apiNamespace()];
        if (is_user_logged_in()) {
            $data['nonce'] = wp_create_nonce('wp_rest');
            $session = self::clientNonceSession();
            if ($session !== null) {
                $data['nonceEndpoint'] = admin_url('admin-ajax.php?action=spacepress_rest_nonce');
                $data['nonceSession'] = $session;
            }
        }

        return $data;
    }

    /** The page can renew its nonce for the WordPress login session that rendered it. */
    public static function renewClientNonce(): void
    {
        $session = self::clientNonceSession();
        if ($session === null || !hash_equals($session, (string) ($_SERVER['HTTP_X_SPACEPRESS_REST_SESSION'] ?? ''))
            || wp_validate_auth_cookie('', 'logged_in') !== get_current_user_id()) {
            wp_die('', '', ['response' => 409]);

            return;
        }

        wp_die(wp_create_nonce('wp_rest'), '', ['response' => 200]);
    }

    private static function clientNonceSession(): ?string
    {
        $token = wp_get_session_token();
        $user = get_current_user_id();

        return $token === '' || $user === 0 ? null : wp_hash("spacepress_rest_nonce|$user|$token", 'nonce', 'sha256');
    }

    /** Whether a module this page enqueues imports the client (WordPress then prints its data itself). */
    private static bool $clientUsed = false;

    /** A module this page enqueues imports the client. */
    public static function useClient(): void
    {
        self::$clientUsed = true;
    }

    /**
     * The version of the runtime's fixed-name modules (`live.js`, `router.js`): a digest of the
     * runtime's files, so a new runtime's module is never an old cached copy. A build mounted from
     * the build store has no `spacepress-runtime.php` and no digest constant; its runtime URLs already
     * name the runtime revision, so the release version is enough there.
     */
    public static function runtimeVersion(): string
    {
        return defined('SP_RUNTIME_HASH') ? (string) SP_RUNTIME_HASH : Wp\Runtime::VERSION;
    }

    /** The script module id of the glue `useLive` stores import (`Render/client/live.js`). */
    public const LIVE = '@spacepress/live';

    /** Registers `@spacepress/live` (and the typed client it imports) once; false when the app has no client. */
    private static function registerLive(): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }
        if (!self::registerClient()) {
            return $done = false;
        }
        wp_register_script_module(self::LIVE, plugins_url('sp/Render/client/live.js', Wp\Runtime::file()), [self::CLIENT], self::runtimeVersion());
        // The live reads go as the visitor, with the nonce the page prints for a signed-in one.
        self::useClient();

        return $done = true;
    }

    /**
     * A `useLive(kind, args)` field's first value: the read answered now, as the visitor, by the
     * subscription endpoint's own evaluation (`Live\Module::evaluate`). The browser starts from it
     * and subscribes with its descriptor and token, so its first poll is answered "not modified"
     * unless something changed since the render. `{ data, error, loading }` with the read beside it
     * (`$live`), as context the store reads.
     */
    public static function live(string $kind, mixed $args, bool $enabled): JsObject
    {
        $wire = Seam::toWire($args);
        $descriptor = ['kind' => $kind, 'op' => 'list', 'args' => is_array($wire) ? $wire : (array) $wire];
        if (!$enabled) {
            return new JsObject(['data' => JsUndefined::Value, 'error' => JsUndefined::Value, 'loading' => false, '$live' => null]);
        }
        $plugin = Wp\Runtime::plugin();
        $viewer = Wp\CoreAccess::viewer();
        $result = $plugin === null || $viewer === null
            ? ['error' => ['code' => 'unavailable', 'detail' => 'No app']]
            : Live\Module::evaluate($plugin->app()->db, $viewer, [$descriptor])[0];
        // The browser asks for the same read through the typed client (`first.<kind>.list(query)`),
        // which builds the same descriptor from the same query, so the token answers it.
        $live = new JsObject(['kind' => $kind, 'query' => $args, 'token' => $result['token'] ?? null]);
        if (isset($result['error'])) {
            return new JsObject(['data' => JsUndefined::Value, 'error' => new JsObject(['code' => (string) ($result['error']['code'] ?? 'error'), 'message' => (string) ($result['error']['detail'] ?? '')]), 'loading' => false, '$live' => $live]);
        }

        return new JsObject(['data' => Js::decodeJson((string) ($result['json'] ?? 'null')), 'error' => JsUndefined::Value, 'loading' => false, '$live' => $live]);
    }

    /** Registers and enqueues each rendered component's store, and the typed client a store that calls an action runs. */
    public static function enqueue(): void
    {
        $plugin = Wp\Runtime::plugin();
        if (self::$used === [] || $plugin === null || !function_exists('wp_register_script_module')) {
            return;
        }
        $islands = $plugin->schema->islands();
        foreach (array_keys(self::$used) as $namespace) {
            $island = $islands[$namespace] ?? null;
            if (!is_array($island)) {
                continue;
            }
            $handle = 'spacepress-island-' . sanitize_key(str_replace('/', '-', $namespace));
            $deps = ['@wordpress/interactivity'];
            if (($island['live'] ?? false) === true && self::registerLive()) {
                $deps[] = self::LIVE;
            }
            // A store that calls a server action imports the typed client, which runs it as the
            // action's Ability (`client.actions.<name>()`) with the nonce the page prints for it.
            $viaClient = ($island['client'] ?? false) === true;
            if ($viaClient && self::registerClient()) {
                $deps[] = self::CLIENT;
                self::useClient();
            }
            wp_register_script_module($handle, $plugin->url('sp/app/client/' . $island['file']), $deps, null);
            wp_enqueue_script_module($handle);
            // A <Link> that swaps the router region onto this page loads the store then
            // (core marks the tag `data-wp-router-options`), and merges this page's config.
            wp_interactivity()->add_client_navigation_support_to_script_module($handle);
            // Each action the store calls, by name. A store that runs them through the typed client
            // gets its Ability's run route (`spacepress export` reads it to refuse or keep the call on
            // --site). One built before that (no `client`) posts to the API's action URL itself, with
            // the REST nonce, and reads `{ data }` back.
            $config = [];
            if (($island['actions'] ?? []) !== []) {
                $app = Wp\Abilities\Register::kebab($plugin->schema->name());
                $actions = [];
                foreach ($island['actions'] as $action) {
                    $actions[$action] = $viaClient
                        ? rest_url('wp-abilities/v1/abilities/' . $app . '/' . Wp\Abilities\Register::kebab((string) $action) . '/run')
                        : Wp\Facade::url('/actions/' . rawurlencode((string) $action));
                }
                $config['actions'] = (object) $actions;
                // Only a signed-in visitor needs one: a signed-out page is cached and prebuilt, past a
                // nonce's lifetime, and an expired one would refuse its calls.
                if (!$viaClient && is_user_logged_in()) {
                    $config['nonce'] = wp_create_nonce('wp_rest');
                }
            }
            if (is_array($island['assets'] ?? null) && $island['assets'] !== []) {
                $assets = [];
                foreach ($island['assets'] as $name => $entry) {
                    if (is_string($entry)) {
                        $assets[$name] = Assets::url($entry);
                    } elseif (is_array($entry) && is_string($entry['file'] ?? null)) {
                        $assets[$name] = [
                            'src' => Assets::url($entry['file']),
                            'width' => (int) ($entry['width'] ?? 0),
                            'height' => (int) ($entry['height'] ?? 0),
                            'format' => (string) ($entry['format'] ?? ''),
                        ];
                    }
                }
                $config['assets'] = (object) $assets;
            }
            if ($config !== []) {
                wp_interactivity_config($namespace, $config);
            }
        }
    }
}
