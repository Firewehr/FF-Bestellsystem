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
        'positionen',
        'bestellungen',
        'print_targets',
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

    $printTargets = ff_mcp_table_exists($conn, 'print_targets')
        ? ff_mcp_query_rows(
            $conn,
            'SELECT print_target, name, active, sort_order
             FROM print_targets
             ORDER BY sort_order, print_target'
        )
        : [];
    foreach ($printTargets as &$pt) {
        $pt['print_target'] = (int)$pt['print_target'];
        $pt['active'] = (int)$pt['active'];
        $pt['sort_order'] = (int)$pt['sort_order'];
    }
    unset($pt);

    return [
        'positionen' => $positions,
        'positionstypen' => $types,
        'bestandteile' => $ingredients,
        'rezepturen' => $recipes,
        'unterkategorien' => $subcategories,
        'beilagen' => $additions,
        'print_targets' => $printTargets,
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

function ff_mcp_print_targets(mysqli $conn): array
{
    $targets = ff_mcp_table_exists($conn, 'print_targets')
        ? ff_mcp_query_rows(
            $conn,
            'SELECT print_target, name, active, sort_order
             FROM print_targets
             ORDER BY sort_order, print_target'
        )
        : [];
    foreach ($targets as &$target) {
        $target['print_target'] = (int)$target['print_target'];
        $target['active'] = (int)$target['active'];
        $target['sort_order'] = (int)$target['sort_order'];
    }
    unset($target);

    return [
        'print_targets' => $targets,
    ];
}

function ff_mcp_create_unterkategorie(mysqli $conn, array $args): array
{
    $name = trim((string)($args['name'] ?? ''));
    if ($name === '' || (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 128)) {
        throw new InvalidArgumentException('"name" ist erforderlich und darf maximal 128 Zeichen lang sein.');
    }

    $rawType = $args['type'] ?? null;
    if (is_string($rawType)) {
        $lower = strtolower(trim($rawType));
        if (in_array($lower, ['speise', 'speisen', 'food', 'essen', '1'], true)) {
            $type = 1;
        } elseif (in_array($lower, ['getraenk', 'getränk', 'getraenke', 'getränke', 'drink', 'drinks', '2'], true)) {
            $type = 2;
        } else {
            $type = (int)$rawType;
        }
    } else {
        $type = (int)$rawType;
    }
    if ($type !== 1 && $type !== 2) {
        throw new InvalidArgumentException('"type" muss 1 (Speise) oder 2 (Getränk) sein.');
    }

    $sortOrder = isset($args['sort_order']) ? (int)$args['sort_order'] : 0;

    $tileBg = null;
    if (isset($args['tile_bg']) && trim((string)$args['tile_bg']) !== '') {
        $tileBgRaw = trim((string)$args['tile_bg']);
        if (function_exists('ff_sanitize_category_tile_bg')) {
            $tileBg = ff_sanitize_category_tile_bg($tileBgRaw);
        } else {
            if ($tileBgRaw[0] !== '#') {
                $tileBgRaw = '#' . $tileBgRaw;
            }
            $tileBg = preg_match('/^#[0-9A-Fa-f]{6}$/', $tileBgRaw) ? strtoupper($tileBgRaw) : null;
        }
    }

    $kassaOnly = !empty($args['kassa_only']) ? 1 : 0;
    $festId = array_key_exists('fest_id', $args) && $args['fest_id'] !== null ? (int)$args['fest_id'] : null;

    $hasFestCol = false;
    $cFest = @mysqli_query($conn, "SHOW COLUMNS FROM `position_subcategories` LIKE 'fest_id'");
    if ($cFest && mysqli_num_rows($cFest) > 0) {
        $hasFestCol = true;
    }
    if ($cFest) {
        @mysqli_free_result($cFest);
    }

    if ($hasFestCol && $festId !== null) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO position_subcategories (type, name, sort_order, tile_bg, kassa_only, fest_id) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Vorbereitung fehlgeschlagen: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'isissi', $type, $name, $sortOrder, $tileBg, $kassaOnly, $festId);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO position_subcategories (type, name, sort_order, tile_bg, kassa_only) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Vorbereitung fehlgeschlagen: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'isisi', $type, $name, $sortOrder, $tileBg, $kassaOnly);
    }

    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Unterkategorie konnte nicht angelegt werden: ' . $err);
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if ($hasFestCol && $festId === null && function_exists('ff_fest_scope_attach_last')) {
        ff_fest_scope_attach_last($conn, 'position_subcategories', $newId);
    }

    error_log(sprintf('MCP create_unterkategorie id=%d name="%s" type=%d', $newId, $name, $type));

    return [
        'id' => $newId,
        'type' => $type,
        'name' => $name,
        'sort_order' => $sortOrder,
        'tile_bg' => $tileBg,
        'kassa_only' => $kassaOnly,
        'fest_id' => $festId,
    ];
}

function ff_mcp_get_unterkategorien(mysqli $conn): array
{
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

    return [
        'unterkategorien' => $subcategories,
    ];
}

