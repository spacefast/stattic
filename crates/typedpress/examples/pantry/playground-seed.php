<?php
// Sample data for WordPress Playground (docs-site/scripts/build-showcase.ts puts it in the blueprint).
// Three places and a few items, written through the app's own API as the admin. Milk drops to
// its low-stock mark when consumed, so the hook puts it on the shopping list and queues the
// notification job. Milk and Butter expire within three days, so expiringSoon lists both.
require_once '/wordpress/wp-load.php';
wp_set_current_user(1);
// Every write must succeed: a seed that half-ran would show a site that looks broken.
$call = static function (string $method, string $route, array $body): void {
	// The app's API (/__tp/api/v1), in process: each call dispatches to the core route as the
	// current user, exactly as an HTTP request to the facade would.
	[$status] = \TypedPress\Wp\Facade::route(\TypedPress\Wp\Runtime::plugin(), $method, $route, [], (string) wp_json_encode($body));
	if ($status >= 300) {
		throw new RuntimeException("$method $route answered $status");
	}
};
$call('POST', '/locations', ['name' => 'Fridge', 'kind' => 'fridge']);
$call('POST', '/locations', ['name' => 'Shelf', 'kind' => 'pantry']);
$call('POST', '/locations', ['name' => 'Freezer', 'kind' => 'freezer']);
$inDays = static fn (int $days): string => gmdate('Y-m-d', time() + $days * DAY_IN_SECONDS);
foreach ([
	['name' => 'Milk', 'quantity' => 2, 'unit' => 'l', 'locationId' => 1, 'expiresOn' => $inDays(2)],
	['name' => 'Rice', 'quantity' => 2, 'unit' => 'kg', 'locationId' => 2],
	['name' => 'Eggs', 'quantity' => 12, 'unit' => 'pcs', 'locationId' => 1],
	['name' => 'Butter', 'quantity' => 2, 'unit' => 'pcs', 'locationId' => 1, 'expiresOn' => $inDays(1)],
] as $item) {
	$call('POST', '/items', $item + ['lowStockAt' => 1]);
}
$call('POST', '/actions/consume', ['itemId' => 1, 'amount' => 1]);
foreach (as_get_scheduled_actions(['hook' => 'tp_job', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20], 'ids') as $id) {
	ActionScheduler::runner()->process_action($id, 'typedpress-playground');
}
