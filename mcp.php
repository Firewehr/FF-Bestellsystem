<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ff_mcp_http_error(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ff_mcp_rpc_response($id, $result): void
{
    echo json_encode(
        ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function ff_mcp_rpc_error($id, int $code, string $message): void
{
    echo json_encode(
        ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function ff_mcp_table_exists(mysqli $conn, string $table): bool
{
    $allowed = [
        'allergene',
        'bestandteil_allergene',
        'bestandteile',
        'beilagen',
        'position_rezepturen',
        'position_subcategories',
        'type',
        'mitarbeiter_verpflegung',
        'feste',
    ];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Ungültiger Tabellenname');
    }

    $statement = mysqli_prepare(
        $conn,
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Tabellenstatus konnte nicht gelesen werden');
    }
    mysqli_stmt_bind_param($statement, 's', $table);
    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Tabellenstatus konnte nicht gelesen werden');
    }
    $result = mysqli_stmt_get_result($statement);
    if (!$result) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Tabellenstatus konnte nicht gelesen werden');
    }
    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    mysqli_stmt_close($statement);
    return $exists;
}

function ff_mcp_query_rows(mysqli $conn, string $sql): array
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        throw new RuntimeException('Katalogdaten konnten nicht gelesen werden');
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

function ff_mcp_menu_catalog(mysqli $conn): array
{
    $positions = ff_mcp_query_rows(
        $conn,
        'SELECT rowid, Positionsname, Betrag, type, Kurzbezeichnung, maxBestellbar,
                reihenfolge, color, icon, print_target, selbstkosten, subcategory_id,
                tile_bg, kassa_only, fest_id
         FROM positionen
         ORDER BY type, reihenfolge, Positionsname'
    );
    foreach ($positions as &$position) {
        foreach (['rowid', 'type', 'maxBestellbar', 'reihenfolge', 'print_target', 'subcategory_id', 'kassa_only', 'fest_id'] as $field) {
            $position[$field] = $position[$field] === null ? null : (int)$position[$field];
        }
        foreach (['Betrag', 'selbstkosten'] as $field) {
            $position[$field] = (float)$position[$field];
        }
    }
    unset($position);

    $types = ff_mcp_table_exists($conn, 'type')
        ? ff_mcp_query_rows($conn, 'SELECT rowid, name FROM `type` ORDER BY rowid')
        : [];
    foreach ($types as &$type) {
        $type['rowid'] = (int)$type['rowid'];
    }
    unset($type);

    $ingredients = [];
    $allergensByIngredient = [];
    $hasIngredients = ff_mcp_table_exists($conn, 'bestandteile');
    $hasIngredientAllergens = $hasIngredients
        && ff_mcp_table_exists($conn, 'bestandteil_allergene')
        && ff_mcp_table_exists($conn, 'allergene');

    if ($hasIngredients) {
        $ingredients = ff_mcp_query_rows(
            $conn,
            'SELECT id, name, einheit, kapazitaet, aktiv, sort_order, fest_id
             FROM bestandteile
             ORDER BY aktiv DESC, sort_order, name'
        );
        foreach ($ingredients as &$ingredient) {
            foreach (['id', 'kapazitaet', 'aktiv', 'sort_order', 'fest_id'] as $field) {
                $ingredient[$field] = $ingredient[$field] === null ? null : (int)$ingredient[$field];
            }
            $ingredient['allergene'] = [];
        }
        unset($ingredient);

        if ($hasIngredientAllergens) {
            $allergenRows = ff_mcp_query_rows(
                $conn,
                'SELECT ba.bestandteil_id, a.id, a.name
                 FROM bestandteil_allergene ba
                 INNER JOIN allergene a ON a.id = ba.allergen_id
                 ORDER BY ba.bestandteil_id, a.sort_order, a.name'
            );
            foreach ($allergenRows as $allergen) {
                $allergensByIngredient[(int)$allergen['bestandteil_id']][] = [
                    'id' => (int)$allergen['id'],
                    'name' => (string)$allergen['name'],
                ];
            }
            foreach ($ingredients as &$ingredient) {
                $ingredient['allergene'] = $allergensByIngredient[(int)$ingredient['id']] ?? [];
            }
            unset($ingredient);
        }
    }

    $recipes = [];
    if (ff_mcp_table_exists($conn, 'position_rezepturen')) {
        $ingredientJoin = $hasIngredients
            ? 'LEFT JOIN bestandteile b ON b.id = pr.bestandteil_id'
            : '';
        $ingredientFields = $hasIngredients
            ? 'b.name AS bestandteil_name, b.einheit AS bestandteil_einheit'
            : 'NULL AS bestandteil_name, NULL AS bestandteil_einheit';
        $recipes = ff_mcp_query_rows(
            $conn,
            'SELECT pr.id, pr.position_id, pr.bestandteil_id, pr.menge, pr.optional_enabled,
                    pr.default_selected, pr.option_group, pr.reihenfolge, pr.fest_id, '
                    . $ingredientFields . '
             FROM position_rezepturen pr
             ' . $ingredientJoin . '
             ORDER BY pr.position_id, pr.reihenfolge, pr.id'
        );
        foreach ($recipes as &$recipe) {
            foreach (['id', 'position_id', 'bestandteil_id', 'optional_enabled', 'default_selected', 'reihenfolge', 'fest_id'] as $field) {
                $recipe[$field] = $recipe[$field] === null ? null : (int)$recipe[$field];
            }
            $recipe['menge'] = (string)$recipe['menge'];
        }
        unset($recipe);
    }

    $subcategories = ff_mcp_table_exists($conn, 'position_subcategories')
        ? ff_mcp_query_rows(
            $conn,
            'SELECT id, type, name, sort_order, tile_bg, kassa_only, fest_id
             FROM position_subcategories
             ORDER BY type, sort_order, name'
        )
        : [];
    foreach ($subcategories as &$subcategory) {
        foreach (['id', 'type', 'sort_order', 'kassa_only', 'fest_id'] as $field) {
            $subcategory[$field] = $subcategory[$field] === null ? null : (int)$subcategory[$field];
        }
    }
    unset($subcategory);

    $additions = ff_mcp_table_exists($conn, 'beilagen')
        ? ff_mcp_query_rows(
            $conn,
            'SELECT rowid, name, position, betrag, fest_id
             FROM beilagen
             ORDER BY position, name'
        )
        : [];
    foreach ($additions as &$addition) {
        foreach (['rowid', 'position', 'fest_id'] as $field) {
            $addition[$field] = $addition[$field] === null ? null : (int)$addition[$field];
        }
        $addition['betrag'] = (float)$addition['betrag'];
    }
    unset($addition);

    return [
        'positionen' => $positions,
        'positionstypen' => $types,
        'bestandteile' => $ingredients,
        'rezepturen' => $recipes,
        'unterkategorien' => $subcategories,
        'beilagen' => $additions,
    ];
}

function ff_mcp_parse_datetime($value, bool $endOfDay): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $value, $m)) {
        return $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
    }
    throw new InvalidArgumentException('Ungültiges Datum: ' . $value . ' (erwartet YYYY-MM-DD oder YYYY-MM-DD HH:MM)');
}

