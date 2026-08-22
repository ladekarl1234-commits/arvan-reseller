<?php
/**
 * A `$wpdb`-shaped test double backed by `PDO('sqlite::memory:')`, plus the
 * `dbDelta()` shim that actually creates the plugin's tables from the same
 * MySQL DDL `Install\Schema` ships. Together they are the seam that makes every
 * money and idempotency path reachable from `composer test` (EX-078).
 *
 * WHAT THIS DOUBLE PROVES, AND WHAT IT DOES NOT — read before trusting a green
 * run (EX-072):
 *
 *   Proven here: the *contract*. That the UNIQUE keys exist and are the ones
 *   the design names; that a second `INSERT IGNORE` on the same business key
 *   affects zero rows; that a `WHERE old_value = %s` guard matches exactly one
 *   of two sequential attempts; that the code branches correctly on all three.
 *
 *   NOT proven here: MySQL/InnoDB semantics. SQLite serialises writes on one
 *   connection, so nothing below simulates two transactions racing, row locks,
 *   gap locks or deadlocks. SQLite is also dynamically typed: it does not
 *   truncate an over-long `varchar(64)` and does not reject an out-of-range
 *   `bigint`, which is precisely the MySQL failure mode `Ledger::append`'s
 *   "no row and no error" branch defends against — that branch is unreachable
 *   through real SQL here and is instead driven through `$intercept` below,
 *   which fabricates the `rows_affected = 0, last_error = ''` shape MySQL
 *   produces. utf8mb4 collation of the Persian text columns and dbDelta's
 *   191-char index-length behaviour are likewise untested by this harness.
 *   Those need `tests/integration/e2e.php` against a real MySQL install.
 *
 * The double is deliberately thin: it translates the handful of MySQL-isms the
 * plugin actually emits and otherwise hands the SQL to SQLite unchanged, so a
 * query that would not parse on the real thing does not quietly pass here.
 */

defined('ABSPATH') || exit;

final class Arvrs_FakeWpdb
{
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $users = 'wp_users';
    public $usermeta = 'wp_usermeta';

    public $insert_id = 0;
    public $rows_affected = 0;
    public $last_error = '';
    public $last_query = '';
    public $num_queries = 0;

    /**
     * Test hook: `fn(string $sql): ?int`. A non-null return short-circuits the
     * query with that many rows affected and never touches SQLite — the only
     * way to reproduce MySQL's swallowed-write shape (see the header).
     *
     * @var callable|null
     */
    public $intercept = null;

    /** @var \PDO */
    private $pdo;

    /** @var bool */
    private $suppress = false;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        // wp_options exists as a real table so the v4→v5 top-up migration —
        // which reads it with SQL and deletes with delete_option() — runs for
        // real instead of against a stub.
        $this->pdo->exec('CREATE TABLE ' . $this->options .
            ' (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    public function suppress_errors($suppress = true): bool
    {
        $was = $this->suppress;
        $this->suppress = (bool) $suppress;
        return $was;
    }

    public function esc_like($text): string
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function flush(): void
    {
        $this->last_error = '';
        $this->rows_affected = 0;
    }

    // ------------------------------------------------------------- prepare

    /**
     * WordPress accepts both `prepare($sql, $a, $b)` and `prepare($sql, [$a,$b])`.
     * `%s` is quoted by prepare itself — the plugin's SQL never quotes it — so
     * a missing quote in the shim would be a silent syntax difference.
     */
    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $args = array_values($args);
        $i    = 0;
        return preg_replace_callback('/%(%|[dfsF])/', function ($m) use (&$i, $args) {
            if ($m[1] === '%') {
                return '%';
            }
            $value = array_key_exists($i, $args) ? $args[$i] : null;
            $i++;
            if ($m[1] === 'd') {
                return (string) (int) $value;
            }
            if ($m[1] === 'f' || $m[1] === 'F') {
                return sprintf('%F', (float) $value);
            }
            return $this->pdo->quote((string) $value);
        }, (string) $query);
    }

    // ------------------------------------------------------------- readers

    public function get_var($query = null, $x = 0, $y = 0)
    {
        $rows = $this->fetch_all($query);
        if (!isset($rows[$y])) {
            return null;
        }
        $row = array_values($rows[$y]);
        return isset($row[$x]) ? $row[$x] : null;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0)
    {
        $rows = $this->fetch_all($query);
        if (!isset($rows[$y])) {
            return null;
        }
        return $output === ARRAY_A ? $rows[$y] : (object) $rows[$y];
    }

