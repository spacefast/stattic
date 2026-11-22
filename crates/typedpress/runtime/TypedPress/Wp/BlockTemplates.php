<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\Html;

/**
 * `tp/app/` pages inside the active block theme's templates
 * (docs/decisions/2026-11-16-pages-in-block-templates.md, docs/pages.md "Block templates").
 *
 * A page's HTML is the content of a WordPress block template. WordPress renders the template as it
 * renders any front-end template: the theme's header and footer parts, global styles, the
 * owner's Site Editor changes. The page itself is one dynamic block, `tp/page`, which prints
 * what the router rendered for the address.
 *
 * TypedPress registers three templates (`register_block_template()`), each editable in the Site Editor:
 *
 * - `tp-page`: the theme's header part, the page, the theme's footer part. The default.
 * - `tp-blank`: the page alone, for a layout that draws its own chrome (`template = "blank"`,
 *   or a layout that places `<TemplatePart>` itself).
 * - `tp-login`: wp-login.php's sign-in view. `login.tsx` is its default content, or the
 *   `tp/login-form` block when the app has none.
 *
 * The 404 is the theme's own `404` template. When the app has `not-found.tsx`, the theme file's
 * content is replaced by TypedPress's default (header, page, footer), so the editor shows and resets to
 * the page the app renders; an owner's saved 404 template wins over both.
 */
final class BlockTemplates
{
    public const NAMESPACE = 'tp';
    public const PAGE = 'tp-page';
    public const BLANK = 'tp-blank';
    public const LOGIN = 'tp-login';
    public const NOT_FOUND = '404';
    public const PAGE_BLOCK = 'tp/page';
    public const LOGIN_BLOCK = 'tp/login-form';

    /** While a page renders: the marker `tp/page` prints, replaced by the page after the template rendered. */
    private static ?string $token = null;
    private static bool $placed = false;

    private static ?Plugin $plugin = null;

    public static function register(Plugin $plugin): void
    {
        $app = $plugin->schema->app();
        if (!is_array($app) || ($app['legacyRoutes'] ?? false) || self::$plugin !== null) {
            return;
        }
        self::$plugin = $plugin;
        add_action('init', self::blocks(...), 5);
        add_action('init', self::templates(...), 20);
        if (is_array($app['notFound'] ?? null)) {
            add_filter('get_block_file_template', static fn (mixed $template): mixed => self::notFoundFile($template), 10, 1);
            add_filter('get_block_templates', static function (array $templates): array {
                foreach ($templates as $template) {
                    self::notFoundFile($template);
                }
                return $templates;
            }, 10, 1);
        }
    }

    /** The two blocks: the page, and the sign-in form an owner can place without code. */
    public static function blocks(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        if (!$registry->is_registered(self::PAGE_BLOCK)) {
            register_block_type(self::PAGE_BLOCK, [
                'title' => __('Page content'),
                'description' => __('What the app renders for the address: its page, inside its layouts.'),
                'category' => 'theme',
                'supports' => ['html' => false, 'multiple' => false, 'reusable' => false, 'autoRegister' => true],
                'render_callback' => static fn (): string => self::page(),
            ]);
        }
        if (!$registry->is_registered(self::LOGIN_BLOCK)) {
            register_block_type(self::LOGIN_BLOCK, [
                'title' => __('Login form'),
                'description' => __('The sign-in form: it posts to wp-login.php, which runs the sign-in.'),
                'category' => 'theme',
                'attributes' => [
                    'submitLabel' => ['type' => 'string', 'default' => ''],
                    'notices' => ['type' => 'boolean', 'default' => true],
                    'links' => ['type' => 'boolean', 'default' => true],
                ],
                'supports' => ['html' => false, 'autoRegister' => true],
                'render_callback' => static fn (array $attrs): string => \TypedPress\Render\LoginView::form(
                    $attrs['className'] ?? null,
                    ($attrs['submitLabel'] ?? '') !== '' ? $attrs['submitLabel'] : null,
                    $attrs['notices'] ?? true,
                    $attrs['links'] ?? true,
                )->html(),
            ]);
        }
    }

