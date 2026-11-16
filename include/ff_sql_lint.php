<?php
/**
 * Statische Prüfung: Werte, die direkt ins SQL eingesetzt werden (statt als Platzhalter).
 *
 * Gemeldet wird eine PHP-Anweisung, deren SQL-Text an einer Werte-Position eine Variable enthält:
 *   - nach einem Vergleich:   "… WHERE id = " . $id      "… id = {$id}"
 *   - nach LIKE / LIMIT / OFFSET
 *   - in Anführungszeichen:   "… name = '$name'"
 *   - in einer VALUES- oder IN-Liste:  "VALUES (" . $a . ", …"   "IN (" . $a . "," . $b . ")"
 *
 * Nicht gemeldet (erlaubt, siehe AGENTS.md):
 *   - SQL-Bausteine, die an Struktur-Positionen eingesetzt werden ("WHERE {$where}", "AND " . $filter)
 *   - Platzhalterlisten ("IN ($in)" aus ff_db_in_ints)
 *   - Tabellen-/Spaltennamen in Backticks ("FROM `{$table}`")
 *   - Konstanten und Funktionsaufrufe (liefern feste SQL-Ausdrücke)
 *
 * Bewusste Ausnahmen markiert man in derselben Anweisung mit dem Kommentar  // sql-lint: ok (Grund)
 */
