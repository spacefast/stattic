<?php

declare(strict_types=1);

namespace Zero\Auth;

use Zero\App;
use Zero\Extensions;
use Zero\HttpError;
use Zero\Response;
use Zero\Schema;
use Zero\Viewer;

use function Zero\db;

/**
 * Feature 4 (§6.2): the `/auth/*` routes, `GET /me`, and the auth-collection
 * guards every read and write path goes through:
 *
 * - hidden columns (`passwordHash`, `suspendedAt`, `guest`) are stripped from every row the API
 *   serializes (list, get, `with`, revisions, write results) and refused in filters, ordering
 *   and `columns` selections, also through relation filters;
 * - `password` is a write-only virtual input: set with `wp_set_password`, never echoed;
 * - `role` is writable by admins only (its column `access.update` rule, enforced here too so it
 *   holds without the field-access lane) and system columns can't be written directly.
 *
 * Sessions and tokens live behind the `Issuer` interface, implemented by `Wp\IdentityBridge` (zero-identity).
 */
final class Module
{
    public const HIDDEN_FILTER = 'cannot be filtered or ordered on';
    private static ?Issuer $issuer = null;

    public static function register(): void
    {
        Extensions::route('auth', self::route(...));
        Extensions::beforeRead(self::guardQuery(...), constraintSafe: true);
        Extensions::onRead(self::strip(...), constraintSafe: true);
        Extensions::normalizeWrite(self::password(...));
        Extensions::onWrite(self::guardWrite(...));
        Extensions::schemaDescribe(self::describe(...));
        if (function_exists('add_action')) {
            \Zero\Wp\Guests::register(); // `zero.prune-guests` through Action Scheduler
        }
    }

    /** The plugin installs its issuer when it boots (Wp\Plugin::app). */
    public static function use(?Issuer $issuer): void
    {
        self::$issuer = $issuer;
    }

    public static function issuer(): ?Issuer
    {
        return self::$issuer;
    }

    // ================================================================ routes

    /** @return array{int, mixed}|Response */
    public static function route(string $method, array $segments, array $query, string $body, Viewer $viewer, array $files, array $form): array|Response
    {
        $path = implode('/', $segments);
        $issuer = self::$issuer;
        $guests = (bool) (db()->schema()->auth()['guests'] ?? true);
        switch ($path) {
            case 'auth/config':
                App::allow($method, ['GET']);

                return [200, $issuer?->config($guests) ?? ['issuer' => null, 'guests' => false]];
            case 'auth/guest':
                App::allow($method, ['POST']);
                if ($issuer === null || !$guests) {
                    throw HttpError::notFound('Guests are not enabled');
                }

                return [201, self::user($issuer->guest())];
            case 'auth/session':
                App::allow($method, ['DELETE']);
                $issuer ?? throw HttpError::notFound('Sessions belong to WordPress without zero-identity');
                if ($viewer->user === null) {
                    throw new HttpError(401, 'unauthenticated', 'Sign in first');
                }
                $issuer->signOut($viewer->user);

                return [200, true];
        }
        throw HttpError::notFound('No route for /' . $path);
    }

    /** `GET /me`: the user plus their auth-collection row (`member`) when the app has one. */
    public static function me(?array $user): ?array
    {
        return $user === null ? null : self::present($user);
    }

    private static function present(array $user): array
    {
        $collection = db()->schema()->auth()['collection'] ?? null;
        if ($collection === null) {
            return $user;
        }

        // Read as the user themself, so field access and "self" apply exactly as on GET /members;
        // a read rule that keeps users from their own row yields no member, never a failed sign-in.
        try {
            $member = db()->findFirst($collection, ['where' => ['id' => $user['id']]], new Viewer($user));
        } catch (HttpError $e) {
            if (!in_array($e->status, [401, 403], true)) {
                throw $e;
            }
            $member = null;
        }

        return $user + ['member' => $member];
    }

    /** Token responses carry the user like `/me` does. */
    private static function user(array $tokens): array
    {
        $tokens['user'] = self::present($tokens['user']);

        return $tokens;
    }

    // ================================================================ guards

    /** @return array<string, true> hidden columns of a collection */
    public static function hidden(string $collection): array
    {
        $out = [];
        $schema = db()->schema();
        if (!$schema->hasCollection($collection)) {
            return [];
        }
        foreach ($schema->collection($collection)['columns'] as $name => $col) {
            if ($col['hidden'] ?? false) {
                $out[(string) $name] = true;
            }
        }

        return $out;
    }

