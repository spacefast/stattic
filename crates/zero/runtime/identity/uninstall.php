<?php

declare(strict_types=1);
if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}
wp_clear_scheduled_hook("zero_identity_maintenance");
// Account data and signing material survive uninstall. Explicit erasure uses WordPress privacy tools.
