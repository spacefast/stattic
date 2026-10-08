<?php
/**
 * Headless WordPress Knowledge compatibility, independent of its consumers.
 * Registration, capabilities and defaults follow Gutenberg's Knowledge API.
 * @see https://github.com/WordPress/gutenberg/tree/5fdb5b445cfea0dabb7bb0966972315e12851630/lib/experimental/knowledge
 * License: GPL-2.0-or-later.
 */
declare(strict_types=1);

if (function_exists('add_action') && class_exists('WP_REST_Posts_Controller')) {
    // Give Core/plugins the first opportunity to supply the native primitive.
    add_action('init', 'wp_knowledge_compat_register', 99);
}

function wp_knowledge_compat_register(): void
{
    if (!post_type_exists('wp_knowledge')) {
        $controller = 'Gutenberg_Knowledge_REST_Controller';
        if (!class_exists($controller)) {
            require_once __DIR__ . '/knowledge-rest-controller.php';
            $controller = 'WP_Knowledge_Compat_REST_Controller';
        }
        register_post_type('wp_knowledge', [
            'label' => __('Guidelines', 'gutenberg'), 'public' => false,
            'show_ui' => false, 'show_in_rest' => true, 'rest_base' => 'knowledge',
            'rest_controller_class' => $controller,
            'capability_type' => ['knowledge_item', 'knowledge_items'],
            'capabilities' => ['read' => 'read_knowledge_items'], 'map_meta_cap' => true,
            'supports' => ['title', 'editor', 'excerpt', 'author', 'revisions'],
            'hierarchical' => false, 'rewrite' => false, 'query_var' => false,
            'has_archive' => false, 'can_export' => true,
        ]);
        remove_post_type_support('wp_knowledge', 'autosave');
        add_filter('user_has_cap', 'wp_knowledge_compat_capabilities', 1, 4);
        add_action('save_post_wp_knowledge', 'wp_knowledge_compat_default_type');
    }
    // An installation may supply either part of the primitive independently.
    if (!taxonomy_exists('wp_knowledge_type')) {
        register_taxonomy('wp_knowledge_type', 'wp_knowledge', [
            'label' => __('Guideline Types', 'gutenberg'), 'public' => false,
            'publicly_queryable' => false, 'hierarchical' => true,
            'show_ui' => false, 'show_in_nav_menus' => false, 'show_in_rest' => true,
            'rewrite' => false, 'query_var' => false,
            'capabilities' => [
                'manage_terms' => 'manage_options', 'edit_terms' => 'edit_knowledge_items',
                'delete_terms' => 'manage_options', 'assign_terms' => 'edit_knowledge_items',
            ],
        ]);
    }
}

/** Gutenberg's administrator/contributor floor and own-private-row grants. */
function wp_knowledge_compat_capabilities(array $allcaps, array $caps, array $args, mixed $user): array
{
    if (!empty($allcaps['manage_options'])) {
        foreach (['read_knowledge_items', 'edit_knowledge_items', 'edit_others_knowledge_items',
            'edit_published_knowledge_items', 'edit_private_knowledge_items', 'publish_knowledge_items',
            'delete_knowledge_items', 'delete_others_knowledge_items', 'delete_published_knowledge_items',
            'delete_private_knowledge_items', 'read_private_knowledge_items'] as $cap) $allcaps[$cap] = true;
        return $allcaps;
    }
    if (empty($allcaps['edit_posts'])) return $allcaps;
    $allcaps['read_knowledge_items'] = true;
    $allcaps['edit_knowledge_items'] = true;
    if (!isset($args[0], $args[2]) || !in_array($args[0], ['edit_post', 'delete_post', 'read_post'], true)) return $allcaps;
    $post = get_post($args[2]);
    if (!$post instanceof WP_Post || $post->post_type !== 'wp_knowledge' || (int) $post->post_author !== (int) $user->ID) return $allcaps;
    $status = $post->post_status === 'trash' ? get_post_meta($post->ID, '_wp_trash_meta_status', true) : $post->post_status;
    if ($status !== 'private') return $allcaps;
    foreach (['edit_private_knowledge_items', 'delete_knowledge_items', 'delete_private_knowledge_items', 'read_private_knowledge_items'] as $cap) $allcaps[$cap] = true;
    return $allcaps;
}

/** Headless rows without an explicit type use the upstream note default. */
function wp_knowledge_compat_default_type(int $postId): void
{
    if (wp_is_post_revision($postId)) return;
    $post = get_post($postId);
    if (!$post instanceof WP_Post) return;
    $guideline = str_starts_with($post->post_name, 'guideline-');
    if (!$guideline) {
        $terms = get_the_terms($postId, 'wp_knowledge_type');
        if (is_wp_error($terms) || !empty($terms)) return;
    }
    $slug = $guideline ? 'guideline' : 'note';
    $term = term_exists($slug, 'wp_knowledge_type');
    if (!$term) {
        $switched = switch_to_locale(get_locale());
        $types = apply_filters('wp_knowledge_types', [
            'guideline' => ['title' => _x('Guideline', 'knowledge type', 'gutenberg')],
            'memory' => ['title' => _x('Memory', 'knowledge type', 'gutenberg')],
            'note' => ['title' => _x('Note', 'knowledge type', 'gutenberg')],
        ]);
        $term = wp_insert_term($types[$slug]['title'] ?? $slug, 'wp_knowledge_type', ['slug' => $slug]);
        if ($switched) restore_previous_locale();
    }
    if (!is_wp_error($term)) wp_set_object_terms($postId, (int) $term['term_id'], 'wp_knowledge_type');
}
