<?php
require_once __DIR__ . '/config.php';

/* ===========================================================
   CAPA DE COMPATIBILIDAD MySQL → SQLite
   El ERP estaba escrito para MySQL. Para funcionar sin servidor
   MySQL, cada sentencia que llega a PDO se traduce aquí a dialecto
   SQLite antes de ejecutarla: DDL (AUTO_INCREMENT, ENGINE=…),
   information_schema, NOW()/CURDATE()/DATE_FORMAT(), INTERVAL,
   ON DUPLICATE KEY UPDATE, INSERT IGNORE, SHOW COLUMNS, etc.
   =========================================================== */

/* Divide los argumentos de una llamada SQL respetando comillas y paréntesis. */
function sql_split_args($s) {
    $args = []; $depth = 0; $cur = ''; $q = null; $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($q !== null) {
            $cur .= $ch;
            if ($ch === $q) $q = null;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; $cur .= $ch; continue; }
        if ($ch === ')') { $depth--; $cur .= $ch; continue; }
        if ($ch === ',' && $depth === 0) { $args[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    if (count($args) === 0 && trim($cur) === '') return [];
    $args[] = $cur;
    return $args;
}

/* Reescribe todas las llamadas fn(...) de la sentencia con balanceo de
   paréntesis (las funciones anidadas se resuelven solas porque cada llamada
   se reescribe sobre el texto ya modificado). */
function sql_map_call($sql, $name, callable $fn) {
    $pos = 0; $nlen = strlen($name);
    while (($i = stripos($sql, $name, $pos)) !== false) {
        $slen = strlen($sql);
        /* no debe formar parte de otro identificador (p.ej. UNIX_TIMESTAMP) */
        if ($i > 0 && (ctype_alnum($sql[$i - 1]) || $sql[$i - 1] === '_')) { $pos = $i + 1; continue; }
        $j = $i + $nlen;
        while ($j < $slen && ($sql[$j] === ' ' || $sql[$j] === "\t" || $sql[$j] === "\n" || $sql[$j] === "\r")) $j++;
        if ($j >= $slen || $sql[$j] !== '(') { $pos = $i + $nlen; continue; }
        $depth = 0; $q = null; $end = -1;
        for ($k = $j; $k < $slen; $k++) {
            $ch = $sql[$k];
            if ($q !== null) { if ($ch === $q) $q = null; continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; continue; }
            if ($ch === '(') $depth++;
            elseif ($ch === ')') { $depth--; if ($depth === 0) { $end = $k; break; } }
        }
        if ($end < 0) break;
        $inner = substr($sql, $j + 1, $end - $j - 1);
        $repl  = (string)call_user_func($fn, sql_split_args($inner));
        $sql   = substr($sql, 0, $i) . $repl . substr($sql, $end + 1);
        $pos   = $i + strlen($repl);
    }
    return $sql;
}

/* En MySQL la división de enteros produce decimal (21/100 = 0.21); en SQLite
   21/100 = 0. Aquí se convierte el divisor entero en real (21/100.0), sin tocar
   nada dentro de comillas. */
function sql_fix_division($sql) {
    $out = ''; $i = 0; $n = strlen($sql); $q = null;
    while ($i < $n) {
        $ch = $sql[$i];
        if ($q !== null) { $out .= $ch; if ($ch === $q) $q = null; $i++; continue; }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $out .= $ch; $i++; continue; }
        if ($ch === '/') {
            $b = strlen($out) - 1;
            while ($b >= 0 && ($out[$b] === ' ' || $out[$b] === "\n" || $out[$b] === "\t" || $out[$b] === "\r")) $b--;
            $rest = substr($sql, $i + 1);
            if ($b >= 0 && (ctype_alnum($out[$b]) || $out[$b] === ')' || $out[$b] === '.')
                && preg_match('/^\s*(\d+)(\.(\d+))?([^\d.])?/', $rest, $m)) {
                $num = $m[1] . (isset($m[3]) && $m[3] !== '' ? '.' . $m[3] : '');
                if (strpos($m[0], '.') === false) {
                    $out .= '/ ' . $num . '.0';
                    $i += strlen($m[0]) - (isset($m[4]) && $m[4] !== '' ? 1 : 0);
                    continue;
                }
            }
        }
        $out .= $ch; $i++;
    }
    return $out;
}

/* Traducción de sentencia MySQL → SQLite. */
function mysql_to_sqlite($sql) {
    if (!is_string($sql) || $sql === '') return $sql;

    /* ---------- DML ---------- */
    /* DELETE alias FROM tabla JOIN … (borrado multi-tabla de MySQL)
       → DELETE FROM tabla WHERE id IN (SELECT alias.id FROM tabla AS alias JOIN …) */
    $sql = preg_replace_callback('/\bDELETE\s+([`"]?\w+[`"]?)\s+FROM\s+([`"]?\w+[`"]?)\s+(?:[`"]?\w+[`"]?\s+)?((?:(?:LEFT\s+OUTER|LEFT|INNER|CROSS)\s+)?JOIN)\b(.*)$/is',
        function ($m) {
            $alias = trim($m[1], '`"');
            $table = trim($m[2], '`"');
            $tail  = $m[4];
            $semi  = '';
            if (substr(rtrim($tail), -1) === ';') { $tail = rtrim(rtrim($tail), ';'); $semi = ';'; }
            return 'DELETE FROM ' . $table . ' WHERE id IN (SELECT ' . $alias . '.id FROM ' . $table . ' AS ' . $alias . ' ' . $m[3] . $tail . ')' . $semi;
        }, $sql);

    /* ---------- DDL ---------- */
    /* ON UPDATE CURRENT_TIMESTAMP no existe en SQLite (los updated_at se
       mantienen con disparadores, ver sqlite_install_triggers). */
    $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP\b/', '', $sql);
    /* id INT AUTO_INCREMENT PRIMARY KEY → INTEGER PRIMARY KEY AUTOINCREMENT */
    $sql = preg_replace('/\b(\w+)\s+\w+\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/', '$1 INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    /* ALTER TABLE … ADD UNIQUE KEY nombre (cols) → CREATE UNIQUE INDEX */
    $sql = preg_replace_callback('/\bALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+ADD\s+UNIQUE(?:\s+KEY)?\s+([`"]?\w+[`"]?)?\s*\(([^)]*)\)/',
        function ($m) {
            $idx = trim((string)($m[2] ?? ''), '`"');
            if ($idx === '') $idx = 'uq_' . $m[1] . '_' . preg_replace('/\W+/', '_', trim($m[3]));
            return 'CREATE UNIQUE INDEX IF NOT EXISTS ' . $idx . ' ON ' . $m[1] . '(' . $m[3] . ')';
        }, $sql);
    /* UNIQUE KEY nombre (cols) → CONSTRAINT nombre UNIQUE (cols) */
    $sql = preg_replace('/\bUNIQUE\s+KEY\s+([`"]?\w+[`"]?)\s*\(([^)]*)\)/', 'CONSTRAINT $1 UNIQUE ($2)', $sql);
    /* índices en línea dentro de CREATE TABLE: KEY/INDEX nombre (cols) y INDEX (cols) */
    $sql = preg_replace('/\b(?:KEY|INDEX)\s+[`"]?\w+[`"]?\s*\([^)]*\)/', '', $sql);
    $sql = preg_replace('/\bINDEX\s*\([^)]*\)/', '', $sql);
    /* al quitar varios índices en línea seguidos pueden quedar comas colgantes antes de ')' */
    $sql = preg_replace('/(?:,\s*)+\)/', ')', $sql);
    /* opciones de tabla: ENGINE=…, DEFAULT CHARSET=…, COLLATE=…, AUTO_INCREMENT=… */
    $sql = preg_replace('/\s+(?:DEFAULT\s+)?(?:ENGINE|CHARSET|CHARACTER\s+SET|COLLATE|ROW_FORMAT|TABLESPACE|KEY_BLOCK_SIZE|AUTO_INCREMENT)\s*=\s*[\w"]+/', '', $sql);
    $sql = preg_replace('/\s+COLLATE\s+(?!NOCASE\b)\w+/', '', $sql);
    $sql = preg_replace('/\s+COMMENT\s*=\s*\'[^\']*\'/', '', $sql);

    /* ---------- information_schema → sqlite_master / pragma ---------- */
    $sql = preg_replace('/SELECT\s+TABLE_NAME,\s+COLUMN_NAME\s+FROM\s+information_schema\.COLUMNS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+DATA_TYPE\s+IN\s*\([^)]*\)/is',
        "SELECT m.name AS TABLE_NAME, p.name AS COLUMN_NAME FROM sqlite_master AS m JOIN pragma_table_info(m.name) AS p"
        . " WHERE m.type = 'table' AND lower(CASE WHEN instr(p.type,'(')>0 THEN substr(p.type,1,instr(p.type,'(')-1) ELSE p.type END)"
        . " IN ('char','character','varchar','nchar','nvarchar','tinytext','text','mediumtext','longtext','clob','nclob','json')", $sql);
    $sql = preg_replace('/SELECT\s+COLUMN_NAME\s+FROM\s+information_schema\.COLUMNS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*(\'[^\']*\'|\?)/i',
        'SELECT name AS COLUMN_NAME FROM pragma_table_info($1)', $sql);
    $sql = preg_replace('/FROM\s+information_schema\.COLUMNS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*(\'[^\']*\'|\?)\s+AND\s+COLUMN_NAME\s*=\s*(\'[^\']*\'|\?)/i',
        'FROM pragma_table_info($1) WHERE name = $2', $sql);
    $sql = preg_replace('/FROM\s+information_schema\.COLUMNS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*(\'[^\']*\'|\?)/i',
        'FROM pragma_table_info($1)', $sql);
    $sql = preg_replace('/FROM\s+information_schema\.TABLES\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*(\'[^\']*\'|\?)/i',
        "FROM sqlite_master WHERE type = 'table' AND name = $1", $sql);
    $sql = preg_replace('/FROM\s+information_schema\.STATISTICS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*(\'[^\']*\'|\?)/i',
        "FROM sqlite_master WHERE type = 'index' AND tbl_name = $1", $sql);
    $sql = preg_replace('/\bindex_name\s*=/i', 'name =', $sql);
    $sql = preg_replace('/\bORDER\s+BY\s+ORDINAL_POSITION\b/i', 'ORDER BY cid', $sql);

    /* ---------- SHOW COLUMNS → pragma_table_info ---------- */
    $sql = preg_replace('/\bSHOW\s+COLUMNS\s+FROM\s+[`"]?(\w+)[`"]?\s+LIKE\s+\'([^\']+)\'/i',
        "SELECT name AS Field, type AS Type FROM pragma_table_info('$1') WHERE name = '$2'", $sql);
    $sql = preg_replace('/\bSHOW\s+COLUMNS\s+FROM\s+[`"]?(\w+)[`"]?/i',
        'SELECT name AS Field, type AS Type FROM pragma_table_info(\'$1\')', $sql);

    /* ---------- funciones ---------- */
    $sql = sql_map_call($sql, 'TIMESTAMP', function ($a) {
        if (count($a) === 2) return 'datetime(' . $a[0] . ', ' . $a[1] . ')';
        if (count($a) === 1) return 'datetime(' . $a[0] . ')';
        return 'TIMESTAMP(' . implode(',', $a) . ')';
    });
    $sql = sql_map_call($sql, 'DATE_FORMAT', function ($a) {
        if (count($a) < 2) return 'DATE_FORMAT(' . implode(',', $a) . ')';
        $fmt = trim($a[1]);
        if (preg_match('/^\'([^\']*)\'$/', $fmt, $m)) {
            /* claves distintas entre MySQL y strftime (%i = minutos → %M) */
            $f = str_replace(['%i', '%k', '%l'], ['%M', '%H', '%I'], $m[1]);
            return "strftime('" . $f . "', " . $a[0] . ')';
        }
        return 'strftime(' . $a[1] . ', ' . $a[0] . ')';
    });
    $unitPlural = ['SECOND'=>'seconds','MINUTE'=>'minutes','HOUR'=>'hours','DAY'=>'days','WEEK'=>'weeks','MONTH'=>'months','YEAR'=>'years'];
    $sql = sql_map_call($sql, 'DATE_ADD', function ($a) use ($unitPlural) {
        if (count($a) < 2) return 'DATE_ADD(' . implode(',', $a) . ')';
        if (!preg_match('/^\s*INTERVAL\s+(\?|\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\s*$/i', trim($a[1]), $m)) {
            return 'DATE_ADD(' . implode(',', $a) . ')';
        }
        $unit = $unitPlural[strtoupper($m[2])];
        $mod  = ($m[1] === '?') ? "('+' || ? || ' $unit')" : "'+" . (int)$m[1] . " $unit'";
        $base = trim($a[0]);
        if (preg_match('/^CURDATE\(\s*\)$/', $base))  return "date('now','localtime', $mod)";
        if (preg_match('/^NOW\(\s*\)$/', $base))      return "datetime('now','localtime', $mod)";
        return "datetime($base, $mod)";
    });
    $sql = sql_map_call($sql, 'DATE_SUB', function ($a) use ($unitPlural) {
        if (count($a) < 2) return 'DATE_SUB(' . implode(',', $a) . ')';
        if (!preg_match('/^\s*INTERVAL\s+(\?|\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\s*$/i', trim($a[1]), $m)) {
            return 'DATE_SUB(' . implode(',', $a) . ')';
        }
        $unit = $unitPlural[strtoupper($m[2])];
        $mod  = ($m[1] === '?') ? "('-' || ? || ' $unit')" : "'-" . (int)$m[1] . " $unit'";
        $base = trim($a[0]);
        if (preg_match('/^CURDATE\(\s*\)$/', $base))  return "date('now','localtime', $mod)";
        if (preg_match('/^NOW\(\s*\)$/', $base))      return "datetime('now','localtime', $mod)";
        return "datetime($base, $mod)";
    });
    $sql = sql_map_call($sql, 'DATEDIFF', function ($a) {
        if (count($a) < 2) return 'DATEDIFF(' . implode(',', $a) . ')';
        return 'CAST(julianday(' . $a[0] . ') - julianday(' . $a[1] . ') AS INT)';
    });
    $sql = sql_map_call($sql, 'UNIX_TIMESTAMP', function ($a) {
        return count($a) === 0 || trim($a[0]) === '' ? "unixepoch('now')" : "unixepoch(" . $a[0] . ", 'utc')";
    });
    $sql = sql_map_call($sql, 'LAST_INSERT_ID', function ($a) {
        return count($a) === 0 || trim($a[0]) === '' ? 'last_insert_rowid()' : '(' . $a[0] . ')';
    });
    $sql = sql_map_call($sql, 'CONCAT', function ($a) {
        if (count($a) === 0) return "''";
        if (count($a) === 1) return $a[0];
        return '(' . implode(' || ', $a) . ')';
    });
    $sql = sql_map_call($sql, 'FIELD', function ($a) {
        if (count($a) < 2) return 'FIELD(' . implode(',', $a) . ')';
        $col = trim(array_shift($a));
        $s = 'CASE ' . $col;
        foreach ($a as $i => $v) $s .= ' WHEN ' . trim($v) . ' THEN ' . $i;
        return $s . ' ELSE 0 END';
    });
    /* YEAR/MONTH/DAY(columna) → strftime (devuelven enteros, como MySQL) */
    foreach (['YEAR' => '%Y', 'MONTH' => '%m', 'DAY' => '%d'] as $fn => $fmt) {
        $sql = sql_map_call($sql, $fn, function ($a) use ($fmt, $fn) {
            if (count($a) < 1) return $fn . '(' . implode(',', $a) . ')';
            return "CAST(strftime('$fmt', " . trim($a[0]) . ') AS INT)';
        });
    }

    /* CURDATE()/NOW() ± INTERVAL n unidad */
    $sql = preg_replace_callback('/\b(CURDATE|NOW)\(\s*\)\s*([+-])\s*INTERVAL\s+(\?|\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b/',
        function ($m) use ($unitPlural) {
            $unit = $unitPlural[strtoupper($m[4])];
            $sign = $m[2];
            $mod  = ($m[3] === '?') ? "('$sign' || ? || ' $unit')" : "'$sign" . (int)$m[3] . " $unit'";
            $fn   = ($m[1] === 'CURDATE') ? 'date' : 'datetime';
            return $fn . "('now','localtime', $mod)";
        }, $sql);

    /* instante actual */
    $sql = preg_replace('/\bNOW\(\s*\)/', "datetime('now','localtime')", $sql);
    $sql = preg_replace('/\bCURDATE\(\s*\)/', "date('now','localtime')", $sql);
    $sql = preg_replace('/\bCURTIME\(\s*\)/', "time('now','localtime')", $sql);
    $sql = preg_replace('/\bCURRENT_TIMESTAMP\b/', "(datetime('now','localtime'))", $sql);

    /* ---------- misc ---------- */
    $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);
    $sql = sql_fix_division($sql);

    return $sql;
}

/* PDO sobre SQLite que traduce cada sentencia al ejecutarla y registra los
   fallos (sentencia original MySQL + error) en el log. */
class ErpPdo extends PDO {
    private static $conflictCache = [];

    public function prepare(string $query, array $options = []): PDOStatement|false {
        try {
            return parent::prepare($this->xlate($query), $options);
        } catch (PDOException $e) { $this->log_sql($query, $e); throw $e; }
    }

    public function exec(string $statement): int|false {
        if (preg_match('/^\s*(SET|USE)\s/i', $statement)) return 0;   // sesiones MySQL: no aplican
        try {
            return parent::exec($this->xlate($statement));
        } catch (PDOException $e) { $this->log_sql($statement, $e); throw $e; }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        try {
            $sql = $this->xlate($query);
            return $fetchMode === null ? parent::query($sql) : parent::query($sql, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) { $this->log_sql($query, $e); throw $e; }
    }

    private function xlate($sql) {
        return $this->fix_upsert(mysql_to_sqlite($sql));
    }

    private function log_sql($sql, $e) {
        error_log('[SQL] ' . $e->getMessage() . ' :: ' . str_replace("\n", ' ', (string)$sql));
    }

    /* ON DUPLICATE KEY UPDATE → ON CONFLICT (…) DO UPDATE SET …
       El conflicto se resuelve contra la clave primaria o el índice único
       de la tabla, que es lo que MySQL usaba internamente. */
    private function fix_upsert($sql) {
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') === false) return $sql;
        if (!preg_match('/^\s*INSERT\s+(?:OR\s+IGNORE\s+)?INTO\s+[`"]?(\w+)[`"]?\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE)) return $sql;
        $table = $m[1][0];
        $open  = $m[0][1] + strlen($m[0][0]) - 1;
        $close = strpos($sql, ')', $open);
        if ($close === false) return $sql;
        $cols = array_map(function ($c) { return trim($c, " \t\n\r\0\x0B`\""); },
            explode(',', substr($sql, $open + 1, $close - $open - 1)));
        $marker = stripos($sql, 'ON DUPLICATE KEY UPDATE');
        $head   = rtrim(substr($sql, 0, $marker));
        $assign = trim(substr($sql, $marker + strlen('ON DUPLICATE KEY UPDATE')));
        $assign = preg_replace('/\bVALUES\s*\(\s*[`"]?(\w+)[`"]?\s*\)/i', 'excluded.$1', $assign);
        $target = $this->conflict_target($table, $cols);
        if ($target === '') return $sql;
        return $head . ' ON CONFLICT(' . $target . ') DO UPDATE SET ' . $assign;
    }

    private function conflict_target($table, $cols) {
        if (!array_key_exists($table, self::$conflictCache)) {
            $info = ['pk' => [], 'uniques' => []];
            try {
                foreach ($this->query('PRAGMA table_info(`' . $table . '`)') as $r) {
                    if ((int)$r['pk'] > 0) $info['pk'][(int)$r['pk']] = $r['name'];
                }
                ksort($info['pk']);
                $info['pk'] = array_values($info['pk']);
                foreach ($this->query('PRAGMA index_list(`' . $table . '`)') as $ix) {
                    if (!(int)$ix['unique'] || strpos((string)$ix['name'], 'sqlite_autoindex') === 0) continue;
                    $icols = [];
                    foreach ($this->query('PRAGMA index_info(`' . $ix['name'] . '`)') as $c) {
                        if ($c['name'] !== null) $icols[] = $c['name'];
                    }
                    if ($icols) $info['uniques'][] = $icols;
                }
            } catch (Throwable $e) { $info = ['pk' => [], 'uniques' => []]; }
            self::$conflictCache[$table] = $info;
        }
        $info = self::$conflictCache[$table];
        $lower = array_map('strtolower', $cols);
        if ($info['pk'] && !array_diff(array_map('strtolower', $info['pk']), $lower)) return implode(', ', $info['pk']);
        foreach ($info['uniques'] as $u) {
            if (!array_diff(array_map('strtolower', $u), $lower)) return implode(', ', $u);
        }
        return $cols[0] ?? '';
    }
}

/* MySQL actualaba updated_at con ON UPDATE CURRENT_TIMESTAMP; SQLite no.
   Aquí se crean (una vez) disparadores para las tablas que tengan esa columna. */
function sqlite_install_triggers($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $has = false;
            foreach ($pdo->query('PRAGMA table_info(`' . $t . '`)') as $c) { if ($c['name'] === 'updated_at') { $has = true; break; } }
            if (!$has) continue;
            $pdo->exec('CREATE TRIGGER IF NOT EXISTS "trg_' . $t . '_updated_at" AFTER UPDATE ON "' . $t . '"'
                . ' FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at'
                . ' BEGIN UPDATE "' . $t . '" SET updated_at = (datetime(\'now\',\'localtime\')) WHERE rowid = NEW.rowid; END');
        }
    } catch (Exception $e) { /* no bloquea */ }
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_SQLITE);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        try {
            $pdo = new ErpPdo('sqlite:' . DB_SQLITE, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 8000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            sqlite_install_triggers($pdo);
        } catch (PDOException $e) {
            /* En dev se muestra el detalle para poder depurar. En prod NO:
               se registra en el log y al visitante le sale un mensaje genérico. */
            if (defined('APP_ENV') && APP_ENV === 'dev') {
                die('Error de conexión con la base de datos. (' . htmlspecialchars($e->getMessage()) . ')');
            }
            error_log('DB connect: ' . $e->getMessage());
            die('No se puede conectar con la base de datos en este momento. Vuelve a intentarlo en un momento.');
        }
    }
    return $pdo;
}

/* ===========================================================
   Auto-reparación del esquema: crea lo nuevo (role, client_types,
   tipo_id) si falta. Así el panel funciona aunque no se haya
   ejecutado migrate.php. Es rápido e idempotente.
   =========================================================== */
function ensure_schema() {
    $pdo = db();
    try {
        $q = function($sql) use ($pdo){ return (int)$pdo->query($sql)->fetchColumn(); };
        $hasTable = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_types'");
        $hasRole  = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='role'");
        $hasEmail = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='email'");
        $hasTipo  = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='tipo_id'");
        $hasInf   = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='informes_json'");
        $hasSvc   = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='servicios_json'");
        $hasSet   = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings'");
        $hasLook  = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='looker_url'");
        $hasTok   = $hasSet ? $q("SELECT COUNT(*) FROM settings WHERE clave='api_token'") : 0;
        $hasTasks = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks'");
        $hasEsCli = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_lists' AND COLUMN_NAME='es_cliente'") : 0;
        $hasComments = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_comments'");
        $hasCreds = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_credentials'");
        $hasCrm = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_leads'");
        $hasCrmTypes = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_types'");
        $hasChk = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_checklist'");
        $hasAtt = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_attachments'");
        $hasReact = $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_comment_reactions'");
        $hasCliOrden = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='orden'");
        $hasCmChk = $hasComments ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_comments' AND COLUMN_NAME='checklist_json'") : 0;
        $hasListTipo = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_lists' AND COLUMN_NAME='tipo'") : 0;
        $hasFini = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='fecha_inicio'") : 0;
        $hasEtq  = $hasTasks ? $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='etiquetas'") : 0;
        $hasFact = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='fact_nombre'");
        $hasActivo = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='activo'");
        $hasFactTel = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='fact_tel'");
        $hasLoginEmail = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='login_email'");
        $hasCliEmail = $q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='email'");
        if ($hasTable && $hasRole && $hasEmail && $hasTipo && $hasInf && $hasSvc && $hasSet && $hasLook && $hasTok && $hasTasks && $hasEsCli && $hasComments && $hasCreds && $hasCrm && $hasCrmTypes && $hasChk && $hasAtt && $hasReact && $hasCliOrden && $hasCmChk && $hasListTipo && $hasFini && $hasEtq && $hasFact && $hasActivo && $hasFactTel && $hasLoginEmail && $hasCliEmail) return;   // ya está todo

        if ($hasTasks && !$hasEsCli) {
            $pdo->exec("ALTER TABLE task_lists ADD COLUMN es_cliente TINYINT NOT NULL DEFAULT 0");
        }
        if ($hasTasks && !$hasListTipo) { $pdo->exec("ALTER TABLE task_lists ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'tareas'"); }
        if (!$hasCrm) {
            $pdo->exec("CREATE TABLE crm_leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                empresa VARCHAR(200) NOT NULL,
                segmento VARCHAR(10) NOT NULL DEFAULT 'b2b',
                estado VARCHAR(20) NOT NULL DEFAULT 'lead_nuevo',
                localizacion VARCHAR(120) NULL,
                origen VARCHAR(60) NULL,
                seguimiento VARCHAR(200) NULL,
                paso_flujo VARCHAR(80) NULL,
                proxima_accion VARCHAR(255) NULL,
                fecha_prox DATE NULL,
                intentos INT NOT NULL DEFAULT 0,
                telefono VARCHAR(60) NULL,
                correo VARCHAR(160) NULL,
                valor DECIMAL(10,2) NULL,
                notas MEDIUMTEXT NULL,
                responsable_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (segmento), INDEX (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasCrmTypes) {
            $pdo->exec("CREATE TABLE crm_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(40) NOT NULL UNIQUE,
                nombre VARCHAR(80) NOT NULL,
                orden INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $it=$pdo->prepare("INSERT INTO crm_types (slug,nombre,orden) VALUES (?,?,?)");
            $it->execute(['b2b','B2B · Agencias',0]);
            $it->execute(['b2c','B2C · Clientes',1]);
        }
        if (!$hasChk) {
            $pdo->exec("CREATE TABLE task_checklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                texto VARCHAR(500) NOT NULL,
                done TINYINT NOT NULL DEFAULT 0,
                responsable_id INT NULL,
                orden INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasAtt) {
            $pdo->exec("CREATE TABLE task_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                comment_id INT NULL,
                filename VARCHAR(255) NOT NULL,
                orig_name VARCHAR(255) NULL,
                mime VARCHAR(120) NULL,
                admin_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (task_id), INDEX (comment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasReact) {
            $pdo->exec("CREATE TABLE task_comment_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                comment_id INT NOT NULL,
                admin_id INT NOT NULL,
                emoji VARCHAR(16) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq (comment_id, admin_id, emoji),
                INDEX (comment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasCliOrden) { $pdo->exec("ALTER TABLE clients ADD COLUMN orden INT NOT NULL DEFAULT 0"); }
        /* Datos de facturación guardados en el cliente (auto-relleno de facturas).
           login_email = el correo de Google con el que ese cliente puede entrar al
           portal pulsando «Entrar con Google» (lo pone el equipo en la ficha). */
        foreach (['fact_nombre'=>"VARCHAR(200) NOT NULL DEFAULT ''",'fact_nif'=>"VARCHAR(40) NOT NULL DEFAULT ''",'fact_dir'=>"VARCHAR(300) NOT NULL DEFAULT ''",'fact_email'=>"VARCHAR(160) NOT NULL DEFAULT ''",'fact_tel'=>"VARCHAR(40) NOT NULL DEFAULT ''",'activo'=>"TINYINT NOT NULL DEFAULT 1",'login_email'=>"VARCHAR(160) NOT NULL DEFAULT ''",'email'=>"VARCHAR(160) NOT NULL DEFAULT ''"] as $col=>$def) {
            if (!$q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='$col'")) $pdo->exec("ALTER TABLE clients ADD COLUMN $col $def");
        }
        /* Bóveda: flag «visible para el cliente» (para enseñar en el portal solo lo marcado). */
        if ($hasCreds && !$q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_credentials' AND COLUMN_NAME='visible_cliente'")) { try{ $pdo->exec("ALTER TABLE client_credentials ADD COLUMN visible_cliente TINYINT NOT NULL DEFAULT 0"); }catch(Exception $e){} }
        if ($hasComments && !$hasCmChk) { $pdo->exec("ALTER TABLE task_comments ADD COLUMN checklist_json MEDIUMTEXT NULL"); }
        if ($hasTasks && !$hasFini) { $pdo->exec("ALTER TABLE tasks ADD COLUMN fecha_inicio DATE NULL"); }
        if ($hasTasks && !$hasEtq)  { $pdo->exec("ALTER TABLE tasks ADD COLUMN etiquetas VARCHAR(255) NULL"); }
        if (!$hasComments) {
            $pdo->exec("CREATE TABLE task_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                admin_id INT NULL,
                cuerpo MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasCreds) {
            $pdo->exec("CREATE TABLE client_credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                titulo VARCHAR(160) NOT NULL,
                categoria VARCHAR(30) NOT NULL DEFAULT 'other',
                usuario VARCHAR(255) NULL,
                secreto TEXT NULL,
                url VARCHAR(500) NULL,
                nota VARCHAR(500) NULL,
                visible_cliente TINYINT NOT NULL DEFAULT 0,
                orden INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasTasks) {
            $pdo->exec("CREATE TABLE task_lists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                es_cliente TINYINT NOT NULL DEFAULT 0,
                tipo VARCHAR(20) NOT NULL DEFAULT 'tareas',
                orden INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                list_id INT NOT NULL,
                titulo VARCHAR(255) NOT NULL,
                descripcion MEDIUMTEXT,
                estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
                responsable_id INT NULL,
                prioridad TINYINT NOT NULL DEFAULT 0,
                due_date DATE NULL,
                visible_cliente TINYINT NOT NULL DEFAULT 0,
                titulo_cliente VARCHAR(255) NULL,
                explicacion_cliente MEDIUMTEXT,
                mes VARCHAR(40) NULL,
                fecha_inicio DATE NULL,
                etiquetas VARCHAR(255) NULL,
                orden INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (client_id), INDEX (list_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        /* Compatibilidad: si task_lists existe pero sin 'tipo' (BD migrada antes de que
           el CREATE lo incluyera), añadirlo. */
        if ($q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_lists'")
            && !$q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='task_lists' AND COLUMN_NAME='tipo'")) {
            $pdo->exec("ALTER TABLE task_lists ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'tareas'");
        }

        if (!$hasLook) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN looker_url TEXT NULL");
        }
        if ($hasSet && !$hasTok) {
            $pdo->prepare("INSERT INTO settings (clave, valor) VALUES ('api_token', ?)")->execute([bin2hex(random_bytes(16))]);
        }

        if (!$hasSet) {
            $pdo->exec("CREATE TABLE settings (clave VARCHAR(60) PRIMARY KEY, valor TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ins = $pdo->prepare("INSERT INTO settings (clave, valor) VALUES (?, ?)");
            foreach ([
                'meeting_url' => '',
                'video_id'    => 'J9-aEZ523bA',
                'whatsapp'    => '34600000000',
                'email'       => 'hola@croilab.com',
                'api_token'   => bin2hex(random_bytes(16)),
            ] as $k => $v) { $ins->execute([$k, $v]); }
        }

        if (!$hasInf) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN informes_json MEDIUMTEXT NULL");
        }
        if (!$hasSvc) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN servicios_json MEDIUMTEXT NULL");
        }
        if (!$hasRole) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'editor'");
        }
        if (!$hasEmail) {
            /* Correo de Google del miembro: sirve para «Entrar con Google» (solo entra quien
               tenga aquí su correo; no hay registro abierto). */
            $pdo->exec("ALTER TABLE admins ADD COLUMN email VARCHAR(190) NULL");
        }
        if ($q("SELECT COUNT(*) FROM admins WHERE role='owner'") === 0) {
            $pdo->exec("UPDATE admins SET role='owner' WHERE id = (SELECT MIN(id) FROM admins)");
        }
        if (!$hasTable) {
            $pdo->exec("CREATE TABLE client_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                secciones_json MEDIUMTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$hasTipo) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN tipo_id INT NULL");
        }
        if ($q("SELECT COUNT(*) FROM client_types") === 0) {
            $todas   = ['metricas'=>1,'progreso'=>1,'informes'=>1,'como'=>1,'accesos'=>1,'plan'=>1];
            $soloWeb = ['metricas'=>0,'progreso'=>1,'informes'=>0,'como'=>1,'accesos'=>1,'plan'=>1];
            $ins = $pdo->prepare("INSERT INTO client_types (nombre, secciones_json) VALUES (?, ?)");
            $ins->execute(['SEO completo', json_encode($todas, JSON_UNESCAPED_UNICODE)]);
            $idSEO = (int)$pdo->lastInsertId();
            $ins->execute(['Solo web', json_encode($soloWeb, JSON_UNESCAPED_UNICODE)]);
            $idWeb = (int)$pdo->lastInsertId();
            $ins->execute(['SEM (campañas)', json_encode($todas, JSON_UNESCAPED_UNICODE)]);
            $pdo->prepare("UPDATE clients SET tipo_id=? WHERE tipo_id IS NULL AND conversiones=1")->execute([$idSEO]);
            $pdo->prepare("UPDATE clients SET tipo_id=? WHERE tipo_id IS NULL AND conversiones=0")->execute([$idWeb]);
        }
    } catch (Exception $e) {
        /* si algo falla, no bloquear la app (queda registrado para depurar) */
        error_log('ensure_schema: ' . $e->getMessage());
    }
}

/* Lee un ajuste global (tabla settings). Devuelve $def si no existe. */
function get_setting($k, $def = '') {
    try {
        $st = db()->prepare('SELECT valor FROM settings WHERE clave = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? $def : $v;
    } catch (Exception $e) { return $def; }
}
