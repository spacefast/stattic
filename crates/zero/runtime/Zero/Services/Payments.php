<?php

declare(strict_types=1);

namespace Zero\Services;

use Zero\Http;
use Zero\HttpError;
use Zero\Jobs\Jobs;
use Zero\Js;

use function Zero\db;

/** Stripe Checkout HTTP subset: single server-priced payment, signed session lifecycle events. */
final class Payments
{
    public static function checkout(array $input, array $headers): array
    {
        if (array_keys($input) !== ['product'] || !is_string($input['product'])) {
            throw HttpError::invalidInput('Send only a product identifier; prices belong to the server', '/product');
        }
        $product = Module::config()['products'][$input['product']] ?? throw HttpError::notFound('Product not found');
        if (!is_int($product['amount'] ?? null) || $product['amount'] < 50 || $product['amount'] > 99999999
            || !is_string($product['currency'] ?? null) || !preg_match('/^[a-z]{3}$/D', $product['currency'])
            || !is_string($product['name'] ?? null) || strlen($product['name']) > 160 || $product['name'] === '') {
            throw new \LogicException('Configure a name, integer minor-unit amount and lowercase currency');
        }
        // Fail before persisting orders when the provider cannot be used. Never return these values.
        Module::secret('ZERO_SERVICES_STRIPE_SECRET');
        Module::secret('ZERO_SERVICES_STRIPE_WEBHOOK_SECRET');
        $return = Module::config()['checkoutReturnUrl'] ?? home_url('/');
        if (!is_string($return) || !filter_var($return, FILTER_VALIDATE_URL)
            || !in_array(wp_parse_url($return, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new \LogicException('Configure a server-owned checkoutReturnUrl');
        }
        return Module::once('services:checkout', $input, $headers, static function () use ($input, $product, $return): array {
            Storage::rate('checkout', 5);
            $id = Jobs::uuid();
            $token = bin2hex(random_bytes(32));
            $job = Jobs::enqueue('zero.services.checkout', ['orderId' => $id], ['key' => $id]);
            Storage::run('INSERT INTO {orders} (`id`, `tokenHash`, `product`, `name`, `amount`, `currency`, `returnUrl`, `state`, `jobId`, `createdAt`, `expiresAt`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, hash('sha256', $token), $input['product'], $product['name'], $product['amount'], $product['currency'], $return, 'requested', $job['id'], Js::iso(Js::now()), time() + 3600]);
            return ['token' => $token, 'state' => 'requested', 'statusPath' => '/services/checkout/' . $token];
        });
    }

    public static function status(string $token): array
    {
        $row = Storage::run('SELECT * FROM {orders} WHERE `tokenHash` = ?', [hash('sha256', $token)])[0]
            ?? throw HttpError::notFound('Checkout not found');
        // The unguessable token is a bearer capability; return no identity, credentials or webhook data.
        return ['state' => $row['state'], 'product' => $row['product'], 'amount' => (int) $row['amount'],
            'currency' => $row['currency'], 'checkoutUrl' => $row['checkoutUrl'], 'delivery' => Module::jobStatus($row['jobId'])];
    }

    public static function createSession(array $input, array $ctx): void
    {
        $row = Storage::run('SELECT * FROM {orders} WHERE `id` = ?', [$input['orderId']])[0]
            ?? throw new \RuntimeException('Order no longer exists');
        if ($row['sessionId'] !== null || $row['state'] !== 'requested') {
            return;
        }
        // Stripe retains keys for at least 24h. Never create a new session after that safety window,
        // and never change the request bytes on retry (including its absolute expiry).
        if ((int) $row['expiresAt'] - time() < 1800) {
            db()->transaction(static function () use ($row): void {
                $current = Storage::run('SELECT * FROM {orders} WHERE `id` = ? FOR UPDATE', [$row['id']])[0];
                if ($current['state'] === 'requested' && $current['sessionId'] === null) {
                    Storage::run('UPDATE {orders} SET `state` = ? WHERE `id` = ?', ['expired', $row['id']]);
                }
            });
            return;
        }
        $request = self::sessionRequest($row);
        $response = Http::post($request['url'], $request['body'], $request['headers']);
        self::saveSession($row, $response);
    }

    /** Executable HTTP serialization boundary; never serialize its Authorization header to a client. */
    public static function sessionRequest(array $row): array
    {
        $return = $row['returnUrl'];
        $params = ['mode' => 'payment', 'client_reference_id' => $row['id'], 'metadata' => ['zero_order' => $row['id']],
            'success_url' => add_query_arg('checkout', 'returned', $return), 'cancel_url' => add_query_arg('checkout', 'cancelled', $return),
            'expires_at' => (int) $row['expiresAt'],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => $row['currency'], 'unit_amount' => (int) $row['amount'], 'product_data' => ['name' => $row['name']]]]]];
        return ['url' => 'https://api.stripe.com/v1/checkout/sessions', 'body' => http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'headers' => [
            'Authorization' => 'Bearer ' . Module::secret('ZERO_SERVICES_STRIPE_SECRET'),
            'Content-Type' => 'application/x-www-form-urlencoded', 'Stripe-Version' => '2024-06-20',
            'Idempotency-Key' => 'zero-checkout-' . $row['id'],
        ]];
    }