if (!function_exists('ff_sql_lint_file')) {
    /**
     * @return list<array{line:int, sql:string}>
     */
    function ff_sql_lint_file(string $path): array
    {
        $code = @file_get_contents($path);
        if ($code === false || strpos($code, '$') === false) {
            return [];
        }
        $toks = token_get_all($code);
        $n = count($toks);
        $hits = [];
        $sqlKw = '~\b(SELECT\b.+\bFROM|UPDATE\s+`?\w+`?\s+SET|INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM|WHERE|HAVING)\b'
            . '|^\s*(?:AND|OR)\s+\(?[`\w.]+`?\s.*?(?:=|<>|!=|<|>|\bLIKE\b|\bIN\b)~is'; // auch Bausteine " AND b.x = "
        // Werte-Positionen: Operator/Schlüsselwort direkt vor dem Platzhalter-Ersatz «V»
        $valuePos = '~(?:<>|!=|<=|>=|=|<|>|\bLIKE|\bLIMIT|\bOFFSET|\b(?:VALUES|IN)\s*\((?:[^()]*,)?)\s*«V»(?!\.)|\'%?«V»%?\'~i';
        // Schema-Befehle: Tabellen-/Spaltennamen dürfen nicht als Parameter übergeben werden (AGENTS.md)
        $schemaCmd = '~\b(?:SHOW\s+(?:FULL\s+)?(?:COLUMNS|INDEX|TABLES|CREATE|DATABASES|TABLE\s+STATUS)|ALTER\s+TABLE|CREATE\s+(?:TABLE|INDEX)|DROP\s+(?:TABLE|INDEX)|TRUNCATE)\b~i';
        // Namenskonvention für SQL-Bausteine ohne Werte: Platzhalterlisten ($in, $ph, …List) und Ausdrücke (…Expr, …Sql, …Cond)
        $fragName = '~^\$(?:ph|in|marks|placeholders|in[A-Z_]\w*|\w*(?:Ph|Placeholders|Marks|List|In|Expr|Sql|SQL|Cond|Filter|Where|Clause|Join|Scope))$~';

        $render = '';
        $hasSql = false;
        $ok = false;
        $startLine = 0;
        $prev = '';
        $curLine = 0;
        $finish = static function () use (&$render, &$hasSql, &$ok, &$startLine, &$hits, &$curLine, $valuePos, $schemaCmd): void {
            if ($hasSql && !$ok && strpos($render, '«V»') !== false && !preg_match($schemaCmd, $render) && preg_match($valuePos, $render)) {
                $hits[] = ['line' => $startLine, 'end' => $curLine, 'sql' => trim(preg_replace('~\s+~', ' ', $render))];
            }
            $render = '';
            $hasSql = false;
            $ok = false;
            $startLine = 0;
        };
        // überspringt ab $i einen balancierten Block ( … ) oder [ … ], gibt Index nach dem Block zurück
        $skipBalanced = static function (int $i) use ($toks, $n): int {
            $open = $toks[$i];
            $close = $open === '(' ? ')' : ']';
            $depth = 0;
            for (; $i < $n; $i++) {
                $t = $toks[$i];
                if ($t === $open || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) && $open === '{')) {
                    $depth++;
                } elseif ($t === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }
            return $n;
        };
        // überspringt Zugriffe nach einer Variablen: [..], ->prop, ::X (Aufruf-Argumente werden weiter geprüft)
        $skipAccess = static function (int $i) use ($toks, $n, $skipBalanced): int {
            while ($i < $n) {
                $t = $toks[$i];
                if ($t === '[') {
                    $i = $skipBalanced($i);
                } elseif (is_array($t) && in_array($t[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                    || (defined('T_NULLSAFE_OBJECT_OPERATOR') && is_array($t) && $t[0] === T_NULLSAFE_OBJECT_OPERATOR)) {
                    $i += 2;
                } else {
                    break;
                }
            }
            return $i;
        };

        $i = 0;
        while ($i < $n) {
            $t = $toks[$i];
            $id = is_array($t) ? $t[0] : null;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                if (stripos($t[1], 'sql-lint: ok') !== false) {
                    $last = end($hits);
                    if ($render === '' && $last !== false && $last['end'] === $t[2]) {
                        array_pop($hits); // Markierung am Zeilenende der vorigen Anweisung
                    } else {
                        $ok = true;
                    }
                }
                $i++;
                continue;
            }
            if (is_array($t)) {
                $curLine = $t[2];
                if ($startLine === 0 && $id !== T_WHITESPACE) {
                    $startLine = $t[2];
                }
            }
            if ($id === T_INLINE_HTML || $id === T_OPEN_TAG || $id === T_CLOSE_TAG || $t === ';' || $t === '{' || $t === '}') {
                $finish();
                $prev = '';
                $i++;
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $s = substr($t[1], 1, -1);
                $render .= $s;
                if (preg_match($sqlKw, $s)) {
                    $hasSql = true;
                }
                $prev = 'value';
                $i++;
                continue;
            }
            if ($t === '"' || $id === T_START_HEREDOC) {
                // Interpolierter String: Variablen werden zu «V»
                $end = $t === '"' ? '"' : T_END_HEREDOC;
                $i++;
                $lit = '';
                while ($i < $n) {
                    $u = $toks[$i];
                    if ($u === $end || (is_array($u) && $u[0] === $end)) {
                        break;
                    }
                    if (is_array($u) && $u[0] === T_ENCAPSED_AND_WHITESPACE) {
                        $lit .= $u[1];
                        $render .= $u[1];
                        $i++;
                    } elseif (is_array($u) && in_array($u[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                        $depth = 1;
                        $i++;
                        $firstVar = '';
                        while ($i < $n && $depth > 0) {
                            $w = $toks[$i];
                            if ($firstVar === '' && is_array($w) && $w[0] === T_VARIABLE) {
                                $firstVar = $w[1];
                            }
                            if ($w === '{' || (is_array($w) && in_array($w[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                                $depth++;
                            } elseif ($w === '}') {
                                $depth--;
                            }
                            $i++;
                        }
                        $render .= preg_match($fragName, $firstVar) ? '«F»' : '«V»';
                    } elseif (is_array($u) && $u[0] === T_VARIABLE) {
                        $render .= preg_match($fragName, $u[1]) ? '«F»' : '«V»';
                        $i++;
                        // "$a[0]" / "$a->b" im String
                        while ($i < $n && ($toks[$i] === '[' || (is_array($toks[$i]) && $toks[$i][0] === T_OBJECT_OPERATOR))) {
                            if ($toks[$i] === '[') {
                                while ($i < $n && $toks[$i] !== ']') {
                                    $i++;
                                }
                                $i++;
                            } else {
                                $i += 2;
                            }
                        }
                    } else {
                        $i++;
                    }
                }
                if (preg_match($sqlKw, $lit)) {
                    $hasSql = true;
                }
                $prev = 'value';
                $i++;
                continue;
            }
            $prevIsConcat = $prev === '.';
            if (is_array($t) && in_array($id, [T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST], true)) {
                // (int) $x: Wert, falls angehängt; das Folgende gehört zum Cast
                $castConcat = $prevIsConcat;
                $i++;
                while ($i < $n && is_array($toks[$i]) && $toks[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                if ($i < $n && $toks[$i] === '(') {
                    $i = $skipBalanced($i);
                } elseif ($i < $n && is_array($toks[$i]) && $toks[$i][0] === T_VARIABLE) {
                    $i = $skipAccess($i + 1);
                } else {
                    $i++;
                }
                if ($castConcat) {
                    $render .= '«V»';
                }
                $prev = 'value';
                continue;
            }
            if ($id === T_VARIABLE) {
                if ($prevIsConcat) {
                    $render .= preg_match($fragName, $t[1]) ? '«F»' : '«V»';
                }
                $i = $skipAccess($i + 1);
                $prev = 'value';
                continue;
            }
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $j = $i + 1;
                while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $n && $toks[$j] === '(') {
                    $fn = strtolower(ltrim($t[1], '\\'));
                    $isValue = in_array($fn, ['intval', 'floatval', 'strval', 'trim', 'addslashes', 'mysqli_real_escape_string', 'implode', 'join', 'date', 'sprintf'], true);
                    if ($prevIsConcat) {
                        // angehängter Aufruf: Wert (intval …) oder SQL-Ausdruck (ff_…_sql)
                        $after = $skipBalanced($j);
                        if ($isValue && in_array($fn, ['implode', 'join'], true)) {
                            // implode(',', array_fill(0, n, '?')) bzw. Spaltenlisten in Backticks
                            $args = '';
                            for ($k = $j; $k < $after; $k++) {
                                $args .= is_array($toks[$k]) ? $toks[$k][1] : $toks[$k];
                            }
                            if (strpos($args, "'?'") !== false || strpos($args, '"?"') !== false || preg_match($fragName, (string) (preg_match('~\$\w+~', $args, $mm) ? $mm[0] : ''))) {
                                $isValue = false;
                            }
                        }
                        $render .= $isValue ? '«V»' : '«F»';
                        $i = $after;
                        $prev = 'value';
                        continue;
                    }
                    // sonst (z. B. ff_db_query( … )): Argumente normal weiter prüfen
                    $i = $j + 1;
                    $prev = '(';
                    continue;
                }
                if ($prevIsConcat) {
                    $render .= '«K»'; // Konstante
                }
                $prev = 'value';
                $i++;
                continue;
            }
            if ($t === '[') {
                // Arrays außerhalb von Strings (Parameterlisten) gehören nicht zum SQL-Text
                $i = $skipBalanced($i);
                $prev = 'value';
                continue;
            }
            if (!(is_array($t) && $id === T_WHITESPACE)) {
                $prev = is_array($t) ? (string) $id : $t;
            }
            $i++;
        }
        $finish();
        return $hits;
    }

    /**
     * @return list<string> "datei:zeile  sql"
     */
    function ff_sql_lint_project(string $root, array $skipDirs = []): array
    {
        $skipDirs = $skipDirs ?: ['.git', '.agents', 'documentation', 'archiv', 'backups', 'uploads', 'Client-Print-Skripte', 'esp8266_grillhuhn_client', 'node_modules', 'assets'];
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $f) use ($skipDirs): bool {
                    return !$f->isDir() || !in_array($f->getFilename(), $skipDirs, true);
                }
            )
        );
        foreach ($it as $f) {
            if (strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            foreach (ff_sql_lint_file($f->getPathname()) as $h) {
                $out[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $h['line'] . '  ' . mb_substr($h['sql'], 0, 160);
            }
        }
        sort($out);
        return $out;
    }
}
