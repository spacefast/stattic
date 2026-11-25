<?php
// Sample data for WordPress Playground (www/scripts/build-showcase.ts puts it in the blueprint).
// The posts in content/posts/ are already on the site: the blueprint seeds them first, as
// `wp spacepress seed` does, so hello-spacepress is published and typed-content-plain-files is a draft, as
// their files say. This adds an author, one more published post, three comments and the settings,
// through the app's own API, so access rules, hooks and jobs run as for a real request.
define('DOING_CRON', true);
require_once '/wordpress/wp-load.php';
// Every write must succeed: a seed that half-ran would show a site that looks broken.
$call = static function (string $method, string $route, array $body): void {
	// The app's API (/wp-json/spacepress/v1), in process: each call dispatches to the core route as the
	// current user, exactly as an HTTP request to the facade would.
	[$status] = \SpacePress\Wp\Facade::route(\SpacePress\Wp\Runtime::plugin(), $method, $route, [], (string) wp_json_encode($body));
	if ($status >= 300) {
		throw new RuntimeException("$method $route answered $status");
	}
};
wp_set_current_user(1);
$call('PATCH', '/settings', ['siteTitle' => 'Field Guide', 'tagline' => 'Notes on building with SpacePress.']);
$call('POST', '/authors', ['name' => 'Ada Admin', 'email' => 'ada@example.com', 'bio' => 'Writes the posts here.', 'userId' => 1]);
// The posts hook fills in the author from the signed-in user.
$call('POST', '/posts', ['title' => 'Comments are moderated', 'status' => 'published', 'tags' => ['guide'], 'body' => '<!-- wp:paragraph --><p>A comment from a reader waits for an editor. Staff comments go up at once.</p><!-- /wp:paragraph -->']);
$hello = get_page_by_path('hello-spacepress', OBJECT, 'sp_posts');
if ($hello) {
	// Staff comments are approved by the afterCreate hook.
	$call('POST', '/comments', ['postId' => $hello->ID, 'name' => 'Ada Admin', 'body' => 'Welcome, everyone.']);
	$call('POST', '/comments', ['postId' => $hello->ID, 'name' => 'Ada Admin', 'body' => 'Edit content/posts/hello-spacepress.md to change this post.']);
	// A reader's comment stays unapproved, and the notifyEditors job stamps it.
	wp_set_current_user(0);
	$call('POST', '/comments', ['postId' => $hello->ID, 'name' => 'A reader', 'body' => 'Can I write posts in Markdown too?']);
	wp_set_current_user(1);
}
// Queued jobs run now, as WP-Cron would on a later request. The webhook jobs fail and back off:
// BLOG_HOOK_URL is not set here, so there is nowhere to deliver to.
foreach (as_get_scheduled_actions(['hook' => 'sp_job', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50], 'ids') as $id) {
	ActionScheduler::runner()->process_action($id, 'spacepress-playground');
}