function ff_mcp_update_unterkategorie(mysqli $conn, array $args): array
{
    $id = (int)($args['subcategory_id'] ?? $args['id'] ?? 0);
    if ($id < 1) {
        throw new InvalidArgumentException('"subcategory_id" ist erforderlich und muss > 0 sein.');
    }

    $hasName = array_key_exists('name', $args);
    $hasTileBg = array_key_exists('tile_bg', $args) || array_key_exists('kachelfarbe', $args);
    $hasSort = array_key_exists('sort_order', $args);
    $hasKassa = array_key_exists('kassa_only', $args);

    if (!$hasName && !$hasTileBg && !$hasSort && !$hasKassa) {
        throw new InvalidArgumentException('Mindestens eines der Felder "name", "tile_bg", "sort_order" oder "kassa_only" muss angegeben werden.');
    }

    $stmt = mysqli_prepare($conn, 'SELECT id, type, name, sort_order, tile_bg, COALESCE(kassa_only, 0) AS kassa_only FROM position_subcategories WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Datenbankfehler');
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $before = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$before) {
        throw new InvalidArgumentException('Unterkategorie ' . $id . ' nicht gefunden.');
    }

    $sets = [];
    $types = '';
    $params = [];

    $name = null;
    if ($hasName) {
        $name = trim((string)$args['name']);
        if ($name === '' || (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 128)) {
            throw new InvalidArgumentException('"name" muss 1-128 Zeichen lang sein.');
        }
        $sets[] = 'name = ?';
        $types .= 's';
        $params[] = $name;
    }

    $tileBg = null;
    $updateTileBg = false;
    if ($hasTileBg) {
        $updateTileBg = true;
        $rawTile = $args['tile_bg'] ?? $args['kachelfarbe'] ?? null;
        if ($rawTile === null || trim((string)$rawTile) === '' || trim((string)$rawTile) === 'default') {
            $tileBg = null;
            $sets[] = 'tile_bg = NULL';
        } else {
            $clean = trim((string)$rawTile);
            if (function_exists('ff_sanitize_category_tile_bg')) {
                $san = ff_sanitize_category_tile_bg($clean);
            } else {
                $h = strtoupper($clean);
                if ($h[0] !== '#') {
                    $h = '#' . $h;
                }
                $san = preg_match('/^#[0-9A-F]{6}$/', $h) ? $h : null;
            }
            if ($san === null) {
                throw new InvalidArgumentException('"tile_bg" muss ein gültiger Hex-Farbcode sein (z. B. "#E8F5E9"). Knalliges Warnrot/Orange ist nicht zulässig.');
            }
            $tileBg = $san;
            $sets[] = 'tile_bg = ?';
            $types .= 's';
            $params[] = $tileBg;
        }
    }

    $sortOrder = null;
    if ($hasSort) {
        $sortOrder = (int)$args['sort_order'];
        $sets[] = 'sort_order = ?';
        $types .= 'i';
        $params[] = $sortOrder;
    }

    $kassaOnly = null;
    if ($hasKassa) {
        $kassaOnly = !empty($args['kassa_only']) ? 1 : 0;
        $sets[] = 'kassa_only = ?';
        $types .= 'i';
        $params[] = $kassaOnly;
    }

    $types .= 'i';
    $params[] = $id;
    $uStmt = mysqli_prepare($conn, 'UPDATE position_subcategories SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1');
    if (!$uStmt) {
        throw new RuntimeException('Update der Unterkategorie fehlgeschlagen');
    }
    mysqli_stmt_bind_param($uStmt, $types, ...$params);
    if (!mysqli_stmt_execute($uStmt)) {
        $err = mysqli_stmt_error($uStmt);
        mysqli_stmt_close($uStmt);
        throw new RuntimeException('Update fehlgeschlagen: ' . $err);
    }
    mysqli_stmt_close($uStmt);

    error_log(sprintf('MCP update_unterkategorie id=%d name="%s" tile_bg="%s"', $id, $name ?? $before['name'], $updateTileBg ? ($tileBg ?? 'null') : ($before['tile_bg'] ?? 'null')));

    return [
        'subcategory_id' => $id,
        'vorher' => [
            'name' => (string)$before['name'],
            'sort_order' => (int)$before['sort_order'],
            'tile_bg' => $before['tile_bg'] !== null ? (string)$before['tile_bg'] : null,
            'kassa_only' => (int)$before['kassa_only'],
        ],
        'nachher' => [
            'name' => $name ?? (string)$before['name'],
            'sort_order' => $sortOrder ?? (int)$before['sort_order'],
            'tile_bg' => $updateTileBg ? $tileBg : ($before['tile_bg'] !== null ? (string)$before['tile_bg'] : null),
            'kassa_only' => $kassaOnly ?? (int)$before['kassa_only'],
        ],
    ];
}

