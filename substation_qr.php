<?php
declare(strict_types=1);

require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
$action = (string) ($_GET['action'] ?? '');
if ($action === 'redeem') {
    $cookieParams = session_get_cookie_params();
    ini_set('session.gc_maxlifetime', '43200');
    session_set_cookie_params([
        'lifetime' => 43200,
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => $cookieParams['secure'],
        'httponly' => true,
        'samesite' => $cookieParams['samesite'] ?? 'Lax',
    ]);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

const FF_SUBSTATION_SETTING = 'substations_config';

if ($action === 'close') {
    unset($_SESSION['substation_scope_station'], $_SESSION['substation_scope_expires']);
    header('Location: login.php');
    exit;
}

if ($action === 'create') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/include/settings.php';

    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    $csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expectedCsrfToken = (string) ($_SESSION['csrf_substation_qr'] ?? '');
    if ($expectedCsrfToken === '' || $csrfToken === '' || !hash_equals($expectedCsrfToken, $csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    $station = is_array($input) ? trim((string) ($input['station'] ?? '')) : '';
    $config = json_decode((string) setting_get($conn, FF_SUBSTATION_SETTING, '{}'), true);
    if ($station === '' || !is_array($config) || !isset($config[$station])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'unknown_station']);
        exit;
    }

    try {
        $created = mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS `substation_access_tokens` (
                `token_hash` CHAR(64) NOT NULL,
                `station` VARCHAR(40) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`token_hash`),
                KEY `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$created) {
            throw new RuntimeException('Sub-Station QR-Tabelle konnte nicht angelegt werden.');
        }
        if (!mysqli_query($conn, 'DELETE FROM `substation_access_tokens` WHERE `expires_at` < NOW() OR (`used_at` IS NOT NULL AND `used_at` < DATE_SUB(NOW(), INTERVAL 1 DAY))')) {
            throw new RuntimeException('Abgelaufene Sub-Station QR-Tokens konnten nicht bereinigt werden.');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO `substation_access_tokens` (`token_hash`, `station`, `expires_at`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE))'
        );
        if (!$stmt) {
            throw new RuntimeException('Sub-Station QR-Token konnte nicht vorbereitet werden.');
        }
        mysqli_stmt_bind_param($stmt, 'ss', $tokenHash, $station);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Sub-Station QR-Token konnte nicht gespeichert werden.');
        }
        mysqli_stmt_close($stmt);
        echo json_encode([
            'ok' => true,
            'url' => 'substation_qr.php?action=redeem&token=' . rawurlencode($token),
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('substation_qr.php create: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server']);
    }
    exit;
}

if ($action === 'redeem') {
    require_once __DIR__ . '/include/db.php';

    $token = (string) ($_GET['token'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        http_response_code(400);
        echo 'Ungültiger QR-Zugang.';
        exit;
    }

    try {
        $created = mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS `substation_access_tokens` (
                `token_hash` CHAR(64) NOT NULL,
                `station` VARCHAR(40) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`token_hash`),
                KEY `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$created) {
            throw new RuntimeException('Sub-Station QR-Tabelle ist nicht verfügbar.');
        }

        $tokenHash = hash('sha256', $token);
        $stmt = mysqli_prepare($conn, 'SELECT `station` FROM `substation_access_tokens` WHERE `token_hash` = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Sub-Station QR-Token konnte nicht geprüft werden.');
        }
        mysqli_stmt_bind_param($stmt, 's', $tokenHash);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Sub-Station QR-Token konnte nicht geprüft werden.');
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if (!$row) {
            http_response_code(410);
            echo 'Dieser QR-Zugang ist ungültig, abgelaufen oder wurde bereits verwendet.';
            exit;
        }

        $stmt = mysqli_prepare(
            $conn,
            'UPDATE `substation_access_tokens` SET `used_at` = NOW(), `expires_at` = DATE_ADD(NOW(), INTERVAL 12 HOUR) WHERE `token_hash` = ? AND `used_at` IS NULL AND `expires_at` >= NOW()'
        );
        if (!$stmt) {
            throw new RuntimeException('Sub-Station QR-Token konnte nicht eingelöst werden.');
        }
        mysqli_stmt_bind_param($stmt, 's', $tokenHash);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Sub-Station QR-Token konnte nicht eingelöst werden.');
        }
        $redeemed = mysqli_stmt_affected_rows($stmt) === 1;
        mysqli_stmt_close($stmt);
        if (!$redeemed) {
            http_response_code(410);
            echo 'Dieser QR-Zugang ist ungültig, abgelaufen oder wurde bereits verwendet.';
            exit;
        }

        $station = (string) $row['station'];
        header(
            'Location: substation.php?station=' . rawurlencode($station) . '&access=' . rawurlencode($token),
            true,
            303
        );
    } catch (Throwable $e) {
        error_log('substation_qr.php redeem: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Der QR-Zugang konnte nicht eingelöst werden. Bitte einen neuen QR-Code erstellen.';
    }
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Nicht gefunden.';
