<?php

declare(strict_types=1);

namespace Zero;

/**
 * Access rules (§1.6). A rule is ['allow' => bool] | ['role' => 'anyone'|'authenticated'|Role] | ['self' => true] | ['fn' => name].
 * evaluate() returns true (allowed / all rows), false (denied) or a filter array (rows matching it).
 */
final class Access
{
    public const DEFAULT_READ = ['role' => 'anyone'];
    public const DEFAULT_WRITE = ['role' => 'editor'];
    /** The built-in media collection's fixed rules (§4.6). */
    public const MEDIA = ['read' => ['role' => 'anyone'], 'create' => ['role' => 'author'], 'update' => ['role' => 'editor'], 'delete' => ['role' => 'editor']];

    /** @return array{read: array, create: array, update: array, delete: array} */
    public static function collectionRules(array $spec): array
    {
        $a = $spec['access'] ?? [];

        return [
            'read' => $a['read'] ?? self::DEFAULT_READ,
            'create' => $a['create'] ?? self::DEFAULT_WRITE,
            'update' => $a['update'] ?? self::DEFAULT_WRITE,
            'delete' => $a['delete'] ?? self::DEFAULT_WRITE,
        ];
    }

    /** @return array{read: array, update: array} */
    public static function globalRules(array $global): array
    {
        $a = $global['access'] ?? [];

        return ['read' => $a['read'] ?? self::DEFAULT_READ, 'update' => $a['update'] ?? self::DEFAULT_WRITE];
    }

    public static function evaluate(array $rule, Viewer $viewer, string $op, ?array $data = null, ?array $dataShape = null, ?string $collection = null): bool|array
    {
        $user = $viewer->user;
        if (array_key_exists('allow', $rule)) {
            return (bool) $rule['allow'];
        }
        if (($rule['self'] ?? false) === true) {
            // "self" (auth collections, §6.2): the viewer's own row; anonymous viewers get nothing.
            return $user === null ? false : ['id' => $user['id']];
        }
        if (isset($rule['role'])) {
            return match ($rule['role']) {
                'anyone' => true,
                'authenticated' => $user !== null,
                default => $user !== null && Schema::roleRank($user['role']) >= Schema::roleRank($rule['role']),
            };
        }
        if (isset($rule['fn'])) {
            $slot = $rule['fn'];
            $fn = is_string($slot) ? '\\ZeroApp\\' . $slot : $slot;
            $ctx = ['user' => $user, 'op' => $op, 'data' => $data];
            $ctx = is_string($slot) ? Seam::ctx($ctx, $dataShape) : $ctx;
            $r = $fn($ctx);
            if ($r instanceof JsObject || is_array($r)) {
                if ($collection === null) {
                    throw new \LogicException('A filter-valued access rule requires its collection');
                }
                $r = Seam::filter($collection, $r);
            }
            if (is_array($r)) {
                return $r;
            }

            return $r === true;
        }

        throw new \LogicException('Invalid access rule');
    }

    /** True when the rule can only return a boolean (draft visibility applies to these, §1.6). */
    public static function isShorthand(array $rule): bool
    {
        return !isset($rule['fn']);
    }

    public static function deny(?array $user, string $what = 'this'): HttpError
    {
        return $user === null
            ? new HttpError(401, 'unauthenticated', "Sign in to do $what")
            : new HttpError(403, 'forbidden', "You don't have access to $what");
    }

    /** An access rule that is a plain boolean check (create, actions, globals, media): throw unless allowed. */
    public static function require(array $rule, Viewer $viewer, string $op, ?array $data, string $what, ?array $dataShape = null): void
    {
        $r = self::evaluate($rule, $viewer, $op, $data, $dataShape);
        if (is_array($r)) {
            throw new \LogicException("The $op rule for $what returned a filter; it must return a boolean");
        }
        if (!$r) {
            throw self::deny($viewer->user, $what);
        }
    }
}