    /** TypedPress's templates, for the active theme. */
    public static function templates(): void
    {
        if (!function_exists('register_block_template') || self::$plugin === null) {
            return;
        }
        $app = self::$plugin->schema->app();
        $templates = [
            self::PAGE => [__('App page'), __('A page of the app, between the theme\'s header and footer.'), self::markup(true, self::block(self::PAGE_BLOCK))],
            self::BLANK => [__('App page without header and footer'), __('A page of the app whose layout draws its own header and footer.'), self::markup(false, self::block(self::PAGE_BLOCK))],
            self::LOGIN => [__('Sign in'), __('wp-login.php\'s sign-in view.'), self::markup(
                self::chrome($app['login'] ?? null),
                self::block(is_array($app['login'] ?? null) ? self::PAGE_BLOCK : self::LOGIN_BLOCK),
            )],
        ];
        // A theme without a 404 template gets TypedPress's; one with a file has it replaced (notFoundFile).
        if (is_array($app['notFound'] ?? null)) {
            $templates[self::NOT_FOUND] = [__('Page: 404'), __('The page for an address nothing is published at.'), self::markup(self::chrome($app['notFound']), self::block(self::PAGE_BLOCK))];
        }
        foreach ($templates as $slug => [$title, $description, $content]) {
            $name = self::NAMESPACE . '//' . $slug;
            if (!\WP_Block_Templates_Registry::get_instance()->is_registered($name)) {
                register_block_template($name, ['title' => $title, 'description' => $description, 'content' => $content]);
            }
        }
    }

    /**
     * The theme's own 404 template file, with TypedPress's default content when the app has
     * `not-found.tsx`: the Site Editor shows and resets to that. A 404 template the owner saved is
     * a post (`source` "custom"), never touched.
     */
    private static function notFoundFile(mixed $template): mixed
    {
        if (!$template instanceof \WP_Block_Template || $template->slug !== self::NOT_FOUND || $template->source !== 'theme' || $template->type !== 'wp_template') {
            return $template;
        }
        $app = self::$plugin?->schema->app();
        $template->content = self::markup(self::chrome($app['notFound'] ?? null), self::block(self::PAGE_BLOCK));
        return $template;
    }

    private static function block(string $name): string
    {
        return "<!-- wp:$name /-->";
    }

    /** Template markup: the content, between the theme's header and footer parts when `$parts`. */
    public static function markup(bool $parts, string $inner): string
    {
        if (!$parts) {
            return $inner . "\n";
        }
        return "<!-- wp:template-part {\"slug\":\"header\",\"tagName\":\"header\"} /-->\n"
            . $inner . "\n"
            . "<!-- wp:template-part {\"slug\":\"footer\",\"tagName\":\"footer\"} /-->\n";
    }

    /**
     * Whether a route's default template has the theme's header and footer: its layouts' choice,
     * innermost first. A layout that picks `"blank"` draws its own.
     *
     * @param ?array<string, mixed> $route
     */
    private static function chrome(?array $route): bool
    {
        return ($route['template'] ?? 'theme') !== 'blank';
    }

    /** A choice as a template slug: `"theme"` and `"blank"` are TypedPress's own. */
    public static function slug(string $choice): string
    {
        return match ($choice) {
            'theme' => self::PAGE,
            'blank' => self::BLANK,
            default => $choice,
        };
    }

    /**
     * The template to render: the active theme's (or the owner's saved) template for `$slug`. A
     * template that has no place for the page falls back to `tp-page`, except the 404 and the
     * sign-in templates, which the owner may make whatever they like as long as sign-in still works.
     */
    public static function resolve(string $slug): ?\WP_Block_Template
    {
        $template = self::published($slug);
        // A custom 404 needs no slot. Login can use the page slot only if the app supplies login.tsx.
        $login = $slug === self::LOGIN && is_array(self::$plugin?->schema->app()['login'] ?? null);
        if ($template instanceof \WP_Block_Template) {
            $slots = $slug === self::LOGIN
                ? ($login ? [self::PAGE_BLOCK, self::LOGIN_BLOCK] : [self::LOGIN_BLOCK])
                : [self::PAGE_BLOCK];
            if ($slug === self::NOT_FOUND || self::containsSlot((string) $template->content, $slots)) {
                return $template;
            }
        }
        if ($slug === self::LOGIN && $template instanceof \WP_Block_Template) {
            error_log('TypedPress: the "' . self::LOGIN . '" block template has no sign-in form' . ($login ? '' : ' (the app has no login.tsx, so it needs the Login form block)') . '; its default content renders instead');
        } elseif ($slug !== self::PAGE && $slug !== self::NOT_FOUND && $slug !== self::LOGIN) {
            error_log("TypedPress: the block template \"$slug\" has no Page content block; the page renders in \"" . self::PAGE . '" instead');
        }
        if ($slug === self::LOGIN) {
            $app = self::$plugin?->schema->app();
            $fallback = new \WP_Block_Template();
            $fallback->id = get_stylesheet() . '//' . self::LOGIN;
            $fallback->slug = self::LOGIN;
            $fallback->content = self::markup(self::chrome($app['login'] ?? null), self::block(is_array($app['login'] ?? null) ? self::PAGE_BLOCK : self::LOGIN_BLOCK));
            return $fallback;
        }
        return $slug === self::PAGE ? null : self::resolve(self::PAGE);
    }