function ff_mcp_orders(mysqli $conn, array $args): array
{
    $from = ff_mcp_parse_datetime($args['von'] ?? '', false);
    $to = ff_mcp_parse_datetime($args['bis'] ?? '', true);
    if ($from === null || $to === null) {
        throw new InvalidArgumentException('Die Parameter "von" und "bis" sind erforderlich.');
    }
    if ($from > $to) {
        throw new InvalidArgumentException('"von" liegt nach "bis".');
    }
    $limit = min(1000, max(1, (int)($args['limit'] ?? 200)));
    $offset = max(0, (int)($args['offset'] ?? 0));
    $includeDeleted = !empty($args['include_deleted']);

    $eventTs = "COALESCE(NULLIF(b.timestampBestellung, '0000-00-00 00:00:00'), b.zeitstempel)";
    $where = ["$eventTs >= ?", "$eventTs <= ?", 'b.bestellt = 1'];
    $types = 'ss';
    $params = [$from, $to];
    if (!$includeDeleted) {
        $where[] = 'b.`delete` = 0';
    }
    if (isset($args['position_id']) && (int)$args['position_id'] > 0) {
        $where[] = 'b.position = ?';
        $types .= 'i';
        $params[] = (int)$args['position_id'];
    }
    if (isset($args['tischnummer']) && $args['tischnummer'] !== '') {
        $where[] = 'b.tischnummer = ?';
        $types .= 'i';
        $params[] = (int)$args['tischnummer'];
    }
    $whereSql = implode(' AND ', $where);

    $statement = mysqli_prepare($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(b.betrag), 0) AS s
        FROM bestellungen b WHERE $whereSql");
    if (!$statement) {
        throw new RuntimeException('Bestellungen konnten nicht gelesen werden');
    }
    mysqli_stmt_bind_param($statement, $types, ...$params);
    mysqli_stmt_execute($statement);
    $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: ['c' => 0, 's' => 0];
    mysqli_stmt_close($statement);

    $sql = "SELECT b.rowid, b.fest_id, $eventTs AS bestellzeit, b.order_nr, b.bon_id,
                   b.tischnummer, b.position, p.Positionsname, b.betrag, b.is_gratis,
                   b.kellner, b.kellnerZahlung, b.Zusatzinfo, b.ausgeliefert, b.kueche,
                   b.`delete` AS geloescht, b.rechnung_id, b.sammelrechnung_id,
                   NULLIF(b.timestampAuslieferung, '0000-00-00 00:00:00') AS zeit_auslieferung,
                   NULLIF(b.timestampBezahlung, '0000-00-00 00:00:00') AS zeit_bezahlung
            FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE $whereSql
            ORDER BY $eventTs, b.rowid
            LIMIT ? OFFSET ?";
    $statement = mysqli_prepare($conn, $sql);
    if (!$statement) {
        throw new RuntimeException('Bestellungen konnten nicht gelesen werden');
    }
    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;
    mysqli_stmt_bind_param($statement, $types, ...$params);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        foreach (['rowid', 'fest_id', 'order_nr', 'tischnummer', 'position', 'ausgeliefert', 'kueche', 'geloescht', 'rechnung_id', 'sammelrechnung_id', 'is_gratis'] as $f) {
            $row[$f] = $row[$f] === null ? null : (int)$row[$f];
        }
        $row['betrag'] = (float)$row['betrag'];
        $rows[] = $row;
    }
    mysqli_stmt_close($statement);

    return [
        'von' => $from,
        'bis' => $to,
        'gesamt_anzahl' => (int)$totals['c'],
        'gesamt_betrag' => (float)$totals['s'],
        'limit' => $limit,
        'offset' => $offset,
        'bestellungen' => $rows,
    ];
}