    /** Hidden columns never reach a query, at any depth (`where`, relation filters, `orderBy`, `columns`, `with`). */
    public static function guardQuery(string $collection, array &$opts, ?Viewer $viewer, string $pointer): void
    {
        if ($viewer === null) {
            return;
        }
        self::guardFilter($collection, $opts['where'] ?? null, $pointer . '/where');
        $hidden = self::hidden($collection);
        $order = $opts['orderBy'] ?? null;
        foreach (is_array($order) ? array_keys($order) : [] as $col) {
            if (isset($hidden[(string) $col])) {
                throw new HttpError(403, 'forbidden', "$col " . self::HIDDEN_FILTER, $pointer . '/orderBy' . HttpError::seg($col));
            }
        }
        $columns = $opts['columns'] ?? null;
        foreach (is_array($columns) ? array_keys($columns) : [] as $col) {
            if (isset($hidden[(string) $col])) {
                throw new HttpError(403, 'forbidden', "$col can't be selected", $pointer . '/columns' . HttpError::seg($col));
            }
        }
    }

    private static function guardFilter(string $collection, mixed $where, string $ptr): void
    {
        if (!is_array($where)) {
            return;
        }
        if (!db()->schema()->hasCollection($collection)) {
            return;
        }
        $spec = db()->schema()->collection($collection);
        $hidden = self::hidden($collection);
        foreach ($where as $key => $value) {
            $key = (string) $key;
            $kptr = $ptr . HttpError::seg($key);
            if (isset($hidden[$key])) {
                throw new HttpError(403, 'forbidden', "$key " . self::HIDDEN_FILTER, $kptr);
            }
            if (in_array($key, ['AND', 'OR'], true) && is_array($value)) {
                foreach ($value as $i => $f) {
                    self::guardFilter($collection, $f, "$kptr/$i");
                }
            } elseif ($key === 'NOT') {
                self::guardFilter($collection, $value, $kptr);
            } elseif (isset($spec['relations'][$key]) && is_array($value)) {
                self::guardFilter($spec['relations'][$key]['collection'], $value, $kptr);
            }
        }
    }

    /**
     * Hidden columns leave every row the API returns (any viewer); storage keeps them. The auth
     * collection's secrets go on trusted reads too (hooks, CLI): nothing in an app needs them, and
     * the issuer reads them itself. Other features' hidden metadata (a locale's translation group)
     * stays visible to trusted internal reads that maintain it.
     */
    public static function strip(array $rows, string $collection, ?Viewer $viewer): array
    {
        $isAuth = $collection === (db()->schema()->auth()['collection'] ?? null);
        $hidden = $viewer !== null || $isAuth ? self::hidden($collection) : [];
        if ($viewer === null && $isAuth) {
            $hidden = array_intersect_key($hidden, array_flip(['passwordHash', 'suspendedAt', 'guest']));
        }
        if ($hidden === []) {
            return $rows;
        }

        return array_map(static fn (array $row): array => array_diff_key($row, $hidden), $rows);
    }

    /** The virtual `password` input on the auth collection: kept for wp_set_password. */
    public static function password(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        if ($op === 'delete' || $collection !== (db()->schema()->auth()['collection'] ?? null) || !array_key_exists('password', $data)) {
            return;
        }
        $password = $data['password'];
        unset($data['password']);
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 1024 || str_contains($password, "\0")) {
            throw HttpError::invalidInput('password must be a string of at least 12 characters, without NUL', '/password');
        }
        $data['passwordHash'] = \Zero\Wp\PendingPassword::put($password);
    }

    /** API writes never set system columns of the auth collection directly, and only admins change `role`. */
    public static function guardWrite(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        if ($viewer === null || $op === 'delete' || $collection !== (db()->schema()->auth()['collection'] ?? null)) {
            return;
        }
        foreach (array_keys(self::hidden($collection)) as $col) {
            if (array_key_exists($col, $provided)) {
                throw HttpError::invalidInput("Unknown field '$col'", HttpError::seg($col));
            }
        }
        $admin = $viewer->user !== null && Schema::roleRank($viewer->user['role']) >= Schema::roleRank('admin');
        if (array_key_exists('role', $provided)) {
            if (!$admin && !($op === 'update' && $existing !== null && $provided['role'] === $existing['role'])) {
                throw new HttpError(403, 'forbidden', 'Only admins can change role', '/role');
            }
        }
        // Where an identity issuer verifies emails (WordPress + zero-identity), a member's email is
        // what identity verified: only admins (imports, support) set it directly, on create or
        // update. Otherwise someone could plant an account on another person's address (with a
        // password they know) and keep access when that person later verifies it.
        if (!$admin && (self::$issuer?->verifiesEmail() ?? false)) {
            if ($op === 'create') {
                throw new HttpError(403, 'forbidden', 'Members sign up on the account screen, where emails are verified', '/email');
            }
            if (array_key_exists('email', $provided) && $existing !== null && $provided['email'] !== $existing['email']) {
                throw new HttpError(403, 'forbidden', 'Change your email on the account screen, where it is verified', '/email');
            }
        }
    }

    /** `/_schema` never names hidden columns. */
    public static function describe(array $describe): array
    {
        foreach ($describe['collections'] as $name => $c) {
            foreach ($c['columns'] as $col => $def) {
                if ($def['hidden'] ?? false) {
                    unset($describe['collections'][$name]['columns'][$col]);
                }
            }
        }

        return $describe;
    }
}