    public function get_col($query = null, $x = 0): array
    {
        $out = [];
        foreach ($this->fetch_all($query) as $row) {
            $values = array_values($row);
            $out[]  = isset($values[$x]) ? $values[$x] : null;
        }
        return $out;
    }

    public function get_results($query = null, $output = OBJECT)
    {
        $rows = $this->fetch_all($query);
        if ($output === ARRAY_A) {
            return $rows;
        }
        if ($output === ARRAY_N) {
            return array_map('array_values', $rows);
        }
        return array_map(function ($row) {
            return (object) $row;
        }, $rows);
    }

    // ------------------------------------------------------------- writers

    /** @return int|false rows affected, false on error (WordPress semantics) */
    public function query($query)
    {
        $sql = $this->translate((string) $query);
        $this->last_query = $sql;
        $this->num_queries++;
        $this->last_error = '';

        if ($this->intercept !== null) {
            $forced = call_user_func($this->intercept, $sql);
            if ($forced !== null) {
                $this->rows_affected = (int) $forced;
                return (int) $forced;
            }
        }
        if (stripos(ltrim($sql), 'SELECT') === 0 || stripos(ltrim($sql), 'PRAGMA') === 0) {
            $rows = $this->fetch_all($query);
            $this->rows_affected = count($rows);
            return count($rows);
        }
        try {
            $affected = $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            $this->rows_affected = 0;
            $this->last_error    = $e->getMessage();
            return false;
        }
        $this->rows_affected = (int) $affected;
        if (stripos(ltrim($sql), 'INSERT') === 0 && $affected > 0) {
            $this->insert_id = (int) $this->pdo->lastInsertId();
        }
        return (int) $affected;
    }

    /** @return int|false */
    public function insert($table, $data, $format = null)
    {
        $cols   = array_keys($data);
        $values = [];
        foreach ($data as $value) {
            $values[] = $this->literal($value);
        }
        return $this->query('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $values) . ')');
    }

    /** @return int|false */
    public function replace($table, $data, $format = null)
    {
        $cols   = array_keys($data);
        $values = [];
        foreach ($data as $value) {
            $values[] = $this->literal($value);
        }
        return $this->query('INSERT OR REPLACE INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $values) . ')');
    }

    /** @return int|false */
    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $set = [];
        foreach ($data as $col => $value) {
            $set[] = $col . ' = ' . $this->literal($value);
        }
        return $this->query('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $this->where_sql($where));
    }

    /** @return int|false */
    public function delete($table, $where, $where_format = null)
    {
        return $this->query('DELETE FROM ' . $table . ' WHERE ' . $this->where_sql($where));
    }

    // ------------------------------------------------------------ internals

    private function where_sql(array $where): string
    {
        $parts = [];
        foreach ($where as $col => $value) {
            $parts[] = $value === null ? ($col . ' IS NULL') : ($col . ' = ' . $this->literal($value));
        }
        return $parts ? implode(' AND ', $parts) : '1=1';
    }

    /** @param mixed $value */
    private function literal($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $this->pdo->quote((string) $value);
    }

