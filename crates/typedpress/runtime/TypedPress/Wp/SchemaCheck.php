<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * The deploy-time schema check (spec §2.9): before a migration file runs, every statement the
 * compiler marked with `-- typedpress-check {json}` is measured against the live rows.
 *
 * - `drop` (a kind or a field): a table or column that holds rows is dropped only when the deploy
 *   names it with its exact live count, `--drop=likes=1204` or `--drop=likes.note=12`. A table TypedPress
 *   didn't create (`existingTable`, e.g. one adopted from Zero 0.8) needs `--drop` even when empty.
 * - `required`: a field that becomes required refuses while rows have no value for it.
 * - `narrow`: a field whose stored type or length narrows refuses while values wouldn't fit
 *   (WordPress runs MySQL without strict mode, which would truncate or coerce them).
 * - `rename` and `alter` on a table TypedPress didn't create: refused without `--drop` naming the field.
 *
 * Every check names the table and column as they stand before its statement runs. All pending
 * statements are checked at once, so a `rename` earlier in the plan moves the name the later
 * checks count under; a table or column that can't be found is a refusal, never a zero count.
 * Every refusal is collected before anything runs, so one deploy reports them all. Additive
 * changes carry no check and apply without one.
 */
final class SchemaCheck
{
    public const MARK = '-- typedpress-check ';

    /** @var array<string, string> physical table => its name before any pending statement ran */
    private array $tables = [];
    /** @var array<string, array<string, string>> live table => [planned column => live column] */
    private array $columns = [];
    /** @var array<string, true> tables a pending statement creates */
    private array $created = [];

    /** @param array<string, int> $drops name => the row count the deploy confirmed */
    public function __construct(private readonly Connection $db, private readonly array $drops = [])
    {
    }