function ff_mcp_update_position(mysqli $conn, array $args): array
{
    $id = (int)($args['position_id'] ?? 0);
    if ($id < 1) {
        throw new InvalidArgumentException('"position_id" ist erforderlich.');
    }
    $hasName = array_key_exists('name', $args);
    $hasPrice = array_key_exists('preis', $args);
    if (!$hasName && !$hasPrice) {
        throw new InvalidArgumentException('Mindestens "name" oder "preis" muss angegeben werden.');
    }

    $name = null;
    if ($hasName) {
        $name = trim((string)$args['name']);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new InvalidArgumentException('"name" muss 1-200 Zeichen lang sein.');
        }
    }
    $price = null;
    if ($hasPrice) {
        $raw = $args['preis'];
        if (is_string($raw)) {
            $raw = str_replace(',', '.', trim($raw));
        }
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100000) {
            throw new InvalidArgumentException('"preis" muss eine Zahl zwischen 0 und 100000 sein.');
        }
        $price = round((float)$raw, 2);
    }

    $select = mysqli_prepare($conn, 'SELECT Positionsname, Betrag FROM positionen WHERE rowid = ? LIMIT 1');
    mysqli_stmt_bind_param($select, 'i', $id);
    mysqli_stmt_execute($select);
    $before = mysqli_fetch_assoc(mysqli_stmt_get_result($select));
    mysqli_stmt_close($select);
    if (!$before) {
        throw new InvalidArgumentException('Position ' . $id . ' nicht gefunden.');
    }

    $sets = [];
    $types = '';
    $params = [];
    if ($name !== null) {
        $sets[] = 'Positionsname = ?';
        $types .= 's';
        $params[] = $name;
    }
    if ($price !== null) {
        $sets[] = 'Betrag = ?';
        $types .= 'd';
        $params[] = $price;
    }
    $types .= 'i';
    $params[] = $id;
    $update = mysqli_prepare($conn, 'UPDATE positionen SET ' . implode(', ', $sets) . ' WHERE rowid = ? LIMIT 1');
    if (!$update) {
        throw new RuntimeException('Update fehlgeschlagen');
    }
    mysqli_stmt_bind_param($update, $types, ...$params);
    if (!mysqli_stmt_execute($update)) {
        mysqli_stmt_close($update);
        throw new RuntimeException('Update fehlgeschlagen');
    }
    mysqli_stmt_close($update);

    error_log(sprintf(
        'MCP update_position id=%d name:"%s"->"%s" preis:%s->%s',
        $id,
        $before['Positionsname'],
        $name ?? $before['Positionsname'],
        $before['Betrag'],
        $price ?? $before['Betrag']
    ));

    return [
        'position_id' => $id,
        'vorher' => ['name' => (string)$before['Positionsname'], 'preis' => (float)$before['Betrag']],
        'nachher' => [
            'name' => $name ?? (string)$before['Positionsname'],
            'preis' => $price ?? (float)$before['Betrag'],
        ],
    ];
}

