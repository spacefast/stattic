<?php
declare(strict_types=1);

// The native service broker records completed spam checks through the same
// journal implementation as the origin and mail transport.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../shared/retail-usage.php';
$root = (string) getenv('SPACEFAST_SERVICE_USAGE_ROOT');
$spaceId = (string) getenv('SPACEFAST_SERVICE_SPACE_ID');
_stattic_record_retail_usage($root, $spaceId, 'spam_checks', 1);
