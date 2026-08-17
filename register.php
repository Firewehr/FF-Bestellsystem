<?php
require_once('auth.php');
require_once('include/db.php');
require_once('include/user_landing.php');
require_once __DIR__ . '/include/ff_finance_schema.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/ff_user_permissions.php';
require_once __DIR__ . '/include/ff_user_status.php';

header("Cache-Control: no-cache");
header('Content-Type: application/json; charset=utf-8');

// Admin und Super-Admin dürfen User anlegen (admin >= 1)
if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

ff_users_ensure_landing_columns($conn);

$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');
$password_again = (string)($_POST['password_again'] ?? '');
$adminFlag = (int)($_POST['admin'] ?? 0);
$canFinance = isset($_POST['can_finance']) && (string)$_POST['can_finance'] === '1' ? 1 : 0;
$canDirektverkauf = isset($_POST['can_direktverkauf']) && (string)$_POST['can_direktverkauf'] === '1' ? 1 : 0;
$menuPermissionsRaw = trim((string)($_POST['menu_permissions'] ?? ''));
$forcePwChange = isset($_POST['force_password_change']) && (string)$_POST['force_password_change'] === '1' ? 1 : 0;
$displayName = trim((string)($_POST['display_name'] ?? ''));
if ($displayName !== '') {
    // hart begrenzen auf VARCHAR(120) – mehr passt eh nicht aufs Bon-Papier.
    $displayName = mb_substr($displayName, 0, 120, 'UTF-8');
} else {
    $displayName = null;
}

// Super-Admin nur beim ersten Setup (login.php), nie über diese Maske
if ($adminFlag === 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_via_setup_only']);
    exit;
}
if ($adminFlag !== 0 && $adminFlag !== 1) {
    $adminFlag = 0;
}

$startPage = ff_user_normalize_start_page($_POST['start_page'] ?? 'menu');
$startPtIn = isset($_POST['start_print_target']) ? (int)$_POST['start_print_target'] : 0;
if ($adminFlag === 1) {
    $startPage = 'menu';
    $startPtIn = 0;
}
$startPtVal = null;
if ($startPage === 'print_target') {
    if (!ff_user_print_target_is_valid($conn, $startPtIn)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_print_target']);
        exit;
    }
    $startPtVal = $startPtIn;
}

if ($username === '' || $password === '' || $password_again === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

if ($password !== $password_again) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'password_mismatch']);
    exit;
}

// Passwort-Hash (bcrypt)
$hash = password_hash($password, PASSWORD_BCRYPT);
if ($hash === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'hash_failed']);
    exit;
}

// UNIQUE(username) muss in der DB existieren (hast du ja)
ff_finance_ensure_schema($conn);
ff_users_ensure_direktverkauf_column($conn);
ff_users_ensure_menu_permissions_column($conn);
ff_users_ensure_status_columns($conn);
if ($startPage === 'print_target' && $startPtVal !== null) {
    $stmt = $conn->prepare("INSERT INTO users (username, display_name, password, admin, can_finance, can_direktverkauf, start_page, start_print_target) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssiiisi', $username, $displayName, $hash, $adminFlag, $canFinance, $canDirektverkauf, $startPage, $startPtVal);
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, display_name, password, admin, can_finance, can_direktverkauf, start_page, start_print_target) VALUES (?,?,?,?,?,?,?,NULL)");
    $stmt->bind_param('sssiiis', $username, $displayName, $hash, $adminFlag, $canFinance, $canDirektverkauf, $startPage);
}

$ok = $stmt->execute();
if (!$ok) {
    // Duplicate Username
    if ((int)$conn->errno === 1062) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'username_taken']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error', 'errno' => (int)$conn->errno]);
    exit;
}

$newId = (int)$conn->insert_id;
if ($newId > 0 && $forcePwChange === 1) {
    $fpc = $conn->prepare('UPDATE users SET force_password_change = 1 WHERE id = ?');
    if ($fpc) {
        $fpc->bind_param('i', $newId);
        $fpc->execute();
        $fpc->close();
    }
}
if ($newId > 0 && $menuPermissionsRaw !== '') {
    $incoming = json_decode($menuPermissionsRaw, true);
    if (is_array($incoming)) {
        $perms = ff_user_permissions_default();
        if (isset($incoming['menu']) && is_array($incoming['menu'])) {
            foreach ($perms['menu'] as $k => $_) {
                $perms['menu'][$k] = !empty($incoming['menu'][$k]) ? 1 : 0;
            }
        }
        if (isset($incoming['print_targets']) && is_array($incoming['print_targets'])) {
            foreach ($incoming['print_targets'] as $pt) {
                $pt = (int)$pt;
                if ($pt > 0 && ff_user_print_target_is_valid($conn, $pt)) {
                    $perms['print_targets'][] = $pt;
                }
            }
            $perms['print_targets'] = array_values(array_unique($perms['print_targets']));
        }
        if ($startPage === 'kueche' || $startPage === 'schank' || ($startPage === 'print_target' && $startPtVal !== null)) {
            $perms = ff_user_permissions_apply_station_bundle($perms, $startPage, (int)($startPtVal ?? 0));
        }
        $perms = ff_user_permissions_apply_print_target_bundle($perms);
        ff_user_permissions_save($conn, $newId, $perms, false);
    }
} elseif ($newId > 0 && ($canFinance || $canDirektverkauf)) {
    $perms = ff_user_permissions_default();
    if ($canFinance) {
        $perms['menu']['finance'] = 1;
    }
    if ($canDirektverkauf) {
        $perms['menu']['direktverkauf'] = 1;
    }
    if ($startPage === 'tische') {
        $perms['menu']['tische'] = 1;
    }
    ff_user_permissions_save($conn, $newId, $perms, true);
}

echo json_encode([
    'ok' => true,
    'id' => $newId,
    'message' => "Neuer Benutzer ($username) wurde angelegt!"
]);
