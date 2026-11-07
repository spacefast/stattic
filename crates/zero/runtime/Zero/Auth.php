<?php

declare(strict_types=1);

namespace Zero;

/*
 * CHANGED vs design §5 (additive): besides resolve(), user($id) names revision authors in
 * GET /{c}/{id}/revisions. Sign-in itself belongs to Auth\Issuer (§6.2).
 */

/** Resolves a request to a User (§5): Wp\WpAuth, with zero-identity's bearer tokens when installed. */
interface Auth
{
    /**
     * The user making this request, or null when anonymous. A suspended user's credentials throw
     * HttpError 403 `suspended` (§6.2) instead of resolving.
     *
     * @param array<string, string> $headers lower-cased header names
     * @return ?array{id: int, name: string, role: string}
     */
    public function resolve(array $headers): ?array;

    /**
     * Look a user up by id (revision authors). Null when unknown.
     *
     * @return ?array{id: int, name: string, role: string}
     */
    public function user(int $id): ?array;
}