function ff_mcp_delete_unterkategorie(mysqli $conn, array $args): array
{
    $id = (int)($args['subcategory_id'] ?? $args['id'] ?? 0);
    if ($id < 1) {
        throw new InvalidArgumentException('"subcategory_id" ist erforderlich und muss > 0 sein.');
    }

    $stmt = mysqli_prepare($conn, 'SELECT id, type, name FROM position_subcategories WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Datenbankfehler');
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $sub = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$sub) {
        throw new InvalidArgumentException('Unterkategorie ' . $id . ' nicht gefunden.');
    }

    // Zugeordnete Positionen zählen und aushängen
    $cStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM positionen WHERE subcategory_id = ?');
    $detached = 0;
    if ($cStmt) {
        mysqli_stmt_bind_param($cStmt, 'i', $id);
        mysqli_stmt_execute($cStmt);
        $cRow = mysqli_fetch_assoc(mysqli_stmt_get_result($cStmt));
        mysqli_stmt_close($cStmt);
        $detached = (int)($cRow['c'] ?? 0);
    }

    if ($detached > 0) {
        $uStmt = mysqli_prepare($conn, 'UPDATE positionen SET subcategory_id = NULL WHERE subcategory_id = ?');
        if ($uStmt) {
            mysqli_stmt_bind_param($uStmt, 'i', $id);
            mysqli_stmt_execute($uStmt);
            mysqli_stmt_close($uStmt);
        }
    }

    // Unterkategorie löschen
    $dStmt = mysqli_prepare($conn, 'DELETE FROM position_subcategories WHERE id = ? LIMIT 1');
    if (!$dStmt) {
        throw new RuntimeException('Löschen der Unterkategorie fehlgeschlagen');
    }
    mysqli_stmt_bind_param($dStmt, 'i', $id);
    if (!mysqli_stmt_execute($dStmt)) {
        $err = mysqli_stmt_error($dStmt);
        mysqli_stmt_close($dStmt);
        throw new RuntimeException('Löschen fehlgeschlagen: ' . $err);
    }
    mysqli_stmt_close($dStmt);

    error_log(sprintf('MCP delete_unterkategorie id=%d name="%s" detached=%d', $id, $sub['name'], $detached));

    return [
        'deleted' => true,
        'subcategory_id' => $id,
        'name' => (string)$sub['name'],
        'type' => (int)$sub['type'],
        'positions_detached' => $detached,
        'message' => sprintf(
            'Unterkategorie "%s" (ID %d) erfolgreich gelöscht. %d zugewiesene Position(en) wurden ausgehängt.',
            $sub['name'],
            $id,
            $detached
        ),
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
    $hasPrintTarget = array_key_exists('print_target', $args);
    $hasSubcategory = array_key_exists('subcategory_id', $args);
    $hasKurz = array_key_exists('kurzbezeichnung', $args) || array_key_exists('short_name', $args);
    $hasTileBg = array_key_exists('tile_bg', $args) || array_key_exists('kachelfarbe', $args);

    if (!$hasName && !$hasPrice && !$hasPrintTarget && !$hasSubcategory && !$hasKurz && !$hasTileBg) {
        throw new InvalidArgumentException('Mindestens eines der Felder "name", "preis", "print_target", "subcategory_id", "kurzbezeichnung" oder "tile_bg" muss angegeben werden.');
    }

    $name = null;
    if ($hasName) {
        $name = trim((string)$args['name']);
        if ($name === '' || (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 200)) {
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
    $printTarget = null;
    if ($hasPrintTarget) {
        $rawPt = $args['print_target'];
        if ($rawPt === null || $rawPt === '') {
            $printTarget = 0;
        } elseif (is_numeric($rawPt)) {
            $valPt = (int)$rawPt;
            if ($valPt < 0) {
                throw new InvalidArgumentException('"print_target" muss >= 0 sein.');
            }
            $printTarget = $valPt;
        } else {
            throw new InvalidArgumentException('"print_target" muss eine Ganzzahl sein.');
        }
    }

    $kurzbezeichnung = null;
    $updateKurz = false;
    if ($hasKurz) {
        $updateKurz = true;
        $rawKurz = $args['kurzbezeichnung'] ?? $args['short_name'] ?? '';
        if ($rawKurz === null) {
            $kurzbezeichnung = '';
        } else {
            $kurzbezeichnung = trim((string)$rawKurz);
            if (function_exists('mb_strlen') && mb_strlen($kurzbezeichnung, 'UTF-8') > 30) {
                throw new InvalidArgumentException('"kurzbezeichnung" darf maximal 30 Zeichen lang sein.');
            }
        }
    }

    $tileBg = null;
    $updateTileBg = false;
    if ($hasTileBg) {
        $updateTileBg = true;
        $rawTile = $args['tile_bg'] ?? $args['kachelfarbe'] ?? null;
        if ($rawTile === null || trim((string)$rawTile) === '' || trim((string)$rawTile) === 'default') {
            $tileBg = null; // Auf Standard zurücksetzen
        } else {
            $cleanTile = trim((string)$rawTile);
            if (function_exists('ff_sanitize_category_tile_bg')) {
                $san = ff_sanitize_category_tile_bg($cleanTile);
            } else {
                $h = strtoupper($cleanTile);
                if ($h[0] !== '#') {
                    $h = '#' . $h;
                }
                $san = preg_match('/^#[0-9A-F]{6}$/', $h) ? $h : null;
            }
            if ($san === null) {
                throw new InvalidArgumentException('"tile_bg" muss ein gültiger Hex-Farbcode sein (z. B. "#E8F5E9"). Knalliges Warnrot/Orange ist nicht zulässig.');
            }
            $tileBg = $san;
        }
    }

    $select = mysqli_prepare($conn, 'SELECT Positionsname, Betrag, print_target, subcategory_id, type, Kurzbezeichnung, tile_bg FROM positionen WHERE rowid = ? LIMIT 1');
    mysqli_stmt_bind_param($select, 'i', $id);
    mysqli_stmt_execute($select);
    $before = mysqli_fetch_assoc(mysqli_stmt_get_result($select));
    mysqli_stmt_close($select);
    if (!$before) {
        throw new InvalidArgumentException('Position ' . $id . ' nicht gefunden.');
    }

    $updateSubcategory = false;
    $subcategoryId = null;
    if ($hasSubcategory) {
        $updateSubcategory = true;
        $rawSub = $args['subcategory_id'];
        if ($rawSub === null || $rawSub === '' || $rawSub === 0 || $rawSub === '0') {
            $subcategoryId = null; // Aufheben
        } elseif (is_numeric($rawSub)) {
            $valSub = (int)$rawSub;
            if ($valSub < 0) {
                throw new InvalidArgumentException('"subcategory_id" muss >= 0 oder null sein.');
            }
            if ($valSub > 0) {
                $subStmt = mysqli_prepare($conn, 'SELECT id, type, name FROM position_subcategories WHERE id = ? LIMIT 1');
                if ($subStmt) {
                    mysqli_stmt_bind_param($subStmt, 'i', $valSub);
                    mysqli_stmt_execute($subStmt);
                    $subRow = mysqli_fetch_assoc(mysqli_stmt_get_result($subStmt));
                    mysqli_stmt_close($subStmt);
                    if (!$subRow) {
                        throw new InvalidArgumentException('Unterkategorie ' . $valSub . ' nicht gefunden.');
                    }
                    $posType = (int)($before['type'] ?? 0);
                    $subType = (int)($subRow['type'] ?? 0);
                    if ($posType > 0 && $subType > 0 && $posType !== $subType) {
                        throw new InvalidArgumentException(sprintf(
                            'Unterkategorie "%s" (Typ %d) passt nicht zum Positionstyp %d (1=Speise, 2=Getränk).',
                            $subRow['name'],
                            $subType,
                            $posType
                        ));
                    }
                }
                $subcategoryId = $valSub;
            }
        } else {
            throw new InvalidArgumentException('"subcategory_id" muss eine Ganzzahl oder null sein.');
        }
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
    if ($printTarget !== null) {
        $sets[] = 'print_target = ?';
        $types .= 'i';
        $params[] = $printTarget;
    }
    if ($updateSubcategory) {
        if ($subcategoryId === null) {
            $sets[] = 'subcategory_id = NULL';
        } else {
            $sets[] = 'subcategory_id = ?';
            $types .= 'i';
            $params[] = $subcategoryId;
        }
    }
    if ($updateKurz) {
        $sets[] = 'Kurzbezeichnung = ?';
        $types .= 's';
        $params[] = $kurzbezeichnung;
    }
    if ($updateTileBg) {
        if ($tileBg === null) {
            $sets[] = 'tile_bg = NULL, color = NULL';
        } else {
            $sets[] = 'tile_bg = ?, color = ?';
            $types .= 'ss';
            $params[] = $tileBg;
            $params[] = $tileBg;
        }
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
        'MCP update_position id=%d name:"%s"->"%s" preis:%s->%s pt:%s->%s sub:%s->%s kurz:"%s"->"%s" tile:%s->%s',
        $id,
        $before['Positionsname'],
        $name ?? $before['Positionsname'],
        $before['Betrag'],
        $price ?? $before['Betrag'],
        $before['print_target'] ?? 'null',
        $printTarget !== null ? (string)$printTarget : ($before['print_target'] ?? 'null'),
        $before['subcategory_id'] ?? 'null',
        $updateSubcategory ? ($subcategoryId !== null ? (string)$subcategoryId : 'null') : ($before['subcategory_id'] ?? 'null'),
        $before['Kurzbezeichnung'] ?? '',
        $updateKurz ? $kurzbezeichnung : ($before['Kurzbezeichnung'] ?? ''),
        $before['tile_bg'] ?? 'null',
        $updateTileBg ? ($tileBg ?? 'null') : ($before['tile_bg'] ?? 'null')
    ));

    return [
        'position_id' => $id,
        'vorher' => [
            'name' => (string)$before['Positionsname'],
            'preis' => (float)$before['Betrag'],
            'print_target' => $before['print_target'] === null ? null : (int)$before['print_target'],
            'subcategory_id' => $before['subcategory_id'] === null ? null : (int)$before['subcategory_id'],
            'kurzbezeichnung' => (string)($before['Kurzbezeichnung'] ?? ''),
            'tile_bg' => $before['tile_bg'] !== null ? (string)$before['tile_bg'] : null,
        ],
        'nachher' => [
            'name' => $name ?? (string)$before['Positionsname'],
            'preis' => $price ?? (float)$before['Betrag'],
            'print_target' => $hasPrintTarget ? $printTarget : ($before['print_target'] === null ? null : (int)$before['print_target']),
            'subcategory_id' => $updateSubcategory ? $subcategoryId : ($before['subcategory_id'] === null ? null : (int)$before['subcategory_id']),
            'kurzbezeichnung' => $updateKurz ? $kurzbezeichnung : (string)($before['Kurzbezeichnung'] ?? ''),
            'tile_bg' => $updateTileBg ? $tileBg : ($before['tile_bg'] !== null ? (string)$before['tile_bg'] : null),
        ],
    ];
}

function ff_mcp_set_recipe(mysqli $conn, array $args): array
{
    $positionId = (int)($args['position_id'] ?? 0);
    if ($positionId < 1) {
        throw new InvalidArgumentException('"position_id" ist erforderlich.');
    }
    $bestandteileList = $args['bestandteile'] ?? $args['rezeptur'] ?? null;
    if (!isset($bestandteileList) || !is_array($bestandteileList) || count($bestandteileList) > 100) {
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
    foreach (array_values($bestandteileList) as $index => $entry) {
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

function ff_mcp_normalize_allergens($raw): array
{
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = preg_split('/[,;\r\n]+/', (string)$raw) ?: [];
    }
    $clean = [];
    foreach ($items as $item) {
        $name = trim((string)$item);
        if ($name === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 100, 'UTF-8');
            $key = mb_strtolower($name, 'UTF-8');
        } else {
            $name = substr($name, 0, 100);
            $key = strtolower($name);
        }
        $clean[$key] = $name;
    }
    return array_values($clean);
}

function ff_mcp_save_bestandteil_allergens(mysqli $conn, int $bestandteilId, array $allergens): void
{
    if (!ff_mcp_table_exists($conn, 'bestandteil_allergene') || !ff_mcp_table_exists($conn, 'allergene')) {
        return;
    }
    $delete = mysqli_prepare($conn, 'DELETE FROM bestandteil_allergene WHERE bestandteil_id = ?');
    if ($delete) {
        mysqli_stmt_bind_param($delete, 'i', $bestandteilId);
        mysqli_stmt_execute($delete);
        mysqli_stmt_close($delete);
    }

    foreach ($allergens as $name) {
        $insert = mysqli_prepare($conn, 'INSERT INTO allergene (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        if (!$insert) {
            continue;
        }
        mysqli_stmt_bind_param($insert, 's', $name);
        if (!mysqli_stmt_execute($insert)) {
            mysqli_stmt_close($insert);
            continue;
        }
        $allergenId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insert);

        if ($allergenId > 0) {
            $assign = mysqli_prepare($conn, 'INSERT IGNORE INTO bestandteil_allergene (bestandteil_id, allergen_id) VALUES (?, ?)');
            if ($assign) {
                mysqli_stmt_bind_param($assign, 'ii', $bestandteilId, $allergenId);
                mysqli_stmt_execute($assign);
                mysqli_stmt_close($assign);
            }
        }
    }
}

function ff_mcp_get_bestandteile(mysqli $conn, array $args): array
{
    if (function_exists('ff_rezepturen_ensure_schema')) {
        ff_rezepturen_ensure_schema($conn);
    }
    if (!ff_mcp_table_exists($conn, 'bestandteile')) {
        return ['bestandteile' => [], 'anzahl' => 0];
    }

    $where = [];
    $types = '';
    $params = [];

    $bestandteilId = isset($args['bestandteil_id']) ? (int)$args['bestandteil_id'] : (isset($args['id']) ? (int)$args['id'] : 0);
    if ($bestandteilId > 0) {
        $where[] = 'b.id = ?';
        $types .= 'i';
        $params[] = $bestandteilId;
    }

    if (!empty($args['nur_aktive'])) {
        $where[] = 'b.aktiv = 1';
    }

    if (isset($args['fest_id']) && (int)$args['fest_id'] > 0) {
        $where[] = '(b.fest_id = ? OR b.fest_id IS NULL)';
        $types .= 'i';
        $params[] = (int)$args['fest_id'];
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    $hasAllergens = ff_mcp_table_exists($conn, 'bestandteil_allergene') && ff_mcp_table_exists($conn, 'allergene');
    $allergenSelect = $hasAllergens
        ? ', COALESCE(GROUP_CONCAT(a.name ORDER BY a.sort_order, a.name SEPARATOR ", "), "") AS allergene_str'
        : ', "" AS allergene_str';
    $allergenJoin = $hasAllergens
        ? 'LEFT JOIN bestandteil_allergene ba ON ba.bestandteil_id = b.id LEFT JOIN allergene a ON a.id = ba.allergen_id'
        : '';

    $sql = "SELECT b.id, b.name, b.einheit, b.kapazitaet, b.aktiv, b.sort_order, b.fest_id $allergenSelect
            FROM bestandteile b
            $allergenJoin
            $whereSql
            GROUP BY b.id, b.name, b.einheit, b.kapazitaet, b.aktiv, b.sort_order, b.fest_id
            ORDER BY b.aktiv DESC, b.sort_order, b.name";

    $rows = ff_mcp_fetch_all($conn, $sql, $types, $params);

    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $consumedMap = function_exists('ff_bestandteil_consumed_map') && $ids !== []
        ? ff_bestandteil_consumed_map($conn, $ids)
        : [];

    $result = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $kap = (int)$row['kapazitaet'];
        $used = round((float)($consumedMap[$id] ?? 0.0), 3);
        $allergene = array_values(array_filter(array_map('trim', explode(',', (string)$row['allergene_str']))));

        $result[] = [
            'id' => $id,
            'name' => (string)$row['name'],
            'einheit' => (string)($row['einheit'] ?? 'Stk'),
            'kapazitaet' => $kap,
            'aktiv' => (int)$row['aktiv'],
            'sort_order' => (int)$row['sort_order'],
            'fest_id' => $row['fest_id'] !== null ? (int)$row['fest_id'] : null,
            'verbraucht' => $used,
            'rest' => $kap > 0 ? round($kap - $used, 3) : null,
            'allergene' => $allergene,
        ];
    }

    return ['bestandteile' => $result, 'anzahl' => count($result)];
}

function ff_mcp_create_bestandteil(mysqli $conn, array $args): array
{
    if (function_exists('ff_rezepturen_ensure_schema')) {
        ff_rezepturen_ensure_schema($conn);
    }
    if (!ff_mcp_table_exists($conn, 'bestandteile')) {
        throw new RuntimeException('Tabelle bestandteile existiert nicht.');
    }

    $name = trim((string)($args['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Parameter "name" ist erforderlich.');
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 255, 'UTF-8');
    } else {
        $name = substr($name, 0, 255);
    }

    $einheit = trim((string)($args['einheit'] ?? 'Stk'));
    if ($einheit === '') {
        $einheit = 'Stk';
    }
    if (function_exists('mb_substr')) {
        $einheit = mb_substr($einheit, 0, 30, 'UTF-8');
    } else {
        $einheit = substr($einheit, 0, 30);
    }

    $kapazitaet = isset($args['kapazitaet']) ? (int)$args['kapazitaet'] : -1;
    $aktiv = array_key_exists('aktiv', $args) ? (!empty($args['aktiv']) ? 1 : 0) : 1;
    $sortOrder = isset($args['sort_order']) ? (int)$args['sort_order'] : 0;
    $festId = isset($args['fest_id']) && (int)$args['fest_id'] > 0 ? (int)$args['fest_id'] : null;

    $allergens = isset($args['allergene']) ? ff_mcp_normalize_allergens($args['allergene']) : [];

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO bestandteile (name, einheit, kapazitaet, aktiv, sort_order, fest_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Fehler beim Vorbereiten des Inserts');
        }
        mysqli_stmt_bind_param($stmt, 'ssiiii', $name, $einheit, $kapazitaet, $aktiv, $sortOrder, $festId);
        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Fehler beim Speichern des Bestandteils: ' . $err);
        }
        $newId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($allergens !== []) {
            ff_mcp_save_bestandteil_allergens($conn, $newId, $allergens);
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    error_log("MCP create_bestandteil id=$newId name=\"$name\"");

    return [
        'id' => $newId,
        'name' => $name,
        'einheit' => $einheit,
        'kapazitaet' => $kapazitaet,
        'aktiv' => $aktiv,
        'sort_order' => $sortOrder,
        'fest_id' => $festId,
        'allergene' => $allergens,
    ];
}

function ff_mcp_update_bestandteil(mysqli $conn, array $args): array
{
    if (function_exists('ff_rezepturen_ensure_schema')) {
        ff_rezepturen_ensure_schema($conn);
    }
    $id = (int)($args['bestandteil_id'] ?? $args['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Parameter "bestandteil_id" ist erforderlich.');
    }

    $existing = ff_mcp_fetch_all($conn, 'SELECT id, name, einheit, kapazitaet, aktiv, sort_order, fest_id FROM bestandteile WHERE id = ? LIMIT 1', 'i', [$id]);
    if ($existing === []) {
        throw new InvalidArgumentException("Bestandteil mit ID $id nicht gefunden.");
    }
    $cur = $existing[0];

    $name = array_key_exists('name', $args) ? trim((string)$args['name']) : (string)$cur['name'];
    if ($name === '') {
        throw new InvalidArgumentException('Name darf nicht leer sein.');
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 255, 'UTF-8');
    }

    $einheit = array_key_exists('einheit', $args) ? trim((string)$args['einheit']) : (string)$cur['einheit'];
    if ($einheit === '') {
        $einheit = 'Stk';
    }
    if (function_exists('mb_substr')) {
        $einheit = mb_substr($einheit, 0, 30, 'UTF-8');
    }

    $kapazitaet = array_key_exists('kapazitaet', $args) ? (int)$args['kapazitaet'] : (int)$cur['kapazitaet'];
    $aktiv = array_key_exists('aktiv', $args) ? (!empty($args['aktiv']) ? 1 : 0) : (int)$cur['aktiv'];
    $sortOrder = array_key_exists('sort_order', $args) ? (int)$args['sort_order'] : (int)$cur['sort_order'];
    $festId = array_key_exists('fest_id', $args) ? ($args['fest_id'] !== null && (int)$args['fest_id'] > 0 ? (int)$args['fest_id'] : null) : ($cur['fest_id'] !== null ? (int)$cur['fest_id'] : null);

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, 'UPDATE bestandteile SET name = ?, einheit = ?, kapazitaet = ?, aktiv = ?, sort_order = ?, fest_id = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Fehler beim Vorbereiten des Updates');
        }
        mysqli_stmt_bind_param($stmt, 'ssiiiii', $name, $einheit, $kapazitaet, $aktiv, $sortOrder, $festId, $id);
        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Fehler beim Aktualisieren: ' . $err);
        }
        mysqli_stmt_close($stmt);

        if (array_key_exists('allergene', $args)) {
            $allergens = ff_mcp_normalize_allergens($args['allergene']);
            ff_mcp_save_bestandteil_allergens($conn, $id, $allergens);
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    error_log("MCP update_bestandteil id=$id name=\"$name\"");

    $updated = ff_mcp_get_bestandteile($conn, ['bestandteil_id' => $id]);
    return $updated['bestandteile'][0] ?? ['id' => $id, 'name' => $name];
}

