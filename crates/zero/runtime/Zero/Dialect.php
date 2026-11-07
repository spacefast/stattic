<?php

declare(strict_types=1);

namespace Zero;

/** SQL dialect value object (§3.2). See the CHANGED note in Store.php for `ciCollation` and `likeFn`. */
final class Dialect
{
    public function __construct(
        public readonly string $name,            // 'mysql'
        public readonly string $quoteOpen,       // '`'
        public readonly string $quoteClose,
        /** sprintf template: (expr, ci arg): a collation name, or BINARY/empty when translated. */
        public readonly string $likeFn,
        public readonly bool $windowFunctions,   // true (MySQL 8, MariaDB >= 10.2, the SQLite translator)
        /** MySQL only: the case-insensitive collation for ilike ('utf8mb4_0900_ai_ci' MySQL 8, 'utf8mb4_unicode_ci' MariaDB). */
        public readonly string $ciCollation = 'utf8mb4_0900_ai_ci',
        /** `LIMIT` with no bound: MySQL's maximum row count, or the translator's largest signed integer. */
        public readonly string $noLimit = '18446744073709551615',
        /** MySQL as the SQLite Database Integration plugin translates it (see mysqlOnSqlite()). */
        public readonly bool $translated = false,
    ) {
    }

    public static function mysql(string $ciCollation = 'utf8mb4_0900_ai_ci'): self
    {
        return new self('mysql', '`', '`', "%s LIKE ? COLLATE %s ESCAPE '\\\\'", true, $ciCollation);
    }

    /**
     * MySQL as the SQLite Database Integration plugin translates it (WordPress Playground, the fast
     * suites, sites without a MySQL server): the same statements, except where the translator
     * differs. `LIKE ... COLLATE ... ESCAPE` isn't accepted, so `like` is `LIKE BINARY` and `ilike` a
     * plain (case-insensitive) `LIKE`; the unbounded LIMIT is SQLite's largest signed integer; and
     * `DATE_FORMAT` seconds come out wrong, so dates are reshaped with string functions.
     */
    public static function mysqlOnSqlite(): self
    {
        return new self('mysql', '`', '`', '%s LIKE %s?', true, '', '9223372036854775807', true);
    }

    /** The `like`/`ilike` test of $expr against the next `?` parameter. */
    public function like(string $expr, bool $ci): string
    {
        if ($this->translated && $ci) {
            // SQLite's LIKE folds ASCII only; ZERO_ILIKE (registered by Wp\Connection) folds Unicode as
            // the MySQL collations do.
            return "ZERO_ILIKE($expr, ?)";
        }
        $arg = match (true) {
            $this->translated => 'BINARY ',
            default => $ci ? $this->ciCollation : 'utf8mb4_bin',
        };

        return sprintf($this->likeFn, $expr, $arg);
    }

    /** $expr, a MySQL `DATETIME` in UTC, as Zero's ISO instant (`2026-01-02T03:04:05.000Z`). */
    public function isoTimestamp(string $expr): string
    {
        return $this->translated
            ? "CONCAT(REPLACE($expr, ' ', 'T'), '.000Z')"
            : "DATE_FORMAT($expr, '%Y-%m-%dT%H:%i:%s.000Z')";
    }

    /** $expr, a date stored as `Ymd` text (SCF's form), as `Y-m-d`. */
    public function isoDate(string $expr): string
    {
        return $this->translated
            ? "CONCAT(SUBSTR($expr, 1, 4), '-', SUBSTR($expr, 5, 2), '-', SUBSTR($expr, 7, 2))"
            : "DATE_FORMAT(STR_TO_DATE($expr, '%Y%m%d'), '%Y-%m-%d')";
    }

    public function quote(string $ident): string
    {
        return $this->quoteOpen . str_replace($this->quoteClose, $this->quoteClose . $this->quoteClose, $ident) . $this->quoteClose;
    }
}
