<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_rezepturen_schema.php';
require_once __DIR__ . '/../include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_rezepturen_ensure_schema($conn);

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function rez_api_json(bool $ok, array $data = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function rez_api_fetch_positions(mysqli $conn): array {
    $positions = [];
    $result = mysqli_query($conn, 'SELECT rowid, Positionsname, Kurzbezeichnung, type, maxBestellbar FROM positionen ORDER BY type, reihenfolge, Positionsname');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $positions[] = [
                'rowid' => (int)$row['rowid'],
                'Positionsname' => (string)$row['Positionsname'],
                'Kurzbezeichnung' => (string)($row['Kurzbezeichnung'] ?? ''),
                'type' => (int)$row['type'],
                'maxBestellbar' => (int)$row['maxBestellbar'],
            ];
        }
    }
    return $positions;
}

function rez_api_fetch_bestandteile(mysqli $conn): array {
    $rows = [];
    if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'bestandteile')) {
        return $rows;
    }

    $result = mysqli_query($conn, 'SELECT b.id, b.name, b.einheit, b.kapazitaet, b.aktiv, b.sort_order,
        COALESCE(GROUP_CONCAT(a.name ORDER BY a.sort_order, a.name SEPARATOR ", "), "") AS allergene
        FROM bestandteile b
        LEFT JOIN bestandteil_allergene ba ON ba.bestandteil_id = b.id
        LEFT JOIN allergene a ON a.id = ba.allergen_id
        GROUP BY b.id, b.name, b.einheit, b.kapazitaet, b.aktiv, b.sort_order
        ORDER BY b.aktiv DESC, b.sort_order, b.name');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'einheit' => (string)($row['einheit'] ?? 'Stk'),
                'kapazitaet' => (int)($row['kapazitaet'] ?? -1),
                'aktiv' => (int)($row['aktiv'] ?? 1),
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'allergene' => (string)($row['allergene'] ?? ''),
            ];
        }
    }
    if ($rows === []) {
        return $rows;
    }
    $consumed = ff_bestandteil_consumed_map(
        $conn,
        array_map(static fn(array $row): int => (int) $row['id'], $rows)
    );
    foreach ($rows as &$row) {
        $row['bestellt'] = $consumed[(int) $row['id']] ?? 0.0;
    }
    unset($row);
    return $rows;
}

function rez_api_normalize_allergene($value): array {
    $names = preg_split('/[,;\r\n]+/', (string)$value) ?: [];
    $clean = [];
    foreach ($names as $name) {
        $name = trim($name);
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

function rez_api_save_bestandteil_allergene(mysqli $conn, int $bestandteilId, array $allergene): void {
    $delete = mysqli_prepare($conn, 'DELETE FROM bestandteil_allergene WHERE bestandteil_id = ?');
    if (!$delete) {
        throw new RuntimeException('allergen_delete_prepare_failed');
    }
    mysqli_stmt_bind_param($delete, 'i', $bestandteilId);
    if (!mysqli_stmt_execute($delete)) {
        throw new RuntimeException('allergen_delete_failed');
    }
    mysqli_stmt_close($delete);

    foreach ($allergene as $name) {
        $insertAllergen = mysqli_prepare($conn, 'INSERT INTO allergene (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        if (!$insertAllergen) {
            throw new RuntimeException('allergen_insert_prepare_failed');
        }
        mysqli_stmt_bind_param($insertAllergen, 's', $name);
        if (!mysqli_stmt_execute($insertAllergen)) {
            throw new RuntimeException('allergen_insert_failed');
        }
        $allergenId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insertAllergen);

        $assign = mysqli_prepare($conn, 'INSERT INTO bestandteil_allergene (bestandteil_id, allergen_id) VALUES (?, ?)');
        if (!$assign) {
            throw new RuntimeException('allergen_assign_prepare_failed');
        }
        mysqli_stmt_bind_param($assign, 'ii', $bestandteilId, $allergenId);
        if (!mysqli_stmt_execute($assign)) {
            throw new RuntimeException('allergen_assign_failed');
        }
        mysqli_stmt_close($assign);
    }
}

function rez_api_position_name(array $positions, int $positionId): string {
    foreach ($positions as $position) {
        if ((int)$position['rowid'] === $positionId) {
            return (string)$position['Positionsname'];
        }
    }
    return '';
}

function rez_api_normalize_rows(array $rows): array {
    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $componentId = isset($row['bestandteil_id']) ? (int)$row['bestandteil_id'] : 0;
        $mengeRaw = isset($row['menge']) ? (string)$row['menge'] : '1';
        $mengeRaw = str_replace(',', '.', trim($mengeRaw));
        $menge = (float)$mengeRaw;
        $reihenfolge = isset($row['reihenfolge']) ? (int)$row['reihenfolge'] : 0;
        $optionalEnabled = isset($row['optional_enabled']) ? (int)$row['optional_enabled'] : 0;
        $defaultSelected = isset($row['default_selected']) ? (int)$row['default_selected'] : 1;
        $optionGroup = isset($row['option_group']) ? trim((string)$row['option_group']) : '';
        if (function_exists('mb_strlen') && mb_strlen($optionGroup, 'UTF-8') > 64) {
            $optionGroup = mb_substr($optionGroup, 0, 64, 'UTF-8');
        } elseif (!function_exists('mb_strlen') && strlen($optionGroup) > 64) {
            $optionGroup = substr($optionGroup, 0, 64);
        }
        if ($componentId <= 0) {
            continue;
        }
        if (!is_finite($menge) || $menge <= 0) {
            $menge = 1.0;
        }
        $clean[] = [
            'bestandteil_id' => $componentId,
            'menge' => $menge,
            'optional_enabled' => $optionalEnabled === 1 ? 1 : 0,
            'default_selected' => $defaultSelected === 1 ? 1 : 0,
            'option_group' => $optionGroup,
            'reihenfolge' => $reihenfolge,
        ];
    }

    usort($clean, static function (array $a, array $b): int {
        if ($a['reihenfolge'] === $b['reihenfolge']) {
            return $a['bestandteil_id'] <=> $b['bestandteil_id'];
        }
        return $a['reihenfolge'] <=> $b['reihenfolge'];
    });

    return $clean;
}

