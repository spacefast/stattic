<?php
declare(strict_types=1);

/** The accepted SQLite grammar is parsed before any SQL reaches MySQL. */
final class SpacefastD1Sql
{
    private array $tokens;
    private int $position = 0;

    public function __construct(string $sql)
    {
        if (strlen($sql) > 65536) {
            throw new RuntimeException('D1_SQL_UNSUPPORTED: statement exceeds 64 KiB.');
        }
        $this->tokens = self::tokenize($sql);
    }

    public static function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) { $i++; continue; }
            if (substr($sql, $i, 2) === '--') {
                $i = strpos($sql, "\n", $i) ?: $length;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) throw new RuntimeException('D1_SQL_UNSUPPORTED: unterminated comment.');
                $i = $end + 2;
                continue;
            }
            if (in_array($char, ["'", '"', '`', '['], true)) {
                $quote = $char === '[' ? ']' : $char;
                $kind = $char === "'" ? 'string' : 'identifier';
                $value = '';
                $closed = false;
                for ($i++; $i < $length; $i++) {
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $value .= $quote; $i++; continue;
                        }
                        $i++; $closed = true; break;
                    }
                    $value .= $sql[$i];
                }
                if (!$closed) throw new RuntimeException('D1_SQL_UNSUPPORTED: unterminated quoted token.');
                $tokens[] = [$kind, $value];
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z_0-9]*/A', $sql, $match, 0, $i)) {
                $tokens[] = ['word', $match[0]]; $i += strlen($match[0]); continue;
            }
            if (preg_match('/\G\d+(?:\.\d+)?/A', $sql, $match, 0, $i)) {
                $tokens[] = ['number', $match[0]]; $i += strlen($match[0]); continue;
            }
            $pair = substr($sql, $i, 2);
            if (in_array($pair, ['>=', '<=', '<>', '!='], true)) {
                $tokens[] = ['symbol', $pair]; $i += 2; continue;
            }
            if (str_contains('(),?*=<>+-/%.;', $char)) {
                $tokens[] = ['symbol', $char]; $i++; continue;
            }
            throw new RuntimeException('D1_SQL_UNSUPPORTED: unsupported SQL token.');
        }
        return $tokens;
    }

    public static function statements(string $sql): array
    {
        $statements = []; $current = [];
        foreach (self::tokenize($sql) as $token) {
            if ($token === ['symbol', ';']) {
                if ($current !== []) $statements[] = $current;
                $current = [];
            } else { $current[] = $token; }
        }
        if ($current !== []) $statements[] = $current;
        return $statements;
    }

    public static function fromTokens(array $tokens): self
    {
        $parser = new self('');
        $parser->tokens = $tokens;
        return $parser;
    }

    private function take(string $value): bool
    {
        $token = $this->tokens[$this->position] ?? null;
        if ($token && in_array($token[0], ['word', 'symbol'], true) && strtoupper($token[1]) === $value) {
            $this->position++; return true;
        }
        return false;
    }

    private function need(string $value): void
    {
        if (!$this->take($value)) throw new RuntimeException('D1_SQL_UNSUPPORTED: expected ' . $value . '.');
    }

    private function identifier(): string
    {
        $token = $this->tokens[$this->position++] ?? null;
        if (!$token || !in_array($token[0], ['word', 'identifier'], true) || !preg_match('/^[A-Za-z_][A-Za-z_0-9]{0,63}$/D', $token[1])) {
            throw new RuntimeException('D1_SQL_UNSUPPORTED: unsupported identifier.');
        }
        return strtolower($token[1]);
    }

    public static function quote(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    public static function table(string $scope, string $name): string
    {
        return 'sf_d1_' . substr(hash('sha256', $scope . "\0" . strtolower($name)), 0, 40);
    }

    private function finish(): void
    {
        $this->take(';');
        if ($this->position !== count($this->tokens)) throw new RuntimeException('D1_SQL_UNSUPPORTED: unsupported trailing SQL.');
    }

    private function group(): array
    {
        $this->need('('); $depth = 1; $result = [];
        while ($this->position < count($this->tokens)) {
            $token = $this->tokens[$this->position++];
            if ($token === ['symbol', '(']) $depth++;
            if ($token === ['symbol', ')'] && --$depth === 0) return $result;
            $result[] = $token;
        }
        throw new RuntimeException('D1_SQL_UNSUPPORTED: unclosed expression.');
    }

    private static function split(array $tokens): array
    {
        $parts = []; $part = []; $depth = 0;
        foreach ($tokens as $token) {
            if ($token === ['symbol', '(']) $depth++;
            if ($token === ['symbol', ')']) $depth--;
            if ($token === ['symbol', ','] && $depth === 0) { $parts[] = $part; $part = []; }
            else $part[] = $token;
        }
        $parts[] = $part;
        return $parts;
    }

    /** Schema facts also determine which indexes need MySQL text prefixes. */
    private function column(): array
    {
        $name = $this->identifier();
        $type = strtoupper($this->identifier());
        if (!in_array($type, ['TEXT', 'INTEGER', 'REAL', 'BLOB'], true)) throw new RuntimeException('D1_SQL_UNSUPPORTED: column type ' . $type . '.');
        $primary = false; $notNull = false; $auto = false; $default = null;
        while ($this->position < count($this->tokens)) {
            if ($this->take('PRIMARY')) { $this->need('KEY'); $primary = true; }
            elseif ($this->take('NOT')) { $this->need('NULL'); $notNull = true; }
            elseif ($this->take('AUTOINCREMENT')) { $auto = true; }
            elseif ($this->take('DEFAULT')) {
                if (($this->tokens[$this->position] ?? null) === ['symbol', '(']) {
                    $default = '(' . self::expression($this->group()) . ')';
                } else {
                    $token = $this->tokens[$this->position++] ?? null;
                    if (!$token || !in_array($token[0], ['number', 'string'], true)) throw new RuntimeException('D1_SQL_UNSUPPORTED: column default.');
                    $default = self::expression([$token]);
                    if ($token[0] === 'string') $default = '(' . $default . ')';
                }
            } else { throw new RuntimeException('D1_SQL_UNSUPPORTED: column constraint.'); }
        }
        if ($auto && (!$primary || $type !== 'INTEGER')) throw new RuntimeException('D1_SQL_UNSUPPORTED: AUTOINCREMENT requires INTEGER PRIMARY KEY.');
        $mysqlType = match ($type) {
            'INTEGER' => 'BIGINT', 'REAL' => 'DOUBLE', 'BLOB' => 'LONGBLOB',
            'TEXT' => $primary ? 'VARBINARY(3072)' : 'LONGTEXT',
        };
        // A SQLite INTEGER PRIMARY KEY generates rowids even without AUTOINCREMENT.
        if ($primary && $type === 'INTEGER') $auto = true;
        if ($primary && !in_array($type, ['INTEGER', 'TEXT'], true)) throw new RuntimeException('D1_SQL_UNSUPPORTED: primary key type.');
        return [
            'name' => $name, 'type' => $type, 'primary' => $primary,
            'sql' => self::quote($name) . ' ' . $mysqlType . ($notNull ? ' NOT NULL' : '')
                . ($default !== null ? ' DEFAULT ' . $default : '') . ($primary ? ' PRIMARY KEY' : '') . ($auto ? ' AUTO_INCREMENT' : ''),
        ];
    }

    public function migration(string $scope, array &$schema): string
    {
        if ($this->take('CREATE')) {
            if ($this->take('TABLE')) {
                if ($this->take('IF')) { $this->need('NOT'); $this->need('EXISTS'); }
                $table = $this->identifier();
                if (isset($schema[$table])) throw new RuntimeException('D1_SQL_UNSUPPORTED: table already declared.');
                $columns = [];
                foreach (self::split($this->group()) as $part) {
                    $column = self::fromTokens($part)->column();
                    if (isset($columns[$column['name']])) throw new RuntimeException('D1_SQL_UNSUPPORTED: duplicate column.');
                    $columns[$column['name']] = $column;
                }
                $this->finish();
                $schema[$table] = $columns;
                return 'CREATE TABLE ' . self::quote(self::table($scope, $table)) . ' (' . implode(', ', array_column($columns, 'sql')) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin';
            }
            $this->need('INDEX');
            if ($this->take('IF')) { $this->need('NOT'); $this->need('EXISTS'); }
            $index = $this->identifier(); $this->need('ON'); $table = $this->identifier();
            $columns = [];
            foreach (self::split($this->group()) as $part) {
                $parser = self::fromTokens($part); $name = $parser->identifier(); $parser->finish();
                $column = $schema[$table][$name] ?? null;
                if (!$column) throw new RuntimeException('D1_SQL_UNSUPPORTED: index column is not declared.');
                $columns[] = self::quote($name) . (in_array($column['type'], ['TEXT', 'BLOB'], true) ? '(191)' : '');
            }
            $this->finish();
            return 'CREATE INDEX ' . self::quote($index) . ' ON ' . self::quote(self::table($scope, $table)) . ' (' . implode(', ', $columns) . ')';
        }
        $this->need('ALTER'); $this->need('TABLE'); $table = $this->identifier();
        if (!isset($schema[$table])) throw new RuntimeException('D1_SQL_UNSUPPORTED: table is not declared.');
        $this->need('ADD'); $this->take('COLUMN');
        $column = $this->column();
        if ($column['primary'] || isset($schema[$table][$column['name']])) throw new RuntimeException('D1_SQL_UNSUPPORTED: column already exists or changes the primary key.');
        $schema[$table][$column['name']] = $column;
        return 'ALTER TABLE ' . self::quote(self::table($scope, $table)) . ' ADD COLUMN ' . $column['sql'];
    }

    private static function expression(array $tokens): string
    {
        $parser = self::fromTokens($tokens); $out = [];
        $keywords = ['AS','AND','OR','NOT','NULL','IS','IN','LIKE','BETWEEN','DISTINCT','ASC','DESC','CASE','WHEN','THEN','ELSE','END'];
        $functions = ['COUNT','COALESCE','ABS','MIN','MAX','SUM','AVG','ROUND'];
        while ($parser->position < count($tokens)) {
            $token = $tokens[$parser->position++];
            [$kind, $value] = $token;
            $upper = strtoupper($value);
            if ($kind === 'word' && in_array($upper, ['SELECT','FROM','JOIN','UNION','INTO','OUTFILE','LOAD_FILE','SLEEP','BENCHMARK'], true)) throw new RuntimeException('D1_SQL_UNSUPPORTED: subquery or unsupported expression.');
            if (($kind === 'word' || $kind === 'identifier') && ($tokens[$parser->position] ?? null) === ['symbol', '(']) {
                if ($upper === 'IN') { $out[] = 'IN (' . self::expression($parser->group()) . ')'; continue; }
                $args = $parser->group();
                if (in_array($upper, ['DATE','DATETIME'], true)) {
                    $parts = self::split($args);
                    $base = $parts[0] ?? [];
                    $now = $base === [['string', 'now']];
                    $sql = $now ? 'UTC_TIMESTAMP()' : self::expression($base);
                    foreach (array_slice($parts, 1) as $modifier) {
                        if (!$now || count($modifier) !== 1 || $modifier[0][0] !== 'string' || !preg_match('/^([+-]?\d+) days?$/D', $modifier[0][1], $m)) {
                            throw new RuntimeException('D1_SQL_UNSUPPORTED: date modifier.');
                        }
                        $sql = 'DATE_ADD(' . $sql . ', INTERVAL ' . (int) $m[1] . ' DAY)';
                    }
                    $out[] = "DATE_FORMAT(" . $sql . ", '" . ($upper === 'DATE' ? '%Y-%m-%d' : '%Y-%m-%d %H:%i:%s') . "')";
                } elseif (in_array($upper, $functions, true)) {
                    $out[] = $upper . '(' . self::expression($args) . ')';
                } else { throw new RuntimeException('D1_SQL_UNSUPPORTED: function ' . $upper . '.'); }
            } elseif ($kind === 'string') {
                $out[] = $value === '' ? "''" : "CONVERT(X'" . bin2hex($value) . "' USING utf8mb4)";
            } elseif ($kind === 'word' && in_array($upper, $keywords, true)) { $out[] = $upper; }
            elseif ($kind === 'word' || $kind === 'identifier') { $out[] = self::quote($value); }
            elseif ($kind === 'number' || ($kind === 'symbol' && $value !== ';')) { $out[] = $value; }
            else { throw new RuntimeException('D1_SQL_UNSUPPORTED: expression.'); }
        }
        return implode(' ', $out);
    }

    private function until(array $words): array
    {
        $result = []; $depth = 0;
        while ($this->position < count($this->tokens)) {
            $token = $this->tokens[$this->position];
            if ($depth === 0 && ($token === ['symbol', ';'] || ($token[0] === 'word' && in_array(strtoupper($token[1]), $words, true)))) break;
            if ($token === ['symbol', '(']) $depth++;
            if ($token === ['symbol', ')']) $depth--;
            if ($depth < 0) throw new RuntimeException('D1_SQL_UNSUPPORTED: unbalanced expression.');
            $result[] = $token; $this->position++;
        }
        if ($depth !== 0 || $result === []) throw new RuntimeException('D1_SQL_UNSUPPORTED: empty or unbalanced expression.');
        return $result;
    }

    /** A single declared table per statement; joins and subqueries are refused. */
    public function query(string $scope, array $schema): array
    {
        $command = strtoupper($this->identifier());
        if (!in_array($command, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) throw new RuntimeException('D1_SQL_UNSUPPORTED: statement ' . $command . '.');
        $output = [$command]; $conflict = null;
        if ($command === 'SELECT') {
            $output[] = self::expression($this->until(['FROM']));
            $this->need('FROM'); $output[] = 'FROM';
        } elseif ($command === 'INSERT') { $this->need('INTO'); $output[] = 'INTO'; }
        elseif ($command === 'DELETE') { $this->need('FROM'); $output[] = 'FROM'; }
        $table = $this->identifier();
        if (!isset($schema[$table])) throw new RuntimeException('no such table: ' . $table);
        $output[] = self::quote(self::table($scope, $table));
        if ($command === 'INSERT') {
            $columns = [];
            foreach (self::split($this->group()) as $part) {
                $p = self::fromTokens($part); $column = $p->identifier(); $p->finish();
                if (!isset($schema[$table][$column])) throw new RuntimeException('D1_SQL_UNSUPPORTED: undeclared insert column.');
                $columns[] = self::quote($column);
            }
            $output[] = '(' . implode(',', $columns) . ')';
            $this->need('VALUES'); $output[] = 'VALUES';
            $values = []; $rowCount = 0;
            do { $values[] = '(' . self::expression($this->group()) . ')'; $rowCount++; } while ($this->take(','));
            $output[] = implode(',', $values);
            if ($this->take('ON')) {
                if ($rowCount !== 1) throw new RuntimeException('D1_SQL_UNSUPPORTED: upsert requires one VALUES row.');
                $this->need('CONFLICT');
                $keys = array_map(function ($part) { $p = self::fromTokens($part); $key = $p->identifier(); $p->finish(); return $key; }, self::split($this->group()));
                $primary = array_keys(array_filter($schema[$table], fn ($column) => $column['primary']));
                if ($keys !== $primary || count($primary) !== 1) throw new RuntimeException('D1_SQL_UNSUPPORTED: conflict target must name the primary key.');
                $this->need('DO');
                if ($this->take('NOTHING')) {
                    $key = self::quote($primary[0]); $output[] = 'ON DUPLICATE KEY UPDATE ' . $key . '=' . $key; $conflict = 'nothing';
                } else {
                    $this->need('UPDATE'); $this->need('SET'); $assignments = [];
                    do {
                        $column = $this->identifier(); $this->need('='); $this->need('EXCLUDED'); $this->need('.'); $source = $this->identifier();
                        if (!isset($schema[$table][$column], $schema[$table][$source]) || $schema[$table][$column]['primary']) throw new RuntimeException('D1_SQL_UNSUPPORTED: upsert assignment.');
                        $assignments[] = self::quote($column) . '=VALUES(' . self::quote($source) . ')';
                    } while ($this->take(','));
                    $output[] = 'ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments); $conflict = 'update';
                }
            }
        } else {
            if ($command === 'UPDATE') { $this->need('SET'); $output[] = 'SET ' . self::expression($this->until(['WHERE'])); }
            if ($this->take('WHERE')) $output[] = 'WHERE ' . self::expression($this->until(['GROUP','ORDER','LIMIT']));
            if ($command === 'SELECT') {
                if ($this->take('GROUP')) { $this->need('BY'); $output[] = 'GROUP BY ' . self::expression($this->until(['ORDER','LIMIT'])); }
                if ($this->take('ORDER')) { $this->need('BY'); $output[] = 'ORDER BY ' . self::expression($this->until(['LIMIT'])); }
                if ($this->take('LIMIT')) $output[] = 'LIMIT ' . self::expression($this->until(['OFFSET']));
                if ($this->take('OFFSET')) $output[] = 'OFFSET ' . self::expression($this->until([]));
            }
        }
        $this->finish();
        return ['sql' => implode(' ', $output), 'mode' => $command === 'SELECT' ? 'query' : 'execute', 'conflict' => $conflict];
    }
}