    /** @return array<int,array<string,mixed>> */
    private function fetch_all($query): array
    {
        $sql = $this->translate((string) $query);
        $this->last_query = $sql;
        $this->num_queries++;
        $this->last_error = '';

        // SHOW INDEX has no SQLite equivalent; it is answered from PRAGMA in
        // MySQL's column shape so Schema::verify() reads it unchanged.
        if (preg_match('/^\s*SHOW\s+INDEX\s+FROM\s+([`\w]+)/i', $sql, $m)) {
            return $this->show_index(trim($m[1], '`'));
        }
        if (preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+([`\w]+)(?:\s+LIKE\s+\'([^\']*)\')?/i', $sql, $m)) {
            return $this->show_columns(trim($m[1], '`'), isset($m[2]) ? $m[2] : '');
        }
        try {
            $stmt = $this->pdo->query($sql);
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Index names are namespaced `{table}__{key}` because SQLite index names
     * are global while MySQL's are per-table; the prefix is stripped again
     * here so callers see the key name they declared.
     *
     * @return array<int,array<string,mixed>>
     */
    private function show_index(string $table): array
    {
        $exists = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($table)
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (!$exists) {
            $this->last_error = 'no such table: ' . $table;
            return [];
        }

        $out     = [];
        $indexes = $this->pdo->query('PRAGMA index_list(' . $this->pdo->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            $name  = (string) $index['name'];
            $key   = strpos($name, $table . '__') === 0 ? substr($name, strlen($table) + 2) : $name;
            if (isset($index['origin']) && $index['origin'] === 'pk') {
                $key = 'PRIMARY';
            }
            $cols = $this->pdo->query('PRAGMA index_info(' . $this->pdo->quote($name) . ')')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $out[] = [
                    'Table'        => $table,
                    'Non_unique'   => (int) $index['unique'] === 1 ? 0 : 1,
                    'Key_name'     => $key,
                    'Seq_in_index' => (int) $col['seqno'] + 1,
                    'Column_name'  => (string) $col['name'],
                ];
            }
        }
        // A table whose only key is the INTEGER PRIMARY KEY rowid alias has no
        // PRAGMA index at all; report it so "readable" never means "empty".
        if (!$out) {
            $out[] = ['Table' => $table, 'Non_unique' => 0, 'Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function show_columns(string $table, string $like): array
    {
        $out = [];
        try {
            $cols = $this->pdo->query('PRAGMA table_info(' . $this->pdo->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
        foreach ($cols as $col) {
            if ($like !== '' && (string) $col['name'] !== $like) {
                continue;
            }
            $out[] = ['Field' => (string) $col['name'], 'Type' => (string) $col['type'], 'Null' => $col['notnull'] ? 'NO' : 'YES'];
        }
        return $out;
    }

    /** The MySQL-isms the plugin actually emits, and nothing else. */
    private function translate(string $sql): string
    {
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);

        // MySQL upsert → SQLite upsert. `VALUES(col)` in the update list is
        // MySQL's name for the row that failed to insert; SQLite calls it
        // `excluded`. The conflict target is left implicit, which SQLite
        // resolves against the table's unique indexes — the same ones MySQL
        // would have used.
        $parts = preg_split('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql, 2);
        if (count($parts) === 2) {
            $sql = $parts[0] . ' ON CONFLICT DO UPDATE SET '
                 . preg_replace('/\bVALUES\s*\(\s*([`\w]+)\s*\)/i', 'excluded.$1', $parts[1]);
        }

        // SQLite is normally built without SQLITE_ENABLE_UPDATE_DELETE_LIMIT,
        // so the bounded-batch UPDATE/DELETE the migrations and prune() rely on
        // become a rowid sub-select. Same rows, same bound.
        $sql = preg_replace(
            '/^\s*UPDATE\s+([`\w\.]+)\s+SET\s+(.*?)\s+WHERE\s+(.*?)\s+LIMIT\s+(\d+)\s*;?\s*$/is',
            'UPDATE $1 SET $2 WHERE rowid IN (SELECT rowid FROM $1 WHERE $3 LIMIT $4)',
            $sql
        );
        $sql = preg_replace(
            '/^\s*DELETE\s+FROM\s+([`\w\.]+)\s+WHERE\s+(.*?)\s+LIMIT\s+(\d+)\s*;?\s*$/is',
            'DELETE FROM $1 WHERE rowid IN (SELECT rowid FROM $1 WHERE $2 LIMIT $3)',
            $sql
        );

        // MySQL's LIKE escapes with a backslash by default; SQLite needs the
        // ESCAPE clause spelled out or `arvrs\_topup\_%` matches literally
        // nothing. Only patterns that actually contain a backslash are touched.
        $sql = preg_replace_callback(
            "/LIKE\\s+('[^']*')(?!\\s+ESCAPE)/i",
            static function (array $m) {
                return strpos($m[1], '\\') === false ? 'LIKE ' . $m[1] : 'LIKE ' . $m[1] . " ESCAPE '\\'";
            },
            $sql
        );
        return $sql;
    }

    // -------------------------------------------------------------- dbDelta

    /**
     * Execute one `CREATE TABLE` from Schema::migrate after rewriting it for
     * SQLite: MySQL column types become SQLite ones, an AUTO_INCREMENT bigint
     * that is also the PRIMARY KEY becomes `INTEGER PRIMARY KEY AUTOINCREMENT`,
     * and every `UNIQUE KEY` / `KEY` is hoisted into its own CREATE INDEX.
     *
     * Like the real dbDelta this is diff-free and idempotent: re-running it
     * over an existing table is a no-op, which is what lets a migration test
     * call migrate() twice.
     *
     * @return string[] the statements executed (mirrors dbDelta's return shape
     *                  loosely enough for callers that ignore it)
     */
    public function db_delta(string $sql): array
    {
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`\w]+)\s*\((.*)\)[^)]*$/is', trim($sql), $m)) {
            return [];
        }
        $table = trim($m[1], '`');
        $parts = self::split_definitions($m[2]);

        $columns    = [];
        $indexes    = [];
        $primary    = [];
        $auto_col   = '';

        foreach ($parts as $part) {
            if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i', $part, $k)) {
                $primary = array_map('trim', explode(',', $k[1]));
                continue;
            }
            if (preg_match('/^(UNIQUE\s+)?KEY\s+([`\w]+)\s*\((.+)\)$/i', $part, $k)) {
                $indexes[] = [
                    'unique' => trim($k[1]) !== '',
                    'name'   => trim($k[2], '`'),
                    'cols'   => trim($k[3]),
                ];
                continue;
            }
            if (preg_match('/^([`\w]+)\s+(.*)$/s', $part, $c)) {
                $name = trim($c[1], '`');
                if (preg_match('/AUTO_INCREMENT/i', $c[2])) {
                    $auto_col = $name;
                }
                $columns[$name] = self::column_sql($name, $c[2]);
            }
        }

        // A single-column AUTO_INCREMENT primary key is SQLite's rowid alias;
        // declaring it inline is the only spelling SQLite accepts.
        if ($auto_col !== '' && $primary === [$auto_col]) {
            $columns[$auto_col] = $auto_col . ' INTEGER PRIMARY KEY AUTOINCREMENT';
            $primary = [];
        }

        $body = array_values($columns);
        if ($primary) {
            $body[] = 'PRIMARY KEY (' . implode(',', $primary) . ')';
        }

        $run = ['CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode(', ', $body) . ')'];
        foreach ($indexes as $index) {
            $run[] = 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS '
                   . $table . '__' . $index['name'] . ' ON ' . $table . ' (' . $index['cols'] . ')';
        }
        foreach ($run as $statement) {
            $this->pdo->exec($statement);
        }
        return $run;
    }

    /** MySQL column definition → SQLite. Constraints and defaults survive. */
    private static function column_sql(string $name, string $definition): string
    {
        $d = $definition;
        $d = preg_replace('/\bdecimal\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $d);
        $d = preg_replace('/\b(bigint|smallint|mediumint|tinyint|int)\s*\(\s*\d+\s*\)/i', 'INTEGER', $d);
        $d = preg_replace('/\b(varchar|char)\s*\(\s*\d+\s*\)/i', 'TEXT', $d);
        $d = preg_replace('/\b(longtext|mediumtext|tinytext|text|datetime|timestamp|date)\b/i', 'TEXT', $d);
        $d = preg_replace('/\bunsigned\b/i', '', $d);
        $d = preg_replace('/\bAUTO_INCREMENT\b/i', '', $d);
        return $name . ' ' . trim(preg_replace('/\s+/', ' ', $d));
    }

    /** Split a CREATE TABLE body on commas that are not inside parentheses. */
    private static function split_definitions(string $body): array
    {
        $parts = [];
        $depth = 0;
        $buf   = '';
        $len   = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim(preg_replace('/\s+/', ' ', $buf));
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = trim(preg_replace('/\s+/', ' ', $buf));
        }
        return array_values(array_filter($parts, 'strlen'));
    }

    // ------------------------------------------------- wp_options mirroring

    /**
     * The option shims in bootstrap.php keep their fast in-memory array, but
     * mirror every write here so code that reads `{$wpdb->options}` with SQL —
     * the v4→v5 top-up migration is the one that does — sees the same world.
     *
     * @param mixed $value
     */
    public static function mirror_option(string $name, $value): void
    {
        $db = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        if (!$db instanceof self) {
            return;
        }
        $db->query('INSERT OR REPLACE INTO ' . $db->options . ' (option_id, option_name, option_value, autoload) VALUES ('
            . '(SELECT option_id FROM ' . $db->options . ' WHERE option_name = ' . $db->pdo->quote($name) . '), '
            . $db->pdo->quote($name) . ', ' . $db->pdo->quote(serialize($value)) . ", 'no')");
    }

    public static function mirror_delete(string $name): void
    {
        $db = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        if (!$db instanceof self) {
            return;
        }
        $db->query('DELETE FROM ' . $db->options . ' WHERE option_name = ' . $db->pdo->quote($name));
    }
}