$positions = rez_api_fetch_positions($conn);
$bestandteile = rez_api_fetch_bestandteile($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $positionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;
    if ($positionId <= 0 && $positions !== []) {
        $positionId = (int)$positions[0]['rowid'];
    }

    $rows = [];
    if ($positionId > 0 && function_exists('ff_rezepturen_table_exists') && ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        $stmt = mysqli_prepare($conn, 'SELECT id, position_id, bestandteil_id, menge, optional_enabled, default_selected, option_group, reihenfolge, fest_id FROM position_rezepturen WHERE position_id = ? ORDER BY reihenfolge, id');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $positionId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $rows[] = [
                        'id' => (int)$row['id'],
                        'position_id' => (int)$row['position_id'],
                        'bestandteil_id' => (int)$row['bestandteil_id'],
                        'menge' => (string)$row['menge'],
                        'optional_enabled' => (int)($row['optional_enabled'] ?? 0),
                        'default_selected' => (int)($row['default_selected'] ?? 1),
                        'option_group' => (string)($row['option_group'] ?? ''),
                        'reihenfolge' => (int)$row['reihenfolge'],
                        'fest_id' => $row['fest_id'] !== null ? (int)$row['fest_id'] : null,
                    ];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    rez_api_json(true, [
        'positions' => $positions,
        'bestandteile' => $bestandteile,
        'position_id' => $positionId,
        'position_name' => rez_api_position_name($positions, $positionId),
        'rows' => $rows,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if ($action === 'add_bestandteil') {
        if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'bestandteile')) {
            ff_rezepturen_ensure_schema($conn);
        }
        if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'bestandteile')) {
            rez_api_json(false, ['error' => 'table_missing_bestandteile'], 500);
        }

        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $einheit = isset($_POST['einheit']) ? trim((string)$_POST['einheit']) : 'Stk';
        $kapazitaet = isset($_POST['kapazitaet']) ? (int)$_POST['kapazitaet'] : -1;
        if ($name === '') {
            rez_api_json(false, ['error' => 'missing_name'], 400);
        }
        if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 255) {
            $name = mb_substr($name, 0, 255, 'UTF-8');
        }
        if (function_exists('mb_strlen') && mb_strlen($einheit, 'UTF-8') > 30) {
            $einheit = mb_substr($einheit, 0, 30, 'UTF-8');
        }
        if ($einheit === '') {
            $einheit = 'Stk';
        }
        $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $allergene = rez_api_normalize_allergene($_POST['allergene'] ?? '');
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, 'INSERT INTO bestandteile (name, einheit, kapazitaet, aktiv, sort_order, fest_id) VALUES (?, ?, ?, 1, ?, NULL)');
        if (!$stmt) {
            mysqli_rollback($conn);
            rez_api_json(false, ['error' => 'insert_prepare_failed'], 500);
        }
        mysqli_stmt_bind_param($stmt, 'ssii', $name, $einheit, $kapazitaet, $sortOrder);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_rollback($conn);
            rez_api_json(false, ['error' => 'insert_failed'], 500);
        }
        $newId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        try {
            rez_api_save_bestandteil_allergene($conn, $newId, $allergene);
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            rez_api_json(false, ['error' => $e->getMessage()], 500);
        }
        rez_api_json(true, ['id' => $newId, 'bestandteile' => rez_api_fetch_bestandteile($conn)]);
    }

    if ($action === 'save_bestandteile') {
        if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'bestandteile')) {
            ff_rezepturen_ensure_schema($conn);
        }
        if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'bestandteile')) {
            rez_api_json(false, ['error' => 'table_missing_bestandteile'], 500);
        }

        $rowsJson = isset($_POST['rows_json']) ? (string)$_POST['rows_json'] : '[]';
        $decoded = json_decode($rowsJson, true);
        if (!is_array($decoded)) {
            rez_api_json(false, ['error' => 'bad_rows_json'], 400);
        }

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, 'UPDATE bestandteile SET name = ?, einheit = ?, kapazitaet = ?, sort_order = ? WHERE id = ?');
            if (!$stmt) {
                throw new RuntimeException('update_prepare_failed');
            }
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                $name = isset($row['name']) ? trim((string)$row['name']) : '';
                $einheit = isset($row['einheit']) ? trim((string)$row['einheit']) : 'Stk';
                $kapazitaet = isset($row['kapazitaet']) ? (int)$row['kapazitaet'] : -1;
                $sortOrder = isset($row['sort_order']) ? (int)$row['sort_order'] : 0;
                $allergene = rez_api_normalize_allergene($row['allergene'] ?? '');
                if ($id <= 0 || $name === '') {
                    continue;
                }
                if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 255) {
                    $name = mb_substr($name, 0, 255, 'UTF-8');
                }
                if (function_exists('mb_strlen') && mb_strlen($einheit, 'UTF-8') > 30) {
                    $einheit = mb_substr($einheit, 0, 30, 'UTF-8');
                }
                if ($einheit === '') {
                    $einheit = 'Stk';
                }
                mysqli_stmt_bind_param($stmt, 'ssiii', $name, $einheit, $kapazitaet, $sortOrder, $id);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException('update_failed');
                }
                rez_api_save_bestandteil_allergene($conn, $id, $allergene);
            }
            mysqli_stmt_close($stmt);
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            rez_api_json(false, ['error' => $e->getMessage()], 500);
        }

        rez_api_json(true, ['bestandteile' => rez_api_fetch_bestandteile($conn)]);
    }

    if ($action !== 'save') {
        rez_api_json(false, ['error' => 'bad_action'], 400);
    }

    if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        ff_rezepturen_ensure_schema($conn);
    }
    if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        rez_api_json(false, ['error' => 'table_missing_position_rezepturen'], 500);
    }

    $positionId = isset($_POST['position_id']) ? (int)$_POST['position_id'] : 0;
    if ($positionId <= 0) {
        rez_api_json(false, ['error' => 'bad_position_id'], 400);
    }

    $rowsJson = isset($_POST['rows_json']) ? (string)$_POST['rows_json'] : '[]';
    $decoded = json_decode($rowsJson, true);
    if (!is_array($decoded)) {
        rez_api_json(false, ['error' => 'bad_rows_json'], 400);
    }

    $cleanRows = rez_api_normalize_rows($decoded);

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, 'DELETE FROM position_rezepturen WHERE position_id = ?');
        if (!$stmt) {
            throw new RuntimeException('delete_prepare_failed');
        }
        mysqli_stmt_bind_param($stmt, 'i', $positionId);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('delete_failed');
        }
        mysqli_stmt_close($stmt);

        if ($cleanRows !== []) {
            $ins = mysqli_prepare($conn, 'INSERT INTO position_rezepturen (position_id, bestandteil_id, menge, optional_enabled, default_selected, option_group, reihenfolge, fest_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)');
            if (!$ins) {
                throw new RuntimeException('insert_prepare_failed');
            }
            foreach ($cleanRows as $row) {
                $componentId = (int)$row['bestandteil_id'];
                $menge = (float)$row['menge'];
                $optionalEnabled = (int)$row['optional_enabled'];
                $defaultSelected = (int)$row['default_selected'];
                $optionGroup = (string)$row['option_group'];
                $reihenfolge = (int)$row['reihenfolge'];
                mysqli_stmt_bind_param($ins, 'iidiisi', $positionId, $componentId, $menge, $optionalEnabled, $defaultSelected, $optionGroup, $reihenfolge);
                if (!mysqli_stmt_execute($ins)) {
                    throw new RuntimeException('insert_failed');
                }
            }
            mysqli_stmt_close($ins);
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        rez_api_json(false, ['error' => $e->getMessage()], 500);
    }

    rez_api_json(true, [
        'position_id' => $positionId,
        'position_name' => rez_api_position_name($positions, $positionId),
        'rows' => $cleanRows,
    ]);
}

rez_api_json(false, ['error' => 'method_not_allowed'], 405);