function ff_mcp_set_recipe(mysqli $conn, array $args): array
{
    $positionId = (int)($args['position_id'] ?? 0);
    if ($positionId < 1) {
        throw new InvalidArgumentException('"position_id" ist erforderlich.');
    }
    if (!isset($args['bestandteile']) || !is_array($args['bestandteile']) || count($args['bestandteile']) > 100) {
        throw new InvalidArgumentException('"bestandteile" muss eine Liste mit höchstens 100 Einträgen sein (leer = Rezeptur löschen).');
    }
    if (!ff_mcp_table_exists($conn, 'position_rezepturen') || !ff_mcp_table_exists($conn, 'bestandteile')) {
        throw new RuntimeException('Rezepturtabellen fehlen');
    }

    $check = mysqli_prepare($conn, 'SELECT 1 FROM positionen WHERE rowid = ? LIMIT 1');
    mysqli_stmt_bind_param($check, 'i', $positionId);
    mysqli_stmt_execute($check);
    $found = mysqli_num_rows(mysqli_stmt_get_result($check)) > 0;
    mysqli_stmt_close($check);
    if (!$found) {
        throw new InvalidArgumentException('Position ' . $positionId . ' nicht gefunden.');
    }

    $ingredientCheck = mysqli_prepare($conn, 'SELECT 1 FROM bestandteile WHERE id = ? LIMIT 1');
    $rows = [];
    foreach (array_values($args['bestandteile']) as $index => $entry) {
        if (!is_array($entry)) {
            throw new InvalidArgumentException('Eintrag ' . ($index + 1) . ' ist ungültig.');
        }
        $ingredientId = (int)($entry['bestandteil_id'] ?? 0);
        mysqli_stmt_bind_param($ingredientCheck, 'i', $ingredientId);
        mysqli_stmt_execute($ingredientCheck);
        if ($ingredientId < 1 || mysqli_num_rows(mysqli_stmt_get_result($ingredientCheck)) === 0) {
            throw new InvalidArgumentException('Bestandteil ' . $ingredientId . ' existiert nicht (Eintrag ' . ($index + 1) . ').');
        }
        $amountRaw = $entry['menge'] ?? 1;
        if (is_string($amountRaw)) {
            $amountRaw = str_replace(',', '.', trim($amountRaw));
        }
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > 1000000) {
            throw new InvalidArgumentException('"menge" muss > 0 sein (Eintrag ' . ($index + 1) . ').');
        }
        $optionGroup = trim((string)($entry['option_group'] ?? ''));
        if (mb_strlen($optionGroup) > 64) {
            throw new InvalidArgumentException('"option_group" max. 64 Zeichen (Eintrag ' . ($index + 1) . ').');
        }
        $rows[] = [
            'bestandteil_id' => $ingredientId,
            'menge' => round((float)$amountRaw, 3),
            'optional_enabled' => !empty($entry['optional']) ? 1 : 0,
            'default_selected' => array_key_exists('default_selected', $entry) ? (!empty($entry['default_selected']) ? 1 : 0) : 1,
            'option_group' => $optionGroup,
            'reihenfolge' => $index,
        ];
    }
    mysqli_stmt_close($ingredientCheck);

    mysqli_begin_transaction($conn);
    try {
        $delete = mysqli_prepare($conn, 'DELETE FROM position_rezepturen WHERE position_id = ?');
        mysqli_stmt_bind_param($delete, 'i', $positionId);
        if (!mysqli_stmt_execute($delete)) {
            throw new RuntimeException('delete_failed');
        }
        mysqli_stmt_close($delete);

        if ($rows !== []) {
            $insert = mysqli_prepare(
                $conn,
                'INSERT INTO position_rezepturen (position_id, bestandteil_id, menge, optional_enabled, default_selected, option_group, reihenfolge, fest_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
            );
            foreach ($rows as $row) {
                mysqli_stmt_bind_param(
                    $insert,
                    'iidiisi',
                    $positionId,
                    $row['bestandteil_id'],
                    $row['menge'],
                    $row['optional_enabled'],
                    $row['default_selected'],
                    $row['option_group'],
                    $row['reihenfolge']
                );
                if (!mysqli_stmt_execute($insert)) {
                    throw new RuntimeException('insert_failed');
                }
            }
            mysqli_stmt_close($insert);
        }
        mysqli_commit($conn);
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        throw $exception;
    }

    error_log('MCP set_recipe position_id=' . $positionId . ' zeilen=' . count($rows));
    return ['position_id' => $positionId, 'bestandteile' => $rows];
}

