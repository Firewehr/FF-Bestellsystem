<?php
require_once __DIR__ . '/include/runtime_bootstrap.php';

include_once __DIR__ . '/include/db.php';
include_once 'include/user_landing.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_finance_schema.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
session_start();

$message = [];

if (isset($conn) && $conn instanceof mysqli) {
    @mysqli_set_charset($conn, 'utf8mb4');
}

$setup_required = false;

if (!isset($conn) || !($conn instanceof mysqli)) {
    $message['error'] = 'Datenbankverbindung fehlt ($conn). Prüfe include/db.php.';
} else {
    ff_users_ensure_landing_columns($conn);
    $res = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM users');
    if ($res) {
        $rowc = mysqli_fetch_assoc($res);
        $setup_required = ((int) ($rowc['c'] ?? 0) === 0);
        mysqli_free_result($res);
    } else {
        $message['error'] = 'Konnte Benutzeranzahl nicht prüfen: ' . mysqli_error($conn);
    }
}

if ($setup_required) {

    if (!empty($_POST) && isset($_POST['setup_mode'])) {

        $u = trim($_POST['setup']['username'] ?? 'admin');
        if ($u === '') {
            $u = 'admin';
        }

        $p1 = (string) ($_POST['setup']['password'] ?? '');
        $p2 = (string) ($_POST['setup']['password2'] ?? '');

        if ($p1 === '' || $p2 === '') {
            $message['error'] = 'Bitte Passwort und Bestätigung ausfüllen.';
        } elseif ($p1 !== $p2) {
            $message['error'] = 'Passwörter stimmen nicht überein.';
        } elseif (strlen($p1) < 6) {
            $message['error'] = 'Passwort zu kurz (mind. 6 Zeichen).';
        } else {
            $hash = password_hash($p1, PASSWORD_BCRYPT);

            $stmt = mysqli_prepare($conn, 'INSERT INTO users (username, password, admin) VALUES (?, ?, 2)');
            if (!$stmt) {
                $message['error'] = 'Konnte Benutzer nicht anlegen: ' . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($stmt, 'ss', $u, $hash);
                if (mysqli_stmt_execute($stmt)) {

                    $_SESSION = [];
                    session_regenerate_id(true);
                    $_SESSION['login'] = true;
                    $_SESSION['user'] = ['username' => $u];
                    $_SESSION['admin'] = 2;
                    $_SESSION['ff_menu_compact'] = 0;

                    header('Location: index.php');
                    exit;
                } else {
                    $message['error'] = 'Konnte Benutzer nicht anlegen: ' . mysqli_stmt_error($stmt);
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    ?>
<?php
$ffAppTitle = (isset($conn) && $conn instanceof mysqli) ? ff_app_title($conn) : 'Bestellsystem FF Obritzberg';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Setup – <?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body.login-feuerwehr-page {
            min-height: 100vh; margin: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
            padding: clamp(1rem, 4vw, 2rem); padding-top: clamp(1.25rem, 5vh, 2.5rem);
            background: linear-gradient(180deg, #eef2f7 0%, #e2e8f0 55%, #dbe4ee 100%);
            box-sizing: border-box; position: relative; isolation: isolate;
        }
        body.login-feuerwehr-page::before {
            content: '';
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: url('feuerwehr-logo.png') no-repeat center 38% / clamp(280px, 92vmin, 820px);
            opacity: 0.085;
        }
        .login-card { position: relative; z-index: 1; background: rgba(255,255,255,0.94); backdrop-filter: blur(6px); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.12); padding: 2rem; max-width: 400px; width: 100%; }
        .login-card h1 { font-size: 1.5rem; font-weight: 600; color: #333; margin-bottom: 0.5rem; }
        .login-card h2 { font-size: 1rem; color: #666; margin-bottom: 1.5rem; }
        .btn-login { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); border: none; color: #fff; }
        .btn-login:hover { background: linear-gradient(135deg, #991b1b 0%, #6b1717 100%); color: #fff; }
    </style>
</head>
<body class="login-feuerwehr-page">
    <div class="login-card">
        <?php if (isset($message['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($message['error'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <h1><?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <h2>Ersteinrichtung</h2>

        <p class="text-muted"><strong>Ersteinrichtung:</strong> Es gibt noch keinen Benutzer. Bitte lege den Super-Admin an.</p>

        <form action="login.php" method="post">
            <div class="mb-3">
                <label for="setup_username" class="form-label">Benutzername</label>
                <input type="text" class="form-control" id="setup_username" name="setup[username]" value="admin">
            </div>
            <div class="mb-3">
                <label for="setup_password" class="form-label">Passwort</label>
                <input type="password" class="form-control" id="setup_password" name="setup[password]">
            </div>
            <div class="mb-3">
                <label for="setup_password2" class="form-label">Passwort bestätigen</label>
                <input type="password" class="form-control" id="setup_password2" name="setup[password2]">
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-login">Admin anlegen</button>
                <button type="reset" class="btn btn-outline-secondary">Reset</button>
            </div>
            <input type="hidden" name="setup_mode" value="1">
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}

if (!empty($_SESSION['login'])) {
    header('Location: index.php');
    exit;
}

if (!empty($_GET['msg'])) {
    $message['notice'] = mb_substr(trim((string) $_GET['msg']), 0, 280, 'UTF-8');
}

if (!empty($_POST)) {

    if (empty($_POST['f']['username']) || empty($_POST['f']['password'])) {
        $message['error'] = 'Es wurden nicht alle Felder ausgefüllt.';
    } else {

        $user = trim((string) $_POST['f']['username']);
        $pass = (string) $_POST['f']['password'];

        if (!isset($conn) || !($conn instanceof mysqli)) {
            $message['error'] = 'Datenbankverbindung fehlt ($conn). Prüfe include/db.php.';
        } else {

            ff_finance_ensure_schema($conn);
            require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
            require_once __DIR__ . '/include/ff_user_permissions.php';
            require_once __DIR__ . '/include/ff_user_status.php';
            ff_users_ensure_direktverkauf_column($conn);
            ff_users_ensure_menu_permissions_column($conn);
            ff_users_ensure_status_columns($conn);
            ff_users_ensure_auth_rev_column($conn);
            $stmt = mysqli_prepare($conn, 'SELECT id, username, password, admin, COALESCE(can_finance,0) AS can_finance, COALESCE(can_direktverkauf,0) AS can_direktverkauf, start_page, start_print_target, menu_permissions, COALESCE(is_active,1) AS is_active, active_from, active_until, COALESCE(force_password_change,0) AS force_password_change, COALESCE(auth_rev,0) AS auth_rev FROM users WHERE username = ? LIMIT 1');
            if (!$stmt) {
                $message['error'] = 'Datenbankfehler: ' . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($stmt, 's', $user);
                mysqli_stmt_execute($stmt);

                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;

                mysqli_stmt_close($stmt);

                if ($row) {
                    $loginAllowed = ff_user_login_allowed($row, $conn);
                    if (password_verify($pass, $row['password']) && !$loginAllowed['ok']) {
                        $message['error'] = $loginAllowed['error'] ?? 'Anmeldung derzeit nicht möglich.';
                    } elseif (password_verify($pass, $row['password'])) {

                        if (password_needs_rehash($row['password'], PASSWORD_BCRYPT)) {
                            $newHash = password_hash($pass, PASSWORD_BCRYPT);
                            $ustmt = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE username=?');
                            if ($ustmt) {
                                mysqli_stmt_bind_param($ustmt, 'ss', $newHash, $row['username']);
                                mysqli_stmt_execute($ustmt);
                                mysqli_stmt_close($ustmt);
                            }
                        }

                        $_SESSION = [];
                        session_regenerate_id(true);
                        $_SESSION['login'] = true;
                        $_SESSION['user'] = ['username' => $row['username']];
                        $_SESSION['user_id'] = (int) ($row['id'] ?? 0);
                        $_SESSION['auth_rev'] = (int) ($row['auth_rev'] ?? 0);
                        $_SESSION['admin'] = $row['admin'];
                        $ffPerms = ff_user_permissions_decode($row);
                        $ffLegacy = ff_user_permissions_sync_legacy_flags($ffPerms);
                        $_SESSION['menu_permissions'] = $ffPerms;
                        $_SESSION['can_finance'] = $ffLegacy['can_finance'];
                        $_SESSION['can_direktverkauf'] = $ffLegacy['can_direktverkauf'];
                        $_SESSION['force_password_change'] = (int) ($row['force_password_change'] ?? 0);
                        $spLogin = ff_user_normalize_start_page($row['start_page'] ?? 'menu');
                        $_SESSION['ff_menu_compact'] = ((int) $row['admin'] < 1 && $spLogin !== 'menu') ? 1 : 0;

                        if ((int) ($row['force_password_change'] ?? 0) === 1) {
                            header('Location: index.php#pwForceChange');
                            exit;
                        }

                        $land = ff_user_login_landing_hash(
                            $conn,
                            (int) $row['admin'],
                            $row['start_page'] ?? 'menu',
                            $row['start_print_target'] ?? null
                        );
                        header('Location: index.php#' . $land);
                        exit;
                    } else {
                        $message['error'] = 'Das Kennwort ist nicht korrekt.';
                    }
                } else {
                    $message['error'] = 'Der Benutzer wurde nicht gefunden.';
                }
            }
        }
    }
}
$ffAppTitle = (isset($conn) && $conn instanceof mysqli) ? ff_app_title($conn) : 'Bestellsystem FF Obritzberg';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login – <?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body.login-feuerwehr-page {
            min-height: 100vh; margin: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
            padding: clamp(1rem, 4vw, 2rem); padding-top: clamp(1.25rem, 5vh, 2.5rem);
            background: linear-gradient(180deg, #eef2f7 0%, #e2e8f0 55%, #dbe4ee 100%);
            box-sizing: border-box; position: relative; isolation: isolate;
        }
        body.login-feuerwehr-page::before {
            content: '';
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: url('feuerwehr-logo.png') no-repeat center 38% / clamp(280px, 92vmin, 820px);
            opacity: 0.085;
        }
        .login-card { position: relative; z-index: 1; background: rgba(255,255,255,0.94); backdrop-filter: blur(6px); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.12); padding: 2rem; max-width: 400px; width: 100%; }
        .login-card h1 { font-size: 1.5rem; font-weight: 600; color: #333; margin-bottom: 0.5rem; }
        .login-card h2 { font-size: 1rem; color: #666; margin-bottom: 1.5rem; }
        .btn-login { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); border: none; color: #fff; }
        .btn-login:hover { background: linear-gradient(135deg, #991b1b 0%, #6b1717 100%); color: #fff; }
    </style>
</head>
<body class="login-feuerwehr-page">
    <div class="login-card">
        <?php if (isset($message['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($message['error'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (isset($message['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars((string) $message['success'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (isset($message['notice'])): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message['notice'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <h1><?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <h2>Anmeldung</h2>

        <form action="login.php" method="post">
            <div class="mb-3">
                <label for="username" class="form-label">Benutzername</label>
                <input type="text" class="form-control" id="username" name="f[username]"
                    <?php echo isset($_POST['f']['username']) ? ' value="' . htmlspecialchars($_POST['f']['username'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Kennwort</label>
                <input type="password" class="form-control" id="password" name="f[password]">
            </div>
            <div class="d-grid gap-2">
                <button type="submit" name="submit" class="btn btn-primary btn-login">Anmelden</button>
                <button type="reset" class="btn btn-outline-secondary">Reset</button>
            </div>
        </form>
    </div>
</body>
</html>
