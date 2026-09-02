<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_rezepturen_schema.php';

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

function rez_api_position_name(array $positions, int $positionId): string {
    foreach ($positions as $position) {
        if ((int)$position['rowid'] === $positionId) {
            return (string)$position['Positionsname'];
        }
    }
    return '';
}

function rez_api_normalize_rows(array $rows, int $positionId): array {
    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $componentId = isset($row['bestandteil_position_id']) ? (int)$row['bestandteil_position_id'] : 0;
        $mengeRaw = isset($row['menge']) ? (string)$row['menge'] : '1';
        $mengeRaw = str_replace(',', '.', trim($mengeRaw));
        $menge = (float)$mengeRaw;
        $reihenfolge = isset($row['reihenfolge']) ? (int)$row['reihenfolge'] : 0;
        if ($componentId <= 0) {
            continue;
        }
        if ($componentId === $positionId) {
            rez_api_json(false, ['error' => 'self_reference'], 400);
        }
        if (!is_finite($menge) || $menge <= 0) {
            $menge = 1.0;
        }
        $clean[] = [
            'bestandteil_position_id' => $componentId,
            'menge' => $menge,
            'reihenfolge' => $reihenfolge,
        ];
    }

    usort($clean, static function (array $a, array $b): int {
        if ($a['reihenfolge'] === $b['reihenfolge']) {
            return $a['bestandteil_position_id'] <=> $b['bestandteil_position_id'];
        }
        return $a['reihenfolge'] <=> $b['reihenfolge'];
    });

    return $clean;
}

$positions = rez_api_fetch_positions($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $positionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;
    if ($positionId <= 0 && $positions !== []) {
        $positionId = (int)$positions[0]['rowid'];
    }

    $rows = [];
    if ($positionId > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT id, position_id, bestandteil_position_id, menge, reihenfolge, fest_id FROM position_rezepturen WHERE position_id = ? ORDER BY reihenfolge, id');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $positionId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $rows[] = [
                        'id' => (int)$row['id'],
                        'position_id' => (int)$row['position_id'],
                        'bestandteil_position_id' => (int)$row['bestandteil_position_id'],
                        'menge' => (string)$row['menge'],
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
        'position_id' => $positionId,
        'position_name' => rez_api_position_name($positions, $positionId),
        'rows' => $rows,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if ($action !== 'save') {
        rez_api_json(false, ['error' => 'bad_action'], 400);
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

    $cleanRows = rez_api_normalize_rows($decoded, $positionId);

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
            $ins = mysqli_prepare($conn, 'INSERT INTO position_rezepturen (position_id, bestandteil_position_id, menge, reihenfolge, fest_id) VALUES (?, ?, ?, ?, NULL)');
            if (!$ins) {
                throw new RuntimeException('insert_prepare_failed');
            }
            foreach ($cleanRows as $row) {
                $componentId = (int)$row['bestandteil_position_id'];
                $menge = (float)$row['menge'];
                $reihenfolge = (int)$row['reihenfolge'];
                mysqli_stmt_bind_param($ins, 'iidi', $positionId, $componentId, $menge, $reihenfolge);
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