function ff_mcp_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = mysqli_prepare($conn, $sql);
    if (!$statement) {
        throw new RuntimeException('Abfrage konnte nicht vorbereitet werden');
    }
    if ($params !== []) {
        mysqli_stmt_bind_param($statement, $types, ...$params);
    }
    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Abfrage fehlgeschlagen');
    }
    $result = mysqli_stmt_get_result($statement);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($statement);
    return $rows;
}

function ff_mcp_fests(mysqli $conn): array
{
    if (!ff_mcp_table_exists($conn, 'feste')) {
        return ['aktuelles_fest_id' => null, 'feste' => []];
    }
    $rows = ff_mcp_fetch_all(
        $conn,
        'SELECT id, name, code, fest_datum, aktiv, ist_aktuell, payment_mode, created_at FROM feste ORDER BY id DESC'
    );
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['aktiv'] = (int)$row['aktiv'];
        $row['ist_aktuell'] = (int)$row['ist_aktuell'];
    }
    unset($row);

    require_once __DIR__ . '/include/settings.php';
    $current = (int)setting_get($conn, 'current_fest_id', '0');
    if ($current <= 0) {
        foreach ($rows as $row) {
            if ($row['aktiv'] === 1) {
                $current = $row['id'];
                break;
            }
        }
    }
    return ['aktuelles_fest_id' => $current > 0 ? $current : null, 'feste' => $rows];
}

function ff_mcp_stock_status(mysqli $conn): array
{
    $hasMv = ff_mcp_table_exists($conn, 'mitarbeiter_verpflegung');
    $orderCounts = [];
    foreach (ff_mcp_fetch_all($conn, 'SELECT `position` AS pid, COUNT(*) AS c FROM bestellungen WHERE `delete` = 0 GROUP BY `position`') as $row) {
        $orderCounts[(int)$row['pid']] = (int)$row['c'];
    }
    $mvCounts = [];
    if ($hasMv) {
        foreach (ff_mcp_fetch_all($conn, 'SELECT position_id AS pid, COALESCE(SUM(menge), 0) AS c FROM mitarbeiter_verpflegung GROUP BY position_id') as $row) {
            $mvCounts[(int)$row['pid']] = (int)$row['c'];
        }
    }

    $positions = [];
    foreach (ff_mcp_fetch_all($conn, 'SELECT rowid, Positionsname, maxBestellbar FROM positionen WHERE maxBestellbar > 0 ORDER BY type, reihenfolge, Positionsname') as $row) {
        $pid = (int)$row['rowid'];
        $capacity = (int)$row['maxBestellbar'];
        $consumed = ($orderCounts[$pid] ?? 0) + ($mvCounts[$pid] ?? 0);
        $positions[] = [
            'position_id' => $pid,
            'name' => (string)$row['Positionsname'],
            'kapazitaet' => $capacity,
            'verbraucht_gaeste' => $orderCounts[$pid] ?? 0,
            'verbraucht_mitarbeiter' => $mvCounts[$pid] ?? 0,
            'rest' => $capacity - $consumed,
        ];
    }

    $ingredients = [];
    if (ff_mcp_table_exists($conn, 'bestandteile') && ff_mcp_table_exists($conn, 'position_rezepturen')) {
        $consumedByIngredient = [];
        foreach (ff_mcp_fetch_all(
            $conn,
            'SELECT pr.bestandteil_id AS bid, SUM(pr.menge) AS c FROM bestellungen b
             JOIN position_rezepturen pr ON pr.position_id = b.position
             WHERE b.`delete` = 0 GROUP BY pr.bestandteil_id'
        ) as $row) {
            $consumedByIngredient[(int)$row['bid']] = (float)$row['c'];
        }
        if ($hasMv) {
            foreach (ff_mcp_fetch_all(
                $conn,
                'SELECT pr.bestandteil_id AS bid, SUM(v.menge * pr.menge) AS c FROM mitarbeiter_verpflegung v
                 JOIN position_rezepturen pr ON pr.position_id = v.position_id GROUP BY pr.bestandteil_id'
            ) as $row) {
                $consumedByIngredient[(int)$row['bid']] = ($consumedByIngredient[(int)$row['bid']] ?? 0.0) + (float)$row['c'];
            }
        }
        foreach (ff_mcp_fetch_all($conn, 'SELECT id, name, einheit, kapazitaet FROM bestandteile WHERE aktiv = 1 AND kapazitaet > 0 ORDER BY sort_order, name') as $row) {
            $bid = (int)$row['id'];
            $used = round($consumedByIngredient[$bid] ?? 0.0, 3);
            $ingredients[] = [
                'bestandteil_id' => $bid,
                'name' => (string)$row['name'],
                'einheit' => (string)$row['einheit'],
                'kapazitaet' => (int)$row['kapazitaet'],
                'verbraucht' => $used,
                'rest' => round((int)$row['kapazitaet'] - $used, 3),
            ];
        }
    }

    return ['positionen' => $positions, 'bestandteile' => $ingredients];
}

