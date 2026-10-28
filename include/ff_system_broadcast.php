<?php
/**
 * Systemnachricht (Vollbild für alle User) + optionale Login-Sperre (außer Super-Admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const FF_SYSTEM_BROADCAST_KEY_ID = 'system_broadcast_id';
const FF_SYSTEM_BROADCAST_KEY_TEXT = 'system_broadcast_text';
const FF_SYSTEM_BROADCAST_KEY_AT = 'system_broadcast_at';
const FF_SYSTEM_LOGIN_LOCK_ALL = 'system_login_lock_all';

/** @return array{id:int,text:string,at:string,active:bool,login_lock_all:bool} */
function ff_system_broadcast_get(mysqli $conn): array
{
    settings_ensure_table($conn);
    $id = max(0, (int) setting_get($conn, FF_SYSTEM_BROADCAST_KEY_ID, '0'));
    $text = trim((string) setting_get($conn, FF_SYSTEM_BROADCAST_KEY_TEXT, ''));
    $at = trim((string) setting_get($conn, FF_SYSTEM_BROADCAST_KEY_AT, ''));
    $lock = setting_get($conn, FF_SYSTEM_LOGIN_LOCK_ALL, '0') === '1';

    return [
        'id' => $id,
        'text' => $text,
        'at' => $at,
        'active' => $text !== '',
        'login_lock_all' => $lock,
    ];
}

function ff_system_login_globally_locked(mysqli $conn): bool
{
    return setting_get($conn, FF_SYSTEM_LOGIN_LOCK_ALL, '0') === '1';
}

/** Alle Nicht-Super-Admins sofort ausloggen (auth_rev erhöhen). */
function ff_system_broadcast_bump_all_non_super(mysqli $conn): int
{
    require_once __DIR__ . '/ff_user_status.php';
    ff_users_ensure_auth_rev_column($conn);

    $res = mysqli_query($conn, 'SELECT id FROM users WHERE admin <> 2');
    if (!$res) {
        return 0;
    }
    $n = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $uid = (int) ($row['id'] ?? 0);
        if ($uid > 0) {
            ff_user_bump_auth_rev($conn, $uid);
            $n++;
        }
    }
    mysqli_free_result($res);

    return $n;
}

/**
 * @return array{ok:bool,message?:string,error?:string,id?:int,bumped?:int}
 */
function ff_system_broadcast_publish(mysqli $conn, string $text, bool $lockAllLogins): array
{
    settings_ensure_table($conn);
    $text = trim($text);
    if ($text === '' && !$lockAllLogins) {
        return ['ok' => false, 'error' => 'empty', 'message' => 'Nachricht oder Login-Sperre erforderlich.'];
    }
    if ($text !== '' && mb_strlen($text, 'UTF-8') > 2000) {
        $text = mb_substr($text, 0, 2000, 'UTF-8');
    }

    $prevId = max(0, (int) setting_get($conn, FF_SYSTEM_BROADCAST_KEY_ID, '0'));
    $newId = $prevId + 1;
    if ($text !== '') {
        setting_set($conn, FF_SYSTEM_BROADCAST_KEY_ID, (string) $newId);
        setting_set($conn, FF_SYSTEM_BROADCAST_KEY_TEXT, $text);
        setting_set($conn, FF_SYSTEM_BROADCAST_KEY_AT, date('Y-m-d H:i:s'));
    }

    $bumped = 0;
    if ($lockAllLogins) {
        setting_set($conn, FF_SYSTEM_LOGIN_LOCK_ALL, '1');
        $bumped = ff_system_broadcast_bump_all_non_super($conn);
    }

    $msg = $text !== ''
        ? 'Systemnachricht wurde gesendet.'
        : 'Login-Sperre wurde aktiviert (ohne Nachricht).';
    if ($lockAllLogins) {
        $msg .= ' Alle Benutzer und Administratoren (außer Super-Admin) wurden ausgeloggt und können sich nicht anmelden, bis die Sperre aufgehoben wird.';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'id' => $text !== '' ? $newId : $prevId,
        'bumped' => $bumped,
    ];
}

/** Nachricht beenden (Overlay verschwindet für alle nach erneutem Poll / neuer id). */
function ff_system_broadcast_clear_message(mysqli $conn): void
{
    $id = max(0, (int) setting_get($conn, FF_SYSTEM_BROADCAST_KEY_ID, '0'));
    setting_set($conn, FF_SYSTEM_BROADCAST_KEY_ID, (string) ($id + 1));
    setting_set($conn, FF_SYSTEM_BROADCAST_KEY_TEXT, '');
    setting_set($conn, FF_SYSTEM_BROADCAST_KEY_AT, '');
}

/** Login-Sperre aufheben (manuell inaktive Konten bleiben inaktiv). */
function ff_system_broadcast_unlock_logins(mysqli $conn): void
{
    setting_set($conn, FF_SYSTEM_LOGIN_LOCK_ALL, '0');
}