function ff_mcp_delete_bestandteil(mysqli $conn, array $args): array
{
    $id = (int)($args['bestandteil_id'] ?? $args['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Parameter "bestandteil_id" ist erforderlich.');
    }

    $existing = ff_mcp_fetch_all($conn, 'SELECT id, name, aktiv FROM bestandteile WHERE id = ? LIMIT 1', 'i', [$id]);
    if ($existing === []) {
        throw new InvalidArgumentException("Bestandteil mit ID $id nicht gefunden.");
    }

    $deaktivieren = !empty($args['deaktivieren']);
    $force = !empty($args['force']);

    if ($deaktivieren) {
        $stmt = mysqli_prepare($conn, 'UPDATE bestandteile SET aktiv = 0 WHERE id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        error_log("MCP delete_bestandteil (deaktiviert) id=$id");
        return ['bestandteil_id' => $id, 'action' => 'deactivated', 'name' => (string)$existing[0]['name']];
    }

    $usages = ff_mcp_fetch_all($conn, 'SELECT COUNT(*) AS c FROM position_rezepturen WHERE bestandteil_id = ?', 'i', [$id]);
    $usageCount = (int)($usages[0]['c'] ?? 0);

    if ($usageCount > 0 && !$force) {
        throw new InvalidArgumentException(
            "Bestandteil $id wird noch in $usageCount Rezeptur(en) verwendet. Zum Löschen 'force': true angeben oder 'deaktivieren': true verwenden."
        );
    }

    mysqli_begin_transaction($conn);
    try {
        if ($usageCount > 0 && $force && ff_mcp_table_exists($conn, 'position_rezepturen')) {
            $delPr = mysqli_prepare($conn, 'DELETE FROM position_rezepturen WHERE bestandteil_id = ?');
            if ($delPr) {
                mysqli_stmt_bind_param($delPr, 'i', $id);
                mysqli_stmt_execute($delPr);
                mysqli_stmt_close($delPr);
            }
        }
        if (ff_mcp_table_exists($conn, 'bestandteil_allergene')) {
            $delBa = mysqli_prepare($conn, 'DELETE FROM bestandteil_allergene WHERE bestandteil_id = ?');
            if ($delBa) {
                mysqli_stmt_bind_param($delBa, 'i', $id);
                mysqli_stmt_execute($delBa);
                mysqli_stmt_close($delBa);
            }
        }
        $delB = mysqli_prepare($conn, 'DELETE FROM bestandteile WHERE id = ?');
        if ($delB) {
            mysqli_stmt_bind_param($delB, 'i', $id);
            mysqli_stmt_execute($delB);
            mysqli_stmt_close($delB);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    error_log("MCP delete_bestandteil id=$id name=\"{$existing[0]['name']}\"");
    return ['bestandteil_id' => $id, 'action' => 'deleted', 'name' => (string)$existing[0]['name']];
}

function ff_mcp_get_recipe(mysqli $conn, array $args): array
{
    if (function_exists('ff_rezepturen_ensure_schema')) {
        ff_rezepturen_ensure_schema($conn);
    }
    if (!ff_mcp_table_exists($conn, 'position_rezepturen')) {
        return ['rezepturen' => [], 'anzahl' => 0];
    }

    $positionId = isset($args['position_id']) ? (int)$args['position_id'] : 0;
    $festId = isset($args['fest_id']) ? (int)$args['fest_id'] : 0;

    $where = [];
    $types = '';
    $params = [];

    if ($positionId > 0) {
        $where[] = 'pr.position_id = ?';
        $types .= 'i';
        $params[] = $positionId;
    }
    if ($festId > 0) {
        $where[] = '(pr.fest_id = ? OR pr.fest_id IS NULL)';
        $types .= 'i';
        $params[] = $festId;
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    $hasBestandteile = ff_mcp_table_exists($conn, 'bestandteile');
    $bJoin = $hasBestandteile ? 'LEFT JOIN bestandteile b ON b.id = pr.bestandteil_id' : '';
    $bFields = $hasBestandteile
        ? 'b.name AS bestandteil_name, b.einheit AS bestandteil_einheit, b.kapazitaet AS bestandteil_kapazitaet, b.aktiv AS bestandteil_aktiv'
        : 'NULL AS bestandteil_name, NULL AS bestandteil_einheit, NULL AS bestandteil_kapazitaet, NULL AS bestandteil_aktiv';

    $sql = "SELECT pr.id, pr.position_id, p.Positionsname, pr.bestandteil_id, pr.menge,
                   pr.optional_enabled, pr.default_selected, pr.option_group, pr.reihenfolge, pr.fest_id,
                   $bFields
            FROM position_rezepturen pr
            LEFT JOIN positionen p ON p.rowid = pr.position_id
            $bJoin
            $whereSql
            ORDER BY pr.position_id, pr.reihenfolge, pr.id";

    $rows = ff_mcp_fetch_all($conn, $sql, $types, $params);

    $allergensByBid = [];
    if ($hasBestandteile && ff_mcp_table_exists($conn, 'bestandteil_allergene') && ff_mcp_table_exists($conn, 'allergene')) {
        $bids = array_unique(array_filter(array_map(static fn($r) => (int)$r['bestandteil_id'], $rows)));
        if ($bids !== []) {
            $inClause = implode(',', $bids);
            $aRows = ff_mcp_query_rows(
                $conn,
                "SELECT ba.bestandteil_id, a.name
                 FROM bestandteil_allergene ba
                 INNER JOIN allergene a ON a.id = ba.allergen_id
                 WHERE ba.bestandteil_id IN ($inClause)
                 ORDER BY ba.bestandteil_id, a.sort_order, a.name"
            );
            foreach ($aRows as $ar) {
                $allergensByBid[(int)$ar['bestandteil_id']][] = (string)$ar['name'];
            }
        }
    }

    $recipes = [];
    foreach ($rows as $row) {
        $bid = (int)$row['bestandteil_id'];
        $recipes[] = [
            'id' => (int)$row['id'],
            'position_id' => (int)$row['position_id'],
            'Positionsname' => (string)($row['Positionsname'] ?? 'Position ' . $row['position_id']),
            'bestandteil_id' => $bid,
            'bestandteil_name' => (string)($row['bestandteil_name'] ?? 'Unbekannt'),
            'bestandteil_einheit' => (string)($row['bestandteil_einheit'] ?? 'Stk'),
            'bestandteil_kapazitaet' => $row['bestandteil_kapazitaet'] !== null ? (int)$row['bestandteil_kapazitaet'] : null,
            'bestandteil_aktiv' => $row['bestandteil_aktiv'] !== null ? (int)$row['bestandteil_aktiv'] : null,
            'menge' => (float)$row['menge'],
            'optional_enabled' => (int)$row['optional_enabled'],
            'default_selected' => (int)$row['default_selected'],
            'option_group' => (string)($row['option_group'] ?? ''),
            'reihenfolge' => (int)$row['reihenfolge'],
            'fest_id' => $row['fest_id'] !== null ? (int)$row['fest_id'] : null,
            'allergene' => $allergensByBid[$bid] ?? [],
        ];
    }

    return [
        'position_id' => $positionId > 0 ? $positionId : null,
        'rezepturen' => $recipes,
        'anzahl' => count($recipes),
    ];
}

function ff_mcp_add_recipe_item(mysqli $conn, array $args): array
{
    if (function_exists('ff_rezepturen_ensure_schema')) {
        ff_rezepturen_ensure_schema($conn);
    }
    $positionId = (int)($args['position_id'] ?? 0);
    $bestandteilId = (int)($args['bestandteil_id'] ?? 0);
    if ($positionId <= 0 || $bestandteilId <= 0) {
        throw new InvalidArgumentException('"position_id" und "bestandteil_id" sind erforderlich.');
    }

    $posCheck = ff_mcp_fetch_all($conn, 'SELECT Positionsname FROM positionen WHERE rowid = ? LIMIT 1', 'i', [$positionId]);
    if ($posCheck === []) {
        throw new InvalidArgumentException("Position $positionId nicht gefunden.");
    }
    $bCheck = ff_mcp_fetch_all($conn, 'SELECT name, einheit FROM bestandteile WHERE id = ? LIMIT 1', 'i', [$bestandteilId]);
    if ($bCheck === []) {
        throw new InvalidArgumentException("Bestandteil $bestandteilId nicht gefunden.");
    }

    $amountRaw = $args['menge'] ?? 1.0;
    if (is_string($amountRaw)) {
        $amountRaw = str_replace(',', '.', trim($amountRaw));
    }
    $menge = (float)$amountRaw;
    if ($menge <= 0) {
        throw new InvalidArgumentException('Menge muss > 0 sein.');
    }

    $optional = !empty($args['optional']) ? 1 : 0;
    $defaultSelected = array_key_exists('default_selected', $args) ? (!empty($args['default_selected']) ? 1 : 0) : 1;
    $optionGroup = trim((string)($args['option_group'] ?? ''));
    if (function_exists('mb_substr')) {
        $optionGroup = mb_substr($optionGroup, 0, 64, 'UTF-8');
    }

    if (isset($args['reihenfolge'])) {
        $reihenfolge = (int)$args['reihenfolge'];
    } else {
        $maxOrder = ff_mcp_fetch_all($conn, 'SELECT COALESCE(MAX(reihenfolge), -1) AS m FROM position_rezepturen WHERE position_id = ?', 'i', [$positionId]);
        $reihenfolge = ((int)($maxOrder[0]['m'] ?? -1)) + 1;
    }

    $existing = ff_mcp_fetch_all(
        $conn,
        'SELECT id FROM position_rezepturen WHERE position_id = ? AND bestandteil_id = ? AND option_group = ? LIMIT 1',
        'iis',
        [$positionId, $bestandteilId, $optionGroup]
    );

    if ($existing !== []) {
        $id = (int)$existing[0]['id'];
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE position_rezepturen SET menge = ?, optional_enabled = ?, default_selected = ?, reihenfolge = ? WHERE id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'diiii', $menge, $optional, $defaultSelected, $reihenfolge, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO position_rezepturen (position_id, bestandteil_id, menge, optional_enabled, default_selected, option_group, reihenfolge, fest_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        mysqli_stmt_bind_param($stmt, 'iidiisi', $positionId, $bestandteilId, $menge, $optional, $defaultSelected, $optionGroup, $reihenfolge);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    error_log("MCP add_recipe_item position_id=$positionId bestandteil_id=$bestandteilId menge=$menge");

    return ff_mcp_get_recipe($conn, ['position_id' => $positionId]);
}

function ff_mcp_remove_recipe_item(mysqli $conn, array $args): array
{
    $positionId = (int)($args['position_id'] ?? 0);
    $bestandteilId = (int)($args['bestandteil_id'] ?? 0);
    if ($positionId <= 0 || $bestandteilId <= 0) {
        throw new InvalidArgumentException('"position_id" und "bestandteil_id" sind erforderlich.');
    }

    if (isset($args['option_group'])) {
        $optionGroup = trim((string)$args['option_group']);
        $stmt = mysqli_prepare($conn, 'DELETE FROM position_rezepturen WHERE position_id = ? AND bestandteil_id = ? AND option_group = ?');
        mysqli_stmt_bind_param($stmt, 'iis', $positionId, $bestandteilId, $optionGroup);
    } else {
        $stmt = mysqli_prepare($conn, 'DELETE FROM position_rezepturen WHERE position_id = ? AND bestandteil_id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $positionId, $bestandteilId);
    }

    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $affected = 0;
    }

    error_log("MCP remove_recipe_item position_id=$positionId bestandteil_id=$bestandteilId (entfernt: $affected)");

    return ff_mcp_get_recipe($conn, ['position_id' => $positionId]);
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

$body = file_get_contents(php_sapi_name() === 'cli' ? 'php://stdin' : 'php://input', false, null, 0, 1048577);
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
                'description' => 'ÄNDERT eine Position der Speisekarte: Name, Preis, Druckziel (print_target), Unterkategorie (subcategory_id), Kurzbezeichnung (kurzbezeichnung) und/oder Kachelfarbe (tile_bg). Bereits erfasste Bestellungen behalten ihren damaligen Betrag.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'position_id' => ['type' => 'integer', 'description' => 'rowid der Position (siehe get_menu_catalog)'],
                        'name' => ['type' => 'string', 'description' => 'Neuer Name der Position'],
                        'preis' => ['type' => 'number', 'description' => 'Neuer Preis in Euro'],
                        'print_target' => ['type' => 'integer', 'description' => 'ID des Druckziels (z. B. 11 = Küche, 12 = Schank / Bon, siehe get_print_targets oder get_menu_catalog)'],
                        'subcategory_id' => ['type' => 'integer', 'description' => 'ID der Unterkategorie (aus position_subcategories / get_unterkategorien; 0 oder null hebt die Zuordnung auf)'],
                        'kurzbezeichnung' => ['type' => 'string', 'description' => 'Kurzbezeichnung der Position für Bons und Kompaktansichten (max. 30 Zeichen; leer = leeren)'],
                        'tile_bg' => ['type' => 'string', 'description' => 'Kachel-Hintergrundfarbe (#RRGGBB Hex-Code; leer oder null = Standard wiederherstellen)'],
                    ],
                    'required' => ['position_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'create_unterkategorie',
                'description' => 'LEGT EINE NEUE UNTERKATEGORIE (Kachelbereich für Speisen oder Getränke) in position_subcategories an.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'integer', 'description' => 'Kategorie: 1 = Speisen, 2 = Getränke', 'enum' => [1, 2]],
                        'name' => ['type' => 'string', 'description' => 'Name der Unterkategorie (z. B. "Burger", "Alkoholfrei", "Bier")'],
                        'sort_order' => ['type' => 'integer', 'description' => 'Sortierreihenfolge (Standard: 0)'],
                        'tile_bg' => ['type' => 'string', 'description' => 'Optional: Kachel-Hintergrundfarbe (#RRGGBB Hex-Code)'],
                        'kassa_only' => ['type' => 'boolean', 'description' => 'Optional: nur an der Kasse / Direktverkauf anzeigen (Standard: false)'],
                        'fest_id' => ['type' => 'integer', 'description' => 'Optional: Fest-ID für fest-spezifisches Scoping'],
                    ],
                    'required' => ['type', 'name'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'update_unterkategorie',
                'description' => 'ÄNDERT eine bestehende Unterkategorie (Kachelbereich): Name, Kachelfarbe (tile_bg), Sortierung (sort_order) und/oder Kassa-Only-Status.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'subcategory_id' => ['type' => 'integer', 'description' => 'ID der Unterkategorie (siehe get_unterkategorien)'],
                        'name' => ['type' => 'string', 'description' => 'Neuer Name der Unterkategorie'],
                        'tile_bg' => ['type' => 'string', 'description' => 'Kachel-Hintergrundfarbe (#RRGGBB Hex-Code; leer oder null = Standard wiederherstellen)'],
                        'sort_order' => ['type' => 'integer', 'description' => 'Sortierreihenfolge (Ganzzahl)'],
                        'kassa_only' => ['type' => 'boolean', 'description' => 'Nur an der Kasse / Direktverkauf anzeigen'],
                    ],
                    'required' => ['subcategory_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'delete_unterkategorie',
                'description' => 'LÖSCHT eine Unterkategorie aus position_subcategories. Zugewiesene Positionen bleiben erhalten und werden auf "keine Unterkategorie" (subcategory_id = NULL) zurückgesetzt.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'subcategory_id' => ['type' => 'integer', 'description' => 'ID der zu löschenden Unterkategorie (siehe get_unterkategorien)'],
                    ],
                    'required' => ['subcategory_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'get_unterkategorien',
                'description' => 'Liest alle Unterkategorien (Kachelbereiche) mit ID, Typ (1=Speise, 2=Getränk), Name, Sortierung, Farbe und Kassa-Only-Status. Es werden keine Daten verändert.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            ], [
                'name' => 'get_print_targets',
                'description' => 'Liest alle konfigurierten Druckziele (z. B. 11 = Küche, 12 = Schank / Bon) mit ID, Name, Aktiv-Status und Sortierung. Es werden keine Daten verändert.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            ], [
                'name' => 'get_bestandteile',
                'description' => 'Liest alle oder gefilterte Bestandteile (Zutaten/Komponenten) inklusive Einheit, Kapazität, aktuellem Verbrauch, verbleibendem Restbestand, Aktiv-Status, Sortierung und Allergenen. Es werden keine Daten verändert.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'bestandteil_id' => ['type' => 'integer', 'description' => 'Optional: nur diesen Bestandteil lesen'],
                        'nur_aktive' => ['type' => 'boolean', 'description' => 'Optional: nur aktive Bestandteile anzeigen (Standard false)'],
                        'fest_id' => ['type' => 'integer', 'description' => 'Optional: Filter nach Fest-ID'],
                    ],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'create_bestandteil',
                'description' => 'ERSTELLT einen neuen Bestandteil (Zutat/Komponente für Rezepturen). Kann Name, Einheit (Standard "Stk"), Kapazität (-1 für unbegrenzt), Sortierung, optional fest_id und Allergene enthalten.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Name des Bestandteils (z.B. "Semmel", "Käse", "Sauce")'],
                        'einheit' => ['type' => 'string', 'description' => 'Mengeneinheit (Standard "Stk", z.B. "kg", "g", "l", "ml", "Portion")'],
                        'kapazitaet' => ['type' => 'integer', 'description' => 'Max. Vorrat/Kapazität (-1 für unbegrenzt, Standard -1)'],
                        'aktiv' => ['type' => 'boolean', 'description' => 'Aktiv für neue Bestellungen (Standard true)'],
                        'sort_order' => ['type' => 'integer', 'description' => 'Sortierreihenfolge (Standard 0)'],
                        'allergene' => [
                            'description' => 'Liste von Allergenen (z.B. ["Gluten", "Milch"] oder "Gluten, Milch")',
                            'oneOf' => [
                                ['type' => 'array', 'items' => ['type' => 'string']],
                                ['type' => 'string'],
                            ],
                        ],
                        'fest_id' => ['type' => 'integer', 'description' => 'Optional: Zuordnung zu einem Fest'],
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'update_bestandteil',
                'description' => 'ÄNDERT die Eigenschaften eines bestehenden Bestandteils: Name, Einheit, Kapazität (-1 für unbegrenzt), Aktiv-Status, Sortierung und/oder Allergene.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'bestandteil_id' => ['type' => 'integer', 'description' => 'ID des zu ändernden Bestandteils (siehe get_bestandteile)'],
                        'name' => ['type' => 'string', 'description' => 'Neuer Name'],
                        'einheit' => ['type' => 'string', 'description' => 'Neue Mengeneinheit'],
                        'kapazitaet' => ['type' => 'integer', 'description' => 'Neue Kapazität (-1 = unbegrenzt)'],
                        'aktiv' => ['type' => 'boolean', 'description' => 'Aktiv-Status (true/false)'],
                        'sort_order' => ['type' => 'integer', 'description' => 'Sortierreihenfolge'],
                        'allergene' => [
                            'description' => 'Neue Allergene (ersetzt bisherige Allergene dieses Bestandteils)',
                            'oneOf' => [
                                ['type' => 'array', 'items' => ['type' => 'string']],
                                ['type' => 'string'],
                            ],
                        ],
                        'fest_id' => ['type' => 'integer', 'description' => 'Fest-Zuordnung'],
                    ],
                    'required' => ['bestandteil_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'delete_bestandteil',
                'description' => 'LÖSCHT oder deaktiviert einen Bestandteil. Falls der Bestandteil noch in Rezepturen verwendet wird, muss "force": true angegeben werden (entfernt ihn aus den Rezepturen) oder "deaktivieren": true (setzt aktiv = 0).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'bestandteil_id' => ['type' => 'integer', 'description' => 'ID des Bestandteils'],
                        'deaktivieren' => ['type' => 'boolean', 'description' => 'Wenn true, wird der Bestandteil nur deaktiviert (aktiv = 0) statt gelöscht'],
                        'force' => ['type' => 'boolean', 'description' => 'Wenn true, wird der Bestandteil gelöscht und aus allen Rezepturen entfernt'],
                    ],
                    'required' => ['bestandteil_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'get_recipe',
                'description' => 'Liest die Rezeptur(en) von einer oder allen Positionen: enthält alle Zutaten mit Mengen, Einheiten, Allergenen, Optional-Status, Default-Auswahl und Optionsgruppen. Es werden keine Daten verändert.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'position_id' => ['type' => 'integer', 'description' => 'Optional: nur die Rezeptur dieser Position (siehe get_menu_catalog)'],
                        'fest_id' => ['type' => 'integer', 'description' => 'Optional: Filter nach Fest-ID'],
                    ],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'set_recipe',
                'description' => 'ÄNDERT die Rezeptur einer Position: ersetzt ALLE Bestandteile der Position durch die übergebene Liste (leere Liste löscht die Rezeptur). Vorher mit get_bestandteile oder get_menu_catalog gültige bestandteil_id lesen.',
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
                'name' => 'add_recipe_item',
                'description' => 'FÜGT einen Bestandteil zur Rezeptur einer Position hinzu (oder aktualisiert Menge/Optionen, falls bereits vorhanden).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'position_id' => ['type' => 'integer', 'description' => 'rowid der Position'],
                        'bestandteil_id' => ['type' => 'integer', 'description' => 'ID des hinzuzufügenden Bestandteils'],
                        'menge' => ['type' => 'number', 'description' => 'Menge je Portion (Standard 1.0)'],
                        'optional' => ['type' => 'boolean', 'description' => 'Vom Gast abwählbar/wählbar (Standard false)'],
                        'default_selected' => ['type' => 'boolean', 'description' => 'Bei optionalen vorausgewählt (Standard true)'],
                        'option_group' => ['type' => 'string', 'description' => 'Optionsgruppe (z.B. "Brot", "Sauce")'],
                        'reihenfolge' => ['type' => 'integer', 'description' => 'Reihenfolge innerhalb der Rezeptur'],
                    ],
                    'required' => ['position_id', 'bestandteil_id'],
                    'additionalProperties' => false,
                ],
            ], [
                'name' => 'remove_recipe_item',
                'description' => 'ENTFERNT einen Bestandteil aus der Rezeptur einer Position.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'position_id' => ['type' => 'integer', 'description' => 'rowid der Position'],
                        'bestandteil_id' => ['type' => 'integer', 'description' => 'ID des zu entfernenden Bestandteils'],
                        'option_group' => ['type' => 'string', 'description' => 'Optional: nur aus dieser Optionsgruppe entfernen'],
                    ],
                    'required' => ['position_id', 'bestandteil_id'],
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
        $toolAliases = [
            'get_ingredients' => 'get_bestandteile',
            'create_ingredient' => 'create_bestandteil',
            'add_bestandteil' => 'create_bestandteil',
            'update_ingredient' => 'update_bestandteil',
            'delete_ingredient' => 'delete_bestandteil',
            'get_recipes' => 'get_recipe',
            'get_rezeptur' => 'get_recipe',
            'set_rezeptur' => 'set_recipe',
            'add_recipe_component' => 'add_recipe_item',
            'remove_recipe_component' => 'remove_recipe_item',
            'get_druckziele' => 'get_print_targets',
            'create_subcategory' => 'create_unterkategorie',
            'add_unterkategorie' => 'create_unterkategorie',
            'get_subcategories' => 'get_unterkategorien',
            'update_subcategory' => 'update_unterkategorie',
            'delete_subcategory' => 'delete_unterkategorie',
            'remove_unterkategorie' => 'delete_unterkategorie',
        ];
        $canonicalToolName = $toolAliases[$toolName] ?? $toolName;

        $allowedTools = [
            'get_menu_catalog',
            'get_print_targets',
            'get_unterkategorien',
            'create_unterkategorie',
            'update_unterkategorie',
            'delete_unterkategorie',
            'get_orders',
            'update_position',
            'get_bestandteile',
            'create_bestandteil',
            'update_bestandteil',
            'delete_bestandteil',
            'get_recipe',
            'set_recipe',
            'add_recipe_item',
            'remove_recipe_item',
            'get_sales_summary',
            'get_stock_status',
            'get_fests',
        ];
        if (!in_array($canonicalToolName, $allowedTools, true)) {
            ff_mcp_rpc_error($id, -32602, 'Unknown tool: ' . $toolName);
        }
        $arguments = $request['params']['arguments'] ?? [];
        if (!is_array($arguments)) {
            ff_mcp_rpc_error($id, -32602, 'Invalid arguments');
        }
        try {
            require_once __DIR__ . '/include/db.php';
            require_once __DIR__ . '/include/ff_rezepturen_schema.php';
            require_once __DIR__ . '/include/ff_position_stock_summary.php';
            require_once __DIR__ . '/include/menu_tile_helpers.php';
            require_once __DIR__ . '/include/ff_fest_scope.php';
            if (function_exists('ff_rezepturen_ensure_schema')) {
                ff_rezepturen_ensure_schema($conn);
            }
            $data = match ($canonicalToolName) {
                'get_print_targets' => ff_mcp_print_targets($conn),
                'get_unterkategorien' => ff_mcp_get_unterkategorien($conn),
                'create_unterkategorie' => ff_mcp_create_unterkategorie($conn, $arguments),
                'update_unterkategorie' => ff_mcp_update_unterkategorie($conn, $arguments),
                'delete_unterkategorie' => ff_mcp_delete_unterkategorie($conn, $arguments),
                'get_orders' => ff_mcp_orders($conn, $arguments),
                'update_position' => ff_mcp_update_position($conn, $arguments),
                'get_bestandteile' => ff_mcp_get_bestandteile($conn, $arguments),
                'create_bestandteil' => ff_mcp_create_bestandteil($conn, $arguments),
                'update_bestandteil' => ff_mcp_update_bestandteil($conn, $arguments),
                'delete_bestandteil' => ff_mcp_delete_bestandteil($conn, $arguments),
                'get_recipe' => ff_mcp_get_recipe($conn, $arguments),
                'set_recipe' => ff_mcp_set_recipe($conn, $arguments),
                'add_recipe_item' => ff_mcp_add_recipe_item($conn, $arguments),
                'remove_recipe_item' => ff_mcp_remove_recipe_item($conn, $arguments),
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
                'content' => [['type' => 'text', 'text' => 'Fehler: ' . $exception->getMessage()]],
                'isError' => true,
            ]);
        }

    default:
        ff_mcp_rpc_error($id, -32601, 'Method not found');
}