function ff_mcp_sales_summary(mysqli $conn, array $args): array
{
    $from = ff_mcp_parse_datetime($args['von'] ?? '', false);
    $to = ff_mcp_parse_datetime($args['bis'] ?? '', true);
    if ($from === null || $to === null) {
        throw new InvalidArgumentException('Die Parameter "von" und "bis" sind erforderlich.');
    }
    if ($from > $to) {
        throw new InvalidArgumentException('"von" liegt nach "bis".');
    }
    $groupBy = (string)($args['gruppierung'] ?? 'position');
    $eventTs = "COALESCE(NULLIF(b.timestampBestellung, '0000-00-00 00:00:00'), b.zeitstempel)";
    $hasTypes = ff_mcp_table_exists($conn, 'type');

    $groups = [
        'position' => ['p.rowid AS schluessel, p.Positionsname AS bezeichnung', 'p.rowid, p.Positionsname', 'p.Positionsname'],
        'typ' => [$hasTypes ? 'p.type AS schluessel, t.name AS bezeichnung' : 'p.type AS schluessel, p.type AS bezeichnung', $hasTypes ? 'p.type, t.name' : 'p.type', 'p.type'],
        'tag' => ["DATE($eventTs) AS schluessel, DATE($eventTs) AS bezeichnung", "DATE($eventTs)", "DATE($eventTs)"],
        'stunde' => ["DATE_FORMAT($eventTs, '%Y-%m-%d %H:00') AS schluessel, DATE_FORMAT($eventTs, '%Y-%m-%d %H:00') AS bezeichnung", "DATE_FORMAT($eventTs, '%Y-%m-%d %H:00')", "DATE_FORMAT($eventTs, '%Y-%m-%d %H:00')"],
        'kellner' => ['b.kellner AS schluessel, b.kellner AS bezeichnung', 'b.kellner', 'b.kellner'],
    ];
    if (!isset($groups[$groupBy])) {
        throw new InvalidArgumentException('"gruppierung" muss position, typ, tag, stunde oder kellner sein.');
    }
    [$select, $groupSql, $orderSql] = $groups[$groupBy];

    $amount = 'COALESCE(NULLIF(b.betrag, 0), p.Betrag)';
    $where = ["$eventTs >= ?", "$eventTs <= ?", 'b.bestellt = 1', 'b.`delete` = 0'];
    $types = 'ss';
    $params = [$from, $to];
    if (isset($args['fest_id']) && (int)$args['fest_id'] > 0) {
        $where[] = 'b.fest_id = ?';
        $types .= 'i';
        $params[] = (int)$args['fest_id'];
    }

    $join = 'LEFT JOIN positionen p ON p.rowid = b.position'
        . ($groupBy === 'typ' && $hasTypes ? ' LEFT JOIN `type` t ON t.rowid = p.type' : '');
    $sql = "SELECT $select,
                   COUNT(*) AS anzahl,
                   SUM(CASE WHEN b.is_gratis = 1 THEN 1 ELSE 0 END) AS anzahl_gratis,
                   COALESCE(SUM(CASE WHEN b.is_gratis = 1 THEN 0 ELSE $amount END), 0) AS umsatz
            FROM bestellungen b $join
            WHERE " . implode(' AND ', $where) . "
            GROUP BY $groupSql
            ORDER BY $orderSql
            LIMIT 2000";
    $rows = ff_mcp_fetch_all($conn, $sql, $types, $params);

    $totalCount = 0;
    $totalSales = 0.0;
    foreach ($rows as &$row) {
        $row['anzahl'] = (int)$row['anzahl'];
        $row['anzahl_gratis'] = (int)$row['anzahl_gratis'];
        $row['umsatz'] = round((float)$row['umsatz'], 2);
        $totalCount += $row['anzahl'];
        $totalSales += $row['umsatz'];
    }
    unset($row);

    return [
        'von' => $from,
        'bis' => $to,
        'gruppierung' => $groupBy,
        'gesamt_anzahl' => $totalCount,
        'gesamt_umsatz' => round($totalSales, 2),
        'gruppen' => $rows,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ff_mcp_http_error(405, 'method_not_allowed');
}

$environmentToken = getenv('FF_MCP_TOKEN');
require_once __DIR__ . '/include/settings.php';
$storedTokenHash = (string)setting_get($conn, 'mcp_token_hash', '');
if ($storedTokenHash === '' && ($environmentToken === false || $environmentToken === '')) {
    ff_mcp_http_error(503, 'mcp_not_configured');
}

$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string)$name, 'Authorization') === 0) {
            $authorization = (string)$value;
            break;
        }
    }
}
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    ff_mcp_http_error(401, $authorization === '' ? 'unauthorized_no_header' : 'unauthorized_bad_format');
}
$providedToken = trim($matches[1]);
$isAuthorized = $storedTokenHash !== ''
    ? password_verify($providedToken, $storedTokenHash)
    : hash_equals((string)$environmentToken, $providedToken);
