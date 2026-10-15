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
            ]],
        ]);

    case 'tools/call':
        $toolName = (string)($request['params']['name'] ?? '');
        if ($toolName !== 'get_menu_catalog') {
            ff_mcp_rpc_error($id, -32602, 'Unknown tool');
        }
        try {
            require_once __DIR__ . '/include/db.php';
            $catalog = ff_mcp_menu_catalog($conn);
            ff_mcp_rpc_response($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]],
                'structuredContent' => $catalog,
                'isError' => false,
            ]);
        } catch (Throwable $exception) {
            error_log('MCP menu catalog read failed: ' . $exception->getMessage());
            ff_mcp_rpc_response($id, [
                'content' => [['type' => 'text', 'text' => 'Katalogdaten konnten nicht gelesen werden.']],
                'isError' => true,
            ]);
        }

    default:
        ff_mcp_rpc_error($id, -32601, 'Method not found');
}
