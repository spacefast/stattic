<?php
declare(strict_types=1);

// GENERATED FILE — DO NOT EDIT.
// Source: ACCESS_ROLES, ACCESS_ROLE_CAPABILITIES and ACCESS_ROLE_NAMES in
// packages/common/src/contracts/access-core.ts
// Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy

// Weakest first: a role's rank is its index.
const SPACEFAST_SPACE_ROLES = ['viewer', 'commenter', 'editor', 'manager'];
const SPACEFAST_SPACE_ROLE_CAPABILITIES = [
    'viewer' => ['page.view'],
    'commenter' => ['page.view', 'comments.read', 'comments.write'],
    'editor' => ['page.view', 'comments.read', 'comments.write', 'content.publish'],
    'manager' => ['page.view', 'comments.read', 'comments.write', 'content.publish', 'access.manage'],
];
// The WordPress role each Space role runs as, before the installation-wide
// capabilities are taken away.
const SPACEFAST_SPACE_ROLE_WORDPRESS_ROLES = [
    'viewer' => 'subscriber',
    'commenter' => 'subscriber',
    'editor' => 'editor',
    'manager' => 'administrator',
];
