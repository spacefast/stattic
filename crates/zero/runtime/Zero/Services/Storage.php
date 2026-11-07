<?php

declare(strict_types=1);

namespace Zero\Services;

use Zero\SystemTables;

use function Zero\db;

/** Service records share the app's WordPress connection and its outer transaction. */
final class Storage
{
    private const TABLES = ['submissions', 'orders', 'events', 'rates'];

    public static function register(): void
    {
        $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $definitions = [
            'submissions' => '`id` CHAR(36) NOT NULL, `form` VARCHAR(80) NOT NULL, `body` LONGTEXT NOT NULL, `jobId` CHAR(36) NOT NULL, `createdAt` CHAR(24) NOT NULL, PRIMARY KEY (`id`)',
            'orders' => '`id` CHAR(36) NOT NULL, `tokenHash` CHAR(64) NOT NULL, `product` VARCHAR(80) NOT NULL, `name` VARCHAR(160) NOT NULL, `amount` INT NOT NULL, `currency` CHAR(3) NOT NULL, `returnUrl` TEXT NOT NULL, `state` VARCHAR(20) NOT NULL, `sessionId` VARCHAR(255) NULL, `checkoutUrl` TEXT NULL, `jobId` CHAR(36) NOT NULL, `createdAt` CHAR(24) NOT NULL, `expiresAt` BIGINT NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `token` (`tokenHash`), UNIQUE KEY `session` (`sessionId`)',
            'events' => '`id` VARCHAR(255) NOT NULL, `bodyHash` CHAR(64) NOT NULL, `orderId` CHAR(36) NULL, `type` VARCHAR(100) NOT NULL, `outcome` VARCHAR(20) NOT NULL, `createdAt` CHAR(24) NOT NULL, PRIMARY KEY (`id`)',
            'rates' => '`id` CHAR(64) NOT NULL, `bucket` BIGINT NOT NULL, `used` INT NOT NULL, PRIMARY KEY (`id`), KEY `bucket` (`bucket`)',
        ];
        foreach ($definitions as $name => $columns) {
            SystemTables::register('__zero_services_' . $name, ["CREATE TABLE IF NOT EXISTS `{prefix}zero__services_$name` ($columns) $tail"]);
        }
    }

    public static function run(string $sql, array $params = []): array
    {
        $store = db()->store();
        if (!$store instanceof \Zero\Wp\CompositeStore) {
            throw new \LogicException('App services require the WordPress store');
        }
        foreach (self::TABLES as $name) {
            $sql = str_replace('{' . $name . '}', '`' . $store->db->prefix() . 'zero__services_' . $name . '`', $sql);
        }
        return $store->db->run($sql, $params);
    }

    /** Fixed fifteen-minute windows; forwarded headers are deliberately not an identity source. */
    public static function rate(string $scope, int $limit): void
    {
        $secret = Module::secret('ZERO_SERVICES_RATE_SECRET');
        $id = hash_hmac('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), $secret);
        $bucket = intdiv(time(), 900);
        self::run('INSERT IGNORE INTO {rates} (`id`, `bucket`, `used`) VALUES (?, ?, 0)', [$id, $bucket]);
        $row = self::run('SELECT `bucket`, `used` FROM {rates} WHERE `id` = ? FOR UPDATE', [$id])[0];
        $used = (int) $row['bucket'] === $bucket ? (int) $row['used'] : 0;
        if ($used >= $limit) {
            throw new \Zero\HttpError(429, 'rate_limited', 'Too many requests. Try again later.');
        }
        self::run('UPDATE {rates} SET `bucket` = ?, `used` = ? WHERE `id` = ?', [$bucket, $used + 1, $id]);
        // Each address occupies one row. Old addresses expire without another scheduled queue.
        self::run('DELETE FROM {rates} WHERE `bucket` < ?', [$bucket - 96]);
    }
}
