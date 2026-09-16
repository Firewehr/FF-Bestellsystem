<?php
/**
 * Öffentlich (kein Login nötig): Seite für den QR-Code-Link, die der Benutzer
 * auf seinem eigenen Gerät öffnet, um dort einen Passkey anzulegen.
 */
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_webauthn.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$row = ($token !== '' && isset($conn) && $conn instanceof mysqli) ? ff_webauthn_remote_load_pending($conn, $token) : null;
$ffAppTitle = (isset($conn) && $conn instanceof mysqli) ? ff_app_title($conn) : 'Bestellsystem FF Obritzberg';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passkey anlegen – <?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; background: #eef2f7; padding: 1.5rem; box-sizing: border-box; }
        .ff-card { background: #fff; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.12); padding: 2rem; max-width: 420px; width: 100%; }
    </style>
</head>
<body>
    <div class="ff-card text-center">
        <h1 class="h5 mb-3">🔑 Passkey anlegen</h1>
        <?php if (!$row): ?>
            <div class="alert alert-danger">Dieser Link ist ungültig oder abgelaufen. Bitte im Admin-Bereich einen neuen QR-Code anfordern.</div>
        <?php else: ?>
            <p class="text-muted small mb-3">Für Benutzer <strong><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></strong>.
            Lege hier auf <strong>diesem Gerät</strong> einen Passkey an (z.&nbsp;B. Fingerabdruck/Gesichtserkennung).</p>
            <div id="ffRemoteUnsupported" class="alert alert-warning small d-none">Dieser Browser/dieses Gerät unterstützt keine Passkeys.</div>
            <div id="ffRemoteErr" class="alert alert-danger small d-none"></div>
            <div id="ffRemoteOk" class="alert alert-success small d-none">
                Passkey wurde erfolgreich angelegt. Du kannst dieses Fenster jetzt schließen.
                <a href="login.php" class="d-block mt-2">Zur Anmeldung</a>
            </div>
            <input type="text" class="form-control form-control-sm mb-2" id="ffRemoteLabel" placeholder="Bezeichnung, z.B. „Mein Handy“" maxlength="120">
            <button type="button" class="btn btn-primary w-100" id="ffRemoteAddBtn">Passkey jetzt anlegen</button>
        <?php endif; ?>
    </div>
    <script src="js/webauthn.js"></script>
    <script>
    (function() {
        var token = <?php echo json_encode($token, JSON_UNESCAPED_UNICODE); ?>;
        var btn = document.getElementById('ffRemoteAddBtn');
        if (!btn) return;
        var unsupportedEl = document.getElementById('ffRemoteUnsupported');
        var errEl = document.getElementById('ffRemoteErr');
        var okEl = document.getElementById('ffRemoteOk');
        var labelEl = document.getElementById('ffRemoteLabel');

        function showErr(msg) {
            if (!errEl) return;
            if (!msg) { errEl.classList.add('d-none'); errEl.textContent = ''; return; }
            errEl.textContent = msg;
            errEl.classList.remove('d-none');
        }

        if (!window.FfWebAuthn || !window.FfWebAuthn.supported()) {
            if (unsupportedEl) unsupportedEl.classList.remove('d-none');
            btn.disabled = true;
            return;
        }

        btn.addEventListener('click', function() {
            showErr('');
            btn.disabled = true;
            fetch('webauthn_register_remote_options.php?token=' + encodeURIComponent(token))
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (!j || !j.ok) { throw new Error(j && j.error ? j.error : 'options_failed'); }
                    return window.FfWebAuthn.createPasskey(j.options);
                })
                .then(function(credential) {
                    return fetch('webauthn_register_remote_finish.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ token: token, credential: credential, label: labelEl ? labelEl.value.trim() : '' })
                    });
                })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (!j || !j.ok) {
                        var map = {
                            invalid_or_expired: 'Dieser Link ist abgelaufen. Bitte im Admin-Bereich einen neuen QR-Code anfordern.',
                            already_registered: 'Dieser Passkey ist bereits registriert.',
                            challenge_mismatch: 'Anfrage abgelaufen, bitte neuen QR-Code anfordern.'
                        };
                        showErr(map[j && j.error] || 'Passkey konnte nicht angelegt werden.');
                        btn.disabled = false;
                        return;
                    }
                    if (okEl) okEl.classList.remove('d-none');
                    btn.classList.add('d-none');
                    if (labelEl) labelEl.classList.add('d-none');
                })
                .catch(function(e) {
                    showErr((e && e.name === 'NotAllowedError') ? 'Abgebrochen oder nicht erlaubt.' : 'Passkey konnte nicht angelegt werden.');
                    btn.disabled = false;
                });
        });
    })();
    </script>
</body>
</html>