    /**
     * `--drop` values: `name=N` (a kind, or `kind.field`), comma-separated or repeated. A host may
     * pass `['likes' => 1204]` instead; counts given as digit strings are accepted too.
     *
     * @param list<mixed>|array<string, mixed> $values
     * @return array<string, int>
     */
    public static function drops(array $values): array
    {
        $drops = [];
        if (!array_is_list($values)) {
            $values = array_map(static fn ($name, $rows): mixed => is_string($name) && (is_int($rows) || is_string($rows)) ? "$name=$rows" : null, array_keys($values), $values);
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                // `--drop likes=1204` (a space, not `=`) reaches WP-CLI as a bare `--drop` and a stray argument.
                throw new \TypedPress\HttpError(422, 'invalid_drop', '--drop takes name=rows after an equals sign: --drop=likes=1204', '/drop');
            }
            foreach (explode(',', $value) as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)=(0|[1-9][0-9]*)$/', $item, $m)) {
                    throw new \TypedPress\HttpError(422, 'invalid_drop', "--drop takes name=rows, e.g. --drop=likes=1204 or --drop=likes.note=12; got \"$item\"", '/drop');
                }
                $drops[$m[1]] = (int) $m[2];
            }
        }

        return $drops;
    }

    /** The statement's check, or null when it carries none. */
    public static function parse(string $statement): ?array
    {
        if (!str_starts_with($statement, self::MARK)) {
            return null;
        }

        return json_decode(substr(explode("\n", $statement, 2)[0], strlen(self::MARK)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The statement without its check line: what the database runs. */
    public static function sql(string $statement): string
    {
        return str_starts_with($statement, self::MARK) ? (explode("\n", $statement, 2)[1] ?? '') : $statement;
    }

    /**
     * Refuses the pending statements when live rows don't allow them.
     *
     * `$applied` are the statements of partly applied files that already ran: they aren't measured,
     * but their renames are replayed, so a check written against the name a table had before the
     * plan finds the name it has now (a migration that failed after a rename resumes).
     *
     * @param array<string, list<string>> $pending migration id => its statements not yet applied
     * @param list<string> $applied statements already applied, in order
     * @throws SchemaRefused
     */
    public function check(array $pending, array $applied = []): void
    {
        foreach ($applied as $statement) {
            $done = self::parse($statement);
            if (($done['check'] ?? null) === 'rename') {
                $done['table'] = str_replace('{prefix}', $this->db->prefix(), (string) $done['table']);
                $this->moved($done);
            }
        }
        $refusals = [];
        foreach ($pending as $id => $statements) {
            foreach ($statements as $statement) {
                if (!str_starts_with($statement, self::MARK)) {
                    $this->follow($statement);
                    continue;
                }
                $check = json_decode(substr(explode("\n", $statement, 2)[0], strlen(self::MARK)), true, flags: JSON_THROW_ON_ERROR);
                $check['table'] = str_replace('{prefix}', $this->db->prefix(), (string) $check['table']);
                $refusal = match ($check['check']) {
                    'drop' => $this->drop($check),
                    'required' => $this->required($check, (string) $id),
                    'narrow' => $this->narrow($check, (string) $id),
                    'rename', 'alter' => $this->adoptedChange($check),
                    default => throw new \RuntimeException('unknown schema check ' . $check['check']),
                };
                if ($refusal !== null) {
                    $refusals[] = ['migration' => (string) $id] + $refusal;
                }
                // Later checks see this statement's effect on names.
                if ($check['check'] === 'rename') {
                    $this->renamed($check);
                }
                $this->follow(explode("\n", $statement, 2)[1] ?? '');
            }
        }
        if ($refusals !== []) {
            throw new SchemaRefused($refusals);
        }
    }

    private function drop(array $check): ?array
    {
        $name = (string) $check['name'];
        $column = $check['column'] ?? null;
        $rows = $this->count($check, $column === null ? null : '{col} IS NOT NULL');
        if ($rows === null) {
            return $this->missing($check);
        }
        $adopted = ($check['adopted'] ?? false) === true;
        $confirmed = $this->drops[$name] ?? null;
        $what = $column === null ? "the kind $name" : "the field $name";
        $holds = $column === null ? "$rows rows" : "$rows rows with a value";
        if ($confirmed === $rows || ($rows === 0 && !$adopted)) {
            return null;
        }
        $refusal = ['name' => $name, 'table' => $check['table'], 'rows' => $rows, 'drop' => "$name=$rows"];
        if ($column !== null) {
            $refusal['column'] = $column;
        }
        if ($confirmed !== null) {
            return $refusal + [
                'code' => 'drop_count_mismatch',
                'message' => "--drop=$name=$confirmed doesn't match the live data: $what holds $holds now.",
                'fix' => "Check what changed, then deploy with --drop=$name=$rows to delete them, or keep $name in the schema.",
            ];
        }
        if ($adopted) {
            return $refusal + [
                'code' => 'drop_adopted_table',
                'message' => "Dropping $what would " . ($column === null ? 'drop' : 'alter') . " {$check['table']}, a table TypedPress didn't create ($holds).",
                'fix' => "Keep $name in the schema, or deploy with --drop=$name=$rows to delete it.",
            ];
        }

        return $refusal + [
            'code' => 'drop_has_rows',
            'message' => "Dropping $what would delete $holds.",
            'fix' => "Keep $name in the schema, rename it with .was() if it moved, or deploy with --drop=$name=$rows to delete them.",
        ];
    }

    private function required(array $check, string $migration): ?array
    {
        $column = $check['column'] ?? null;
        $name = (string) $check['name'];
        // A field that becomes required may narrow in the same change: the values it has must fit too,
        // or MySQL truncates them. Each kind of row is refused with its own code and count.
        if ($column !== null && isset($check['fits'])) {
            $narrow = $this->narrow($check, $migration);
            if ($narrow !== null) {
                return $narrow;
            }
        }
        // A new column has no values yet: every row lacks it.
        $rows = $this->count($check, $column === null ? null : '{col} IS NULL');
        if ($rows === null) {
            return $this->missing($check);
        }
        if ($rows === 0) {
            return null;
        }
        $field = substr($name, strrpos($name, '.') + 1);
        $keep = "give $field a default with .default(value), which existing rows get, or keep it optional: "
            . "delete the unapplied tp/_generated/migrations/$migration.sql, restore snapshot.json to the last deployed build, and build again.";
        // The site keeps refusing until a migration runs again; filling the rows alone doesn't retry it.
        $fix = $column === null ? ucfirst($keep) : "Fill those rows, then run `wp typedpress migrate` (or deploy again); or $keep";

        return ['code' => 'required_field_missing', 'name' => $name, 'table' => $check['table'], 'rows' => $rows,
            'message' => "$name is required, and $rows rows have no value for it.", 'fix' => $fix];
    }

    /** A table a pending statement creates: checks of it run against no rows. */
    private function follow(string $statement): void
    {
        if (preg_match('/^CREATE TABLE `((?:[^`]|``)+)`/', $statement, $m)) {
            $this->created[str_replace('``', '`', $m[1])] = true;
        } elseif (preg_match('/^ALTER TABLE `((?:[^`]|``)+)` ADD COLUMN `((?:[^`]|``)+)`/', $statement, $m)) {
            $this->adds[str_replace('``', '`', $m[1])][str_replace('``', '`', $m[2])] = true;
        }
    }

    /** A pending rename: later checks name the new table or column; it still holds the old one's rows. */
    private function renamed(array $check): void
    {
        $to = (string) $check['to'];
        if (isset($check['column'])) {
            $live = $this->live($check['table']);
            $this->columns[$live][$to] = $this->columns[$live][(string) $check['column']] ?? (string) $check['column'];
            unset($this->columns[$live][(string) $check['column']]);

            return;
        }
        $to = str_replace('{prefix}', $this->db->prefix(), $to);
        $this->tables[$to] = $this->live($check['table']);
        unset($this->tables[$check['table']]);
        if (isset($this->created[$check['table']])) {
            $this->created[$to] = true;
        }
    }

    /**
     * A rename that already ran: the name a check uses (the one before the plan) now lives under
     * the new name. The opposite of renamed(), which keeps a pending rename's new name on the old.
     */
    private function moved(array $check): void
    {
        $to = (string) $check['to'];
        if (isset($check['column'])) {
            $live = $this->live($check['table']);
            $this->columns[$live][(string) $check['column']] = $to;

            return;
        }
        $to = str_replace('{prefix}', $this->db->prefix(), $to);
        $from = (string) $check['table'];
        // Column renames recorded under the old table follow it.
        if (isset($this->columns[$from])) {
            $this->columns[$to] = $this->columns[$from];
            unset($this->columns[$from]);
        }
        foreach ($this->tables as $planned => $live) {
            if ($live === $from) {
                $this->tables[$planned] = $to;
            }
        }
        $this->tables[$from] = $to;
    }

    /** The table as it is now, before any pending statement runs. */
    private function live(string $table): string
    {
        return $this->tables[$table] ?? $table;
    }

    private function narrow(array $check, string $migration): ?array
    {
        $name = (string) $check['name'];
        $fits = (string) $check['fits'];
        $adopted = ($check['adopted'] ?? false) === true;
        $rows = $this->count($check, $fits === 'false' ? '{col} IS NOT NULL' : "{col} IS NOT NULL AND NOT ($fits)");
        if ($rows === null) {
            return $this->missing($check);
        }
        $confirmed = $this->drops[$name] ?? null;
        if ($confirmed === $rows || ($rows === 0 && !$adopted)) {
            return null;
        }
        $refusal = ['name' => $name, 'table' => $check['table'], 'column' => $check['column'], 'rows' => $rows, 'drop' => "$name=$rows"];
        if ($confirmed !== null) {
            return $refusal + ['code' => 'drop_count_mismatch',
                'message' => "--drop=$name=$confirmed doesn't match the live data: $rows values of $name don't fit its new type now.",
                'fix' => "Check what changed, then deploy with --drop=$name=$rows to change them, or keep $name's type."];
        }
        if ($adopted && $rows === 0) {
            return $refusal + ['code' => 'drop_adopted_table',
                'message' => "Changing $name's type would alter {$check['table']}, a table TypedPress didn't create.",
                'fix' => "Keep $name's type, or deploy with --drop=$name=0 to change it."];
        }

        return $refusal + ['code' => 'value_does_not_fit',
            'message' => "$name's new type or length doesn't fit $rows existing values; MySQL would truncate or convert them.",
            'fix' => "Fix those values first, or keep $name's type: delete the unapplied tp/_generated/migrations/$migration.sql, restore snapshot.json to the last deployed build, and build again. To change them anyway, deploy with --drop=$name=$rows."];
    }

    /** A rename or retype of a column of a table TypedPress didn't create: only with --drop naming it. */
    private function adoptedChange(array $check): ?array
    {
        if (($check['adopted'] ?? false) !== true) {
            return null;
        }
        $name = (string) $check['name'];
        $rows = $this->count($check, '{col} IS NOT NULL');
        if ($rows === null) {
            return $this->missing($check);
        }
        if (($this->drops[$name] ?? null) === $rows) {
            return null;
        }
        $what = $check['check'] === 'rename' ? "Renaming $name" : "Changing $name's type";

        return ['code' => isset($this->drops[$name]) ? 'drop_count_mismatch' : 'drop_adopted_table', 'name' => $name,
            'table' => $check['table'], 'column' => $check['column'], 'rows' => $rows, 'drop' => "$name=$rows",
            'message' => "$what would alter {$check['table']}, a table TypedPress didn't create ($rows rows with a value).",
            'fix' => "Keep $name as it is, or deploy with --drop=$name=$rows to change it."];
    }

    private function missing(array $check): array
    {
        $what = isset($check['column']) ? "column {$check['column']} of {$check['table']}" : "table {$check['table']}";

        return ['code' => 'schema_out_of_step', 'name' => (string) ($check['name'] ?? $check['table']), 'table' => $check['table'], 'rows' => 0,
            'message' => "The database has no $what, which this build's migrations expect to find.",
            'fix' => 'Check the database against tp/_generated/migrations/ (a statement may have been applied by hand, or the ledger edited) and fix whichever is wrong; nothing was changed.'];
    }

    /** The live column a check names, after pending renames; null when its table or column doesn't exist. */
    private function column(array $check): ?string
    {
        $table = $this->live($check['table']);
        $column = (string) $check['column'];
        $live = $this->columns[$table][$column] ?? $column;
        if (isset($this->created[$check['table']]) && !$this->exists($table)) {
            return $live;
        }

        return $this->exists($table, $live) ? $live : null;
    }

    private function exists(string $table, ?string $column = null): bool
    {
        return $column === null
            ? $this->db->run('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== []
            : $this->db->run('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) !== [];
    }

    /**
     * Rows of the check's table, where `$where` holds for its column (`{col}` names it); 0 for a table
     * a pending statement creates; null when the table or column isn't there to count.
     */
    private function count(array $check, ?string $where): ?int
    {
        $table = $this->live($check['table']);
        if (!$this->exists($table)) {
            return isset($this->created[$check['table']]) ? 0 : null;
        }
        $sql = 'SELECT COUNT(*) AS n FROM `' . str_replace('`', '``', $table) . '`';
        if (isset($check['column']) && $where !== null) {
            $column = $this->column($check);
            if ($column === null) {
                // A column a pending statement adds holds nothing yet.
                return $this->added($check) ? 0 : null;
            }
            $sql .= ' WHERE ' . str_replace('{col}', '`' . str_replace('`', '``', $column) . '`', $where);
        }
        // A count that can't be taken (the condition overflowed on some row, say) is never a zero:
        // that would let the change through unmeasured.
        try {
            $n = $this->db->run($sql)[0]['n'] ?? null;
        } catch (DbError $e) {
            throw new SchemaRefused([['code' => 'check_failed', 'name' => (string) ($check['name'] ?? $table), 'table' => $table, 'rows' => 0,
                'message' => "Couldn't count the rows {$check['name']} would change: " . $e->getMessage(),
                'fix' => 'Nothing was changed. Report this with the message; the values in that column need a look before the change can run.']]);
        }
        if (!is_numeric($n)) {
            throw new SchemaRefused([['code' => 'check_failed', 'name' => (string) ($check['name'] ?? $table), 'table' => $table, 'rows' => 0,
                'message' => "Couldn't count the rows {$check['name']} would change: the count returned no row.",
                'fix' => 'Nothing was changed. Report this; the change can\'t run until it can be measured.']]);
        }

        return (int) $n;
    }

    /** @var array<string, array<string, true>> table => columns pending statements add */
    private array $adds = [];

    private function added(array $check): bool
    {
        return isset($this->adds[$check['table']][(string) $check['column']]);
    }
}