if (!$isAuthorized) {
    ff_mcp_http_error(401, 'unauthorized_token_mismatch');
}

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (stripos($contentType, 'application/json') !== 0) {
    ff_mcp_http_error(415, 'unsupported_media_type');
}

$body = file_get_contents('php://input', false, null, 0, 1048577);
if ($body === false || strlen($body) > 1048576) {
    ff_mcp_http_error(413, 'request_too_large');
}
$request = json_decode($body, true);
if (!is_array($request) || ($request['jsonrpc'] ?? null) !== '2.0' || !isset($request['method'])) {
    http_response_code(400);
    ff_mcp_rpc_error(null, -32600, 'Invalid Request');
}

if (!array_key_exists('id', $request)) {
    if (($request['method'] ?? '') === 'notifications/initialized') {
        http_response_code(202);
        exit;
    }
    http_response_code(202);
    exit;
}

$id = $request['id'];
if (!is_string($id) && !is_int($id) && $id !== null) {
    http_response_code(400);
    ff_mcp_rpc_error(null, -32600, 'Invalid Request');
}

switch ((string)$request['method']) {
    case 'initialize':
        $requestedVersion = (string)($request['params']['protocolVersion'] ?? '');
        $supportedVersions = ['2024-11-05', '2025-03-26', '2025-06-18'];
        ff_mcp_rpc_response($id, [
            'protocolVersion' => in_array($requestedVersion, $supportedVersions, true)
                ? $requestedVersion
                : '2025-06-18',
            'capabilities' => ['tools' => (object)[]],
            'serverInfo' => ['name' => 'bestellsystem', 'version' => '1.0.0'],
        ]);

    case 'ping':
        ff_mcp_rpc_response($id, (object)[]);

    case 'tools/list':
        ff_mcp_rpc_response($id, [
            'tools' => [[
                'name' => 'get_menu_catalog',
                'description' => 'Liest alle Positionen, Positionstypen, Rezepturbestandteile samt Allergenen, Rezepturen, Unterkategorien und Beilagen. Es werden keine Daten verändert.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'get_orders',
                'description' => 'Liest Bestellzeilen (eine Zeile je bestellter Position) in einem Zeitraum. Es werden keine Daten verändert.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'von' => ['type' => 'string', 'description' => 'Beginn, YYYY-MM-DD oder YYYY-MM-DD HH:MM'],
                        'bis' => ['type' => 'string', 'description' => 'Ende (inklusive), YYYY-MM-DD oder YYYY-MM-DD HH:MM'],
                        'position_id' => ['type' => 'integer', 'description' => 'Optional: nur diese Position'],
                        'tischnummer' => ['type' => 'integer', 'description' => 'Optional: nur dieser Tisch'],
                        'include_deleted' => ['type' => 'boolean', 'description' => 'Stornierte/gelöschte Zeilen einbeziehen (Standard: nein)'],
                        'limit' => ['type' => 'integer', 'description' => 'Max. Zeilen (1-1000, Standard 200)'],
                        'offset' => ['type' => 'integer', 'description' => 'Offset für Paginierung'],
                    ],
                    'required' => ['von', 'bis'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'update_position',
                'description' => 'ÄNDERT eine Position der Speisekarte: Name und/oder Preis. Bereits erfasste Bestellungen behalten ihren damaligen Betrag.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'position_id' => ['type' => 'integer', 'description' => 'rowid der Position (siehe get_menu_catalog)'],
                        'name' => ['type' => 'string', 'description' => 'Neuer Name'],
                        'preis' => ['type' => 'number', 'description' => 'Neuer Preis in Euro'],
                    ],
                    'required' => ['position_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'set_recipe',
                'description' => 'ÄNDERT die Rezeptur einer Position: ersetzt ALLE Bestandteile der Position durch die übergebene Liste (leere Liste löscht die Rezeptur). Vorher mit get_menu_catalog die aktuelle Rezeptur und gültige bestandteil_id lesen.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'position_id' => ['type' => 'integer', 'description' => 'rowid der Position'],
                        'bestandteile' => [
                            'type' => 'array',
                            'description' => 'Vollständige neue Rezeptur in Reihenfolge',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'bestandteil_id' => ['type' => 'integer'],
                                    'menge' => ['type' => 'number', 'description' => 'Menge je Portion (Standard 1)'],
                                    'optional' => ['type' => 'boolean', 'description' => 'Vom Gast abwählbar/wählbar'],
                                    'default_selected' => ['type' => 'boolean', 'description' => 'Bei optionalen vorausgewählt (Standard true)'],
                                    'option_group' => ['type' => 'string', 'description' => 'Optionsgruppe (max. 64 Zeichen)'],
                                ],
                                'required' => ['bestandteil_id'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['position_id', 'bestandteile'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'get_sales_summary',
                'description' => 'Liest aggregierte Verkaufszahlen (Anzahl, Umsatz ohne Gratis-Posten) eines Zeitraums, gruppiert. Es werden keine Daten verändert.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'von' => ['type' => 'string', 'description' => 'Beginn, YYYY-MM-DD oder YYYY-MM-DD HH:MM'],
                        'bis' => ['type' => 'string', 'description' => 'Ende (inklusive)'],
                        'gruppierung' => ['type' => 'string', 'enum' => ['position', 'typ', 'tag', 'stunde', 'kellner'], 'description' => 'Standard: position'],
                        'fest_id' => ['type' => 'integer', 'description' => 'Optional: nur dieses Fest (siehe get_fests)'],
                    ],
                    'required' => ['von', 'bis'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'get_stock_status',
                'description' => 'Liest Kapazität, Verbrauch und Rest der mengenbegrenzten Positionen und Bestandteile. Es werden keine Daten verändert.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            ], [
                'name' => 'get_fests',
                'description' => 'Liest alle Feste und das aktuelle Fest (für fest_id-Filter). Es werden keine Daten verändert.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            ]],
        ]);

    case 'tools/call':
        $toolName = (string)($request['params']['name'] ?? '');
        if (!in_array($toolName, ['get_menu_catalog', 'get_orders', 'update_position', 'set_recipe', 'get_sales_summary', 'get_stock_status', 'get_fests'], true)) {
            ff_mcp_rpc_error($id, -32602, 'Unknown tool');
        }
        $arguments = $request['params']['arguments'] ?? [];
        if (!is_array($arguments)) {
            ff_mcp_rpc_error($id, -32602, 'Invalid arguments');
        }
        try {
            require_once __DIR__ . '/include/db.php';
            $data = match ($toolName) {
                'get_orders' => ff_mcp_orders($conn, $arguments),
                'update_position' => ff_mcp_update_position($conn, $arguments),
                'set_recipe' => ff_mcp_set_recipe($conn, $arguments),
                'get_sales_summary' => ff_mcp_sales_summary($conn, $arguments),
                'get_stock_status' => ff_mcp_stock_status($conn),
                'get_fests' => ff_mcp_fests($conn),
                default => ff_mcp_menu_catalog($conn),
            };
            ff_mcp_rpc_response($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]],
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (InvalidArgumentException $exception) {
            ff_mcp_rpc_response($id, [
                'content' => [['type' => 'text', 'text' => $exception->getMessage()]],
                'isError' => true,
            ]);
        } catch (Throwable $exception) {
            error_log('MCP tool ' . $toolName . ' failed: ' . $exception->getMessage());
            ff_mcp_rpc_response($id, [
                'content' => [['type' => 'text', 'text' => 'Daten konnten nicht gelesen werden.']],
                'isError' => true,
            ]);
        }

    default:
        ff_mcp_rpc_error($id, -32601, 'Method not found');
}