    /** Whether the owner saved (and published) a sign-in template in the Site Editor. */
    public static function loginCustomized(): bool
    {
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return false;
        }
        $template = self::published(self::LOGIN);
        return $template instanceof \WP_Block_Template && $template->source === 'custom';
    }

    /**
     * The active theme's template for `$slug`, or null. `get_block_template()` also returns a saved
     * template that is trashed or a draft; only a published one renders, so the theme's file or
     * the registered template answers in its place.
     */
    private static function published(string $slug): ?\WP_Block_Template
    {
        $template = get_block_template(get_stylesheet() . '//' . $slug);
        if ($template instanceof \WP_Block_Template && $template->source === 'custom' && $template->status !== 'publish') {
            $template = get_block_file_template(get_stylesheet() . '//' . $slug);
        }
        return $template instanceof \WP_Block_Template ? $template : null;
    }

    /** Look inside template parts only when the template itself has no slot. @param list<string> $slots */
    private static function containsSlot(string $content, array $slots, int $depth = 0): bool
    {
        foreach ($slots as $slot) {
            if (has_block($slot, $content)) {
                return true;
            }
        }
        if ($depth >= 4 || !str_contains($content, 'wp:template-part')) {
            return false;
        }
        foreach (parse_blocks($content) as $block) {
            foreach (self::parts($block) as $slug) {
                $part = get_block_template(get_stylesheet() . '//' . $slug, 'wp_template_part');
                if ($part instanceof \WP_Block_Template && self::containsSlot((string) $part->content, $slots, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> the slugs of the template parts a parsed block places, nested ones too */
    private static function parts(array $block): array
    {
        $out = ($block['blockName'] ?? null) === 'core/template-part' && is_string($block['attrs']['slug'] ?? null) ? [$block['attrs']['slug']] : [];
        foreach ($block['innerBlocks'] ?? [] as $inner) {
            $out = [...$out, ...self::parts($inner)];
        }
        return $out;
    }

    /**
     * The template's HTML, as `template-canvas.php` gets it, with the page in place of the
     * `tp/page` block. The page is put in after WordPress's template filters ran (shortcodes,
     * texturize, smilies, content tags), so they never rewrite what the app rendered.
     */
    public static function html(\WP_Block_Template $template, string $page): ?string
    {
        global $_wp_current_template_id, $_wp_current_template_content;
        $_wp_current_template_id = $template->id;
        $_wp_current_template_content = $template->content;
        self::$token = '<!-- typedpress-page-' . bin2hex(random_bytes(8)) . ' -->';
        self::$placed = false;
        try {
            $html = get_the_block_template_html();
        } finally {
            $token = self::$token;
            self::$token = null;
        }
        $at = strpos($html, $token);
        // The 404 and sign-in templates are the owner's to fill: one without the page (the Login
        // form block alone, a 404 of their own words) renders as they made it.
        if ($at === false && ($template->slug === self::LOGIN || $template->slug === self::NOT_FOUND)) {
            return $html;
        }
        if ($at === false) {
            // The page block didn't render where the template placed it (a filter removed it, a
            // pattern hid it): the caller renders elsewhere rather than drop the page.
            error_log('TypedPress: the block template "' . $template->slug . '" rendered no Page content block; the page renders without it');
            return null;
        }
        return substr_replace($html, $page, $at, strlen($token));
    }

    /** `tp/page`: the page while one renders; in the editor, a placeholder. */
    private static function page(): string
    {
        if (self::$token !== null) {
            if (self::$placed) {
                return '';
            }
            self::$placed = true;
            return self::$token;
        }
        return '<div class="typedpress-page-placeholder"><p>' . esc_html__('The page the app renders for each address appears here.') . '</p></div>';
    }

    /** `<TemplatePart slug tagName? className? />`: one of the theme's template parts, as `core/template-part` renders it. */
    public static function part(mixed $slug, mixed $tag, mixed $class): Html
    {
        $attrs = ['slug' => (string) $slug];
        if (is_string($tag) && $tag !== '') {
            $attrs['tagName'] = $tag;
        }
        if (is_string($class) && $class !== '') {
            $attrs['className'] = $class;
        }
        if (!function_exists('render_block')) {
            return Html::raw('');
        }
        // As a parsed block, never as markup: an attribute value can't close the block comment.
        return Html::raw(render_block(['blockName' => 'core/template-part', 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]));
    }
}
