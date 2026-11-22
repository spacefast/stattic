<?php
declare(strict_types=1);

// Backward-compat pointer: canonical WordPress principal projection lives in
// `engine/wordpress/content-principals.php` because both SpacePress apps and
// legacy managed-content Spaces share it.
require_once __DIR__ . '/../../wordpress/content-principals.php';