    private static function saveSession(array $row, array $response): void
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            // Provider response bodies may contain private configuration. The job log gets a status only.
            throw new \RuntimeException('Stripe checkout request failed with HTTP ' . $response['status']);
        }
        try {
            $session = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Stripe returned invalid JSON');
        }
        if (!is_array($session) || !is_string($session['id'] ?? null) || !str_starts_with($session['id'], 'cs_')
            || !is_string($session['url'] ?? null) || wp_parse_url($session['url'], PHP_URL_SCHEME) !== 'https'
            || wp_parse_url($session['url'], PHP_URL_HOST) !== 'checkout.stripe.com') {
            throw new \RuntimeException('Stripe returned an invalid checkout session');
        }
        db()->transaction(static function () use ($row, $session): void {
            $current = Storage::run('SELECT * FROM {orders} WHERE `id` = ? FOR UPDATE', [$row['id']])[0];
            if ($current['sessionId'] !== null && $current['sessionId'] !== $session['id']) {
                throw new \RuntimeException('Checkout session identity changed');
            }
            Storage::run('UPDATE {orders} SET `sessionId` = ?, `checkoutUrl` = ?, `state` = ? WHERE `id` = ?',
                [$session['id'], $session['url'], $current['state'] === 'requested' ? 'ready' : $current['state'], $row['id']]);
        });
    }

    public static function webhook(string $raw, string $signature): array
    {
        self::verify($raw, $signature, Module::secret('ZERO_SERVICES_STRIPE_WEBHOOK_SECRET'));
        $event = Module::body($raw);
        if (!is_string($event['id'] ?? null) || !preg_match('/^evt_[a-zA-Z0-9_]{1,240}$/D', $event['id'])
            || !is_string($event['type'] ?? null) || strlen($event['type']) > 100) {
            throw HttpError::invalidInput('Invalid Stripe event envelope', '/id');
        }
        return db()->transaction(static function () use ($event, $raw): array {
            // This is a durable payment-event ledger, not the expiring HTTP replay receipt store.
            $hash = hash('sha256', $raw);
            Storage::run('INSERT IGNORE INTO {events} (`id`, `bodyHash`, `type`, `outcome`, `createdAt`) VALUES (?, ?, ?, ?, ?)',
                [$event['id'], $hash, $event['type'], 'received', Js::iso(Js::now())]);
            $stored = Storage::run('SELECT * FROM {events} WHERE `id` = ? FOR UPDATE', [$event['id']])[0];
            if (!hash_equals($stored['bodyHash'], $hash)) {
                throw new HttpError(409, 'event_mismatch', 'This event identifier was already received with different content');
            }
            if ($stored['outcome'] !== 'received') {
                return ['received' => true, 'duplicate' => true];
            }
            $states = ['checkout.session.completed' => 'processing', 'checkout.session.async_payment_succeeded' => 'paid',
                'checkout.session.async_payment_failed' => 'failed', 'checkout.session.expired' => 'expired'];
            $object = $event['data']['object'] ?? null;
            $next = $states[$event['type']] ?? null;
            if ($next === null || !is_array($object) || !is_string($object['metadata']['zero_order'] ?? null)) {
                self::recordEvent($event['id'], null, 'ignored');
                return ['received' => true, 'duplicate' => false];
            }
            $orderId = $object['metadata']['zero_order'];
            $row = Storage::run('SELECT * FROM {orders} WHERE `id` = ? FOR UPDATE', [$orderId])[0] ?? null;
            if ($row === null) {
                self::recordEvent($event['id'], null, 'ignored');
                return ['received' => true, 'duplicate' => false];
            }
            if (($object['object'] ?? null) !== 'checkout.session' || ($object['mode'] ?? null) !== 'payment'
                || !is_string($object['id'] ?? null) || !str_starts_with($object['id'], 'cs_')
                || ($object['client_reference_id'] ?? null) !== $orderId
                || ($row['sessionId'] !== null && $row['sessionId'] !== $object['id'])
                || ($object['currency'] ?? null) !== $row['currency'] || ($object['amount_total'] ?? null) !== (int) $row['amount']) {
                throw new HttpError(422, 'payment_mismatch', 'The payment event does not match its server-owned order');
            }
            if (($object['payment_status'] ?? null) === 'paid' && in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                $next = 'paid';
            } elseif ($next === 'paid') {
                throw new HttpError(422, 'payment_mismatch', 'Payment success requires a paid session');
            }
            // Success is absorbing. Late unpaid completion cannot undo failure/expiry, and a late
            // authenticated payment success may still settle either terminal state.
            $rank = ['requested' => 0, 'ready' => 1, 'processing' => 2, 'failed' => 3, 'expired' => 4, 'paid' => 5];
            $apply = $rank[$next] > $rank[$row['state']];
            if ($apply) {
                Storage::run('UPDATE {orders} SET `state` = ?, `sessionId` = ? WHERE `id` = ?', [$next, $object['id'], $orderId]);
            }
            self::recordEvent($event['id'], $orderId, $apply ? 'applied' : 'ignored');
            return ['received' => true, 'duplicate' => false];
        });
    }

    private static function recordEvent(string $id, ?string $order, string $outcome): void
    {
        Storage::run('UPDATE {events} SET `orderId` = ?, `outcome` = ? WHERE `id` = ?', [$order, $outcome, $id]);
    }

    /** Stripe's timestamped v1 scheme, including multiple signatures during key rotation. */
    private static function verify(string $raw, string $header, string $secret): void
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value) && strlen($value) <= 12) {
                if ($timestamp !== null) {
                    throw new HttpError(401, 'invalid_signature', 'Invalid Stripe signature');
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $value)) {
                $signatures[] = $value;
            }
        }
        if ($timestamp !== null && abs(time() - $timestamp) <= 300) {
            $expected = \Zero\Jobs\Webhooks::sign($secret, $timestamp . '.' . $raw);
            foreach ($signatures as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return;
                }
            }
        }
        throw new HttpError(401, 'invalid_signature', 'The Stripe signature is missing, stale or invalid');
    }
}
