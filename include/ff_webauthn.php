<?php
declare(strict_types=1);

/**
 * Passkeys (WebAuthn) – ohne externe Bibliothek (kein composer/vendor im Projekt).
 * Deckt genau das ab, was Browser/Betriebssysteme heute für Passkeys senden:
 * ES256 (P-256) und RS256, Attestation wird nicht kryptografisch geprüft
 * ("none"/"packed" etc. werden gleich behandelt) – sicherheitsrelevant ist die
 * Signaturprüfung bei jedem Login (ff_webauthn_login_verify), nicht die Herkunft
 * des Authenticators.
 */

/** Tabelle für gespeicherte Passkeys sicherstellen. */
function ff_webauthn_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `user_passkeys` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `credential_id` VARCHAR(1024) NOT NULL,
        `public_key_pem` TEXT NOT NULL,
        `sign_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `label` VARCHAR(120) NOT NULL DEFAULT '',
        `transports` VARCHAR(255) NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_used_at` DATETIME NULL DEFAULT NULL,
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ff_webauthn_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ff_webauthn_b64url_decode(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($data, true);
    return $out === false ? '' : $out;
}

/** Relying Party ID = Host ohne Port (muss zur aufrufenden Domain passen). */
function ff_webauthn_rp_id(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return explode(':', $host)[0];
}

function ff_webauthn_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

/**
 * Sehr kompakter CBOR-Decoder für genau die Strukturen, die WebAuthn nutzt
 * (attestationObject-Map, COSE-Key-Map): unsigned/negative int, byte/text
 * strings (definite + indefinite), arrays, maps, bool/null.
 */
final class FfCborReader
{
    private string $data;
    private int $pos = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function pos(): int
    {
        return $this->pos;
    }

    private function readByte(): int
    {
        if ($this->pos >= strlen($this->data)) {
            throw new RuntimeException('CBOR: unerwartetes Ende');
        }
        return ord($this->data[$this->pos++]);
    }

    private function readBytes(int $n): string
    {
        if ($n < 0 || $this->pos + $n > strlen($this->data)) {
            throw new RuntimeException('CBOR: unerwartetes Ende');
        }
        $out = substr($this->data, $this->pos, $n);
        $this->pos += $n;
        return $out;
    }

    private function readUint(int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }
        if ($additional === 24) {
            return $this->readByte();
        }
        if ($additional === 25) {
            $b = $this->readBytes(2);
            return (ord($b[0]) << 8) | ord($b[1]);
        }
        if ($additional === 26) {
            $b = $this->readBytes(4);
            return (ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3]);
        }
        if ($additional === 27) {
            $b = $this->readBytes(8);
            $v = 0;
            for ($i = 0; $i < 8; $i++) {
                $v = ($v << 8) | ord($b[$i]);
            }
            return $v;
        }
        throw new RuntimeException('CBOR: nicht unterstützte Länge');
    }

    private function peekBreak(): bool
    {
        return $this->pos < strlen($this->data) && ord($this->data[$this->pos]) === 0xFF;
    }

    private function readIndefiniteChunks(): string
    {
        $out = '';
        while (true) {
            if ($this->peekBreak()) {
                $this->pos++;
                break;
            }
            $out .= (string) $this->decode();
        }
        return $out;
    }

    /** @return mixed */
    public function decode()
    {
        $head = $this->readByte();
        $major = $head >> 5;
        $additional = $head & 0x1F;

        switch ($major) {
            case 0:
                return $this->readUint($additional);
            case 1:
                return -1 - $this->readUint($additional);
            case 2:
            case 3:
                if ($additional === 31) {
                    return $this->readIndefiniteChunks();
                }
                return $this->readBytes($this->readUint($additional));
            case 4:
                $len = ($additional === 31) ? -1 : $this->readUint($additional);
                $out = [];
                if ($len === -1) {
                    while (!$this->peekBreak()) {
                        $out[] = $this->decode();
                    }
                    $this->pos++;
                } else {
                    for ($i = 0; $i < $len; $i++) {
                        $out[] = $this->decode();
                    }
                }
                return $out;
            case 5:
                $len = ($additional === 31) ? -1 : $this->readUint($additional);
                $out = [];
                if ($len === -1) {
                    while (!$this->peekBreak()) {
                        $k = $this->decode();
                        $out[$k] = $this->decode();
                    }
                    $this->pos++;
                } else {
                    for ($i = 0; $i < $len; $i++) {
                        $k = $this->decode();
                        $out[$k] = $this->decode();
                    }
                }
                return $out;
            case 6:
                $this->readUint($additional);
                return $this->decode();
            case 7:
                if ($additional === 20) {
                    return false;
                }
                if ($additional === 21) {
                    return true;
                }
                if ($additional === 25) {
                    $this->readBytes(2);
                    return null;
                }
                if ($additional === 26) {
                    $this->readBytes(4);
                    return null;
                }
                if ($additional === 27) {
                    $this->readBytes(8);
                    return null;
                }
                return null;
            default:
                throw new RuntimeException('CBOR: unbekannter Major-Type');
        }
    }
}

function ff_cbor_decode(string $data)
{
    return (new FfCborReader($data))->decode();
}

/** authenticatorData (roh, kein CBOR) in seine Felder zerlegen. */
function ff_webauthn_parse_auth_data(string $authData): array
{
    if (strlen($authData) < 37) {
        throw new RuntimeException('authData zu kurz');
    }
    $off = 0;
    $rpIdHash = substr($authData, $off, 32);
    $off += 32;
    $flags = ord($authData[$off]);
    $off += 1;
    $signCount = unpack('N', substr($authData, $off, 4))[1];
    $off += 4;

    $credentialId = null;
    $coseKey = null;
    if ($flags & 0x40) { // AT: attested credential data vorhanden
        $off += 16; // AAGUID (nicht benötigt)
        $credIdLen = unpack('n', substr($authData, $off, 2))[1];
        $off += 2;
        $credentialId = substr($authData, $off, $credIdLen);
        $off += $credIdLen;
        $reader = new FfCborReader(substr($authData, $off));
        $coseKey = $reader->decode();
        $off += $reader->pos();
    }

    return [
        'rpIdHash' => $rpIdHash,
        'flags' => $flags,
        'signCount' => $signCount,
        'credentialId' => $credentialId,
        'coseKey' => $coseKey,
    ];
}

function ff_der_len(int $len): string
{
    if ($len < 128) {
        return chr($len);
    }
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xFF) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function ff_der_seq(string $content): string
{
    return "\x30" . ff_der_len(strlen($content)) . $content;
}

function ff_der_bitstring(string $content): string
{
    $withPad = "\x00" . $content;
    return "\x03" . ff_der_len(strlen($withPad)) . $withPad;
}

function ff_der_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') {
        $bytes = "\x00";
    }
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }
    return "\x02" . ff_der_len(strlen($bytes)) . $bytes;
}

function ff_der_to_pem(string $der): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** COSE EC2-Key (P-256/ES256) -> PEM SubjectPublicKeyInfo. */
function ff_webauthn_cose_ec2_to_pem(array $cose): ?string
{
    $x = $cose[-2] ?? null;
    $y = $cose[-3] ?? null;
    if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
        return null;
    }
    $point = "\x04" . $x . $y;
    // OID id-ecPublicKey (1.2.840.10045.2.1) + OID prime256v1 (1.2.840.10045.3.1.7)
    $algId = ff_der_seq(
        "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" .
        "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"
    );
    return ff_der_to_pem(ff_der_seq($algId . ff_der_bitstring($point)));
}

/** COSE RSA-Key (RS256) -> PEM SubjectPublicKeyInfo. */
function ff_webauthn_cose_rsa_to_pem(array $cose): ?string
{
    $n = $cose[-1] ?? null;
    $e = $cose[-2] ?? null;
    if (!is_string($n) || !is_string($e)) {
        return null;
    }
    $rsaPub = ff_der_seq(ff_der_integer($n) . ff_der_integer($e));
    // OID rsaEncryption (1.2.840.113549.1.1.1) + NULL
    $algId = ff_der_seq("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
    return ff_der_to_pem(ff_der_seq($algId . ff_der_bitstring($rsaPub)));
}

function ff_webauthn_cose_to_pem(array $cose): ?string
{
    $kty = $cose[1] ?? null; // 2=EC2, 3=RSA
    if ($kty === 2) {
        return ff_webauthn_cose_ec2_to_pem($cose);
    }
    if ($kty === 3) {
        return ff_webauthn_cose_rsa_to_pem($cose);
    }
    return null;
}

/** Optionen für navigator.credentials.create() (Admin legt Passkey für Benutzer an). */
function ff_webauthn_register_options(mysqli $conn, int $userId, string $username): array
{
    ff_webauthn_ensure_schema($conn);
    $challenge = random_bytes(32);
    $_SESSION['webauthn_reg_challenge'] = ff_webauthn_b64url_encode($challenge);
    $_SESSION['webauthn_reg_user_id'] = $userId;

    $existing = [];
    $stmt = mysqli_prepare($conn, 'SELECT credential_id FROM user_passkeys WHERE user_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $existing[] = ['id' => $row['credential_id'], 'type' => 'public-key'];
    }
    mysqli_stmt_close($stmt);

    return [
        'challenge' => ff_webauthn_b64url_encode($challenge),
        'rp' => ['id' => ff_webauthn_rp_id(), 'name' => 'FF Bestellsystem'],
        'user' => [
            'id' => ff_webauthn_b64url_encode((string) $userId),
            'name' => $username,
            'displayName' => $username,
        ],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],   // ES256
            ['type' => 'public-key', 'alg' => -257], // RS256
        ],
        'timeout' => 60000,
        'attestation' => 'none',
        'authenticatorSelection' => [
            'residentKey' => 'required',
            'requireResidentKey' => true,
            'userVerification' => 'required',
        ],
        'excludeCredentials' => $existing,
    ];
}

/** Attestation-Antwort des Browsers prüfen und Passkey speichern. */
function ff_webauthn_register_verify(mysqli $conn, array $cred, string $label): array
{
    ff_webauthn_ensure_schema($conn);
    $expectedChallenge = (string) ($_SESSION['webauthn_reg_challenge'] ?? '');
    $userId = (int) ($_SESSION['webauthn_reg_user_id'] ?? 0);
    unset($_SESSION['webauthn_reg_challenge'], $_SESSION['webauthn_reg_user_id']);

    if ($expectedChallenge === '' || $userId <= 0) {
        return ['ok' => false, 'error' => 'no_pending_challenge'];
    }

    $clientDataJsonRaw = ff_webauthn_b64url_decode((string) ($cred['response']['clientDataJSON'] ?? ''));
    $attestationObjectRaw = ff_webauthn_b64url_decode((string) ($cred['response']['attestationObject'] ?? ''));
    if ($clientDataJsonRaw === '' || $attestationObjectRaw === '') {
        return ['ok' => false, 'error' => 'bad_request'];
    }

    $clientData = json_decode($clientDataJsonRaw, true);
    if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.create') {
        return ['ok' => false, 'error' => 'bad_client_data'];
    }
    if (!hash_equals($expectedChallenge, (string) ($clientData['challenge'] ?? ''))) {
        return ['ok' => false, 'error' => 'challenge_mismatch'];
    }
    if (!hash_equals(ff_webauthn_origin(), (string) ($clientData['origin'] ?? ''))) {
        return ['ok' => false, 'error' => 'origin_mismatch'];
    }

    try {
        $attObj = ff_cbor_decode($attestationObjectRaw);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'cbor_error'];
    }
    $authDataRaw = (string) ($attObj['authData'] ?? '');
    if ($authDataRaw === '') {
        return ['ok' => false, 'error' => 'no_auth_data'];
    }

    try {
        $parsed = ff_webauthn_parse_auth_data($authDataRaw);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'auth_data_error'];
    }

    if (!hash_equals(hash('sha256', ff_webauthn_rp_id(), true), $parsed['rpIdHash'])) {
        return ['ok' => false, 'error' => 'rp_id_mismatch'];
    }
    if (($parsed['flags'] & 0x01) === 0) {
        return ['ok' => false, 'error' => 'user_not_present'];
    }
    if ($parsed['credentialId'] === null || !is_array($parsed['coseKey'])) {
        return ['ok' => false, 'error' => 'no_credential_data'];
    }

    $pem = ff_webauthn_cose_to_pem($parsed['coseKey']);
    if ($pem === null) {
        return ['ok' => false, 'error' => 'unsupported_key_type'];
    }

    $credentialIdB64 = ff_webauthn_b64url_encode($parsed['credentialId']);

    $chk = mysqli_prepare($conn, 'SELECT id FROM user_passkeys WHERE credential_id = ? LIMIT 1');
    mysqli_stmt_bind_param($chk, 's', $credentialIdB64);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    $dup = mysqli_stmt_num_rows($chk) > 0;
    mysqli_stmt_close($chk);
    if ($dup) {
        return ['ok' => false, 'error' => 'already_registered'];
    }

    $label = trim($label) !== '' ? mb_substr(trim($label), 0, 120, 'UTF-8') : 'Passkey';
    $transportsJson = null;
    if (!empty($cred['response']['transports']) && is_array($cred['response']['transports'])) {
        $transportsJson = json_encode(array_values($cred['response']['transports']), JSON_UNESCAPED_UNICODE);
    }

    $signCount = $parsed['signCount'];
    $stmt = mysqli_prepare($conn, 'INSERT INTO user_passkeys (user_id, credential_id, public_key_pem, sign_count, label, transports) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'ississ', $userId, $credentialIdB64, $pem, $signCount, $label, $transportsJson);
    $ok = mysqli_stmt_execute($stmt);
    $newId = $ok ? mysqli_insert_id($conn) : 0;
    mysqli_stmt_close($stmt);

    if (!$ok) {
        return ['ok' => false, 'error' => 'db_error'];
    }
    return ['ok' => true, 'id' => $newId, 'label' => $label];
}

/** Optionen für navigator.credentials.get() – ohne Benutzername (discoverable/resident credential). */
function ff_webauthn_login_options(mysqli $conn): array
{
    ff_webauthn_ensure_schema($conn);
    $challenge = random_bytes(32);
    $_SESSION['webauthn_login_challenge'] = ff_webauthn_b64url_encode($challenge);

    return [
        'challenge' => ff_webauthn_b64url_encode($challenge),
        'rpId' => ff_webauthn_rp_id(),
        'timeout' => 60000,
        'userVerification' => 'required',
        'allowCredentials' => [],
    ];
}

/** @return array{ok:bool,error?:string,user_row?:array} */
function ff_webauthn_login_verify(mysqli $conn, array $cred): array
{
    ff_webauthn_ensure_schema($conn);
    $expectedChallenge = (string) ($_SESSION['webauthn_login_challenge'] ?? '');
    unset($_SESSION['webauthn_login_challenge']);
    if ($expectedChallenge === '') {
        return ['ok' => false, 'error' => 'no_pending_challenge'];
    }

    $rawId = (string) ($cred['rawId'] ?? $cred['id'] ?? '');
    $credentialIdB64 = ff_webauthn_b64url_encode(ff_webauthn_b64url_decode($rawId));
    if ($credentialIdB64 === '') {
        return ['ok' => false, 'error' => 'bad_request'];
    }

    $stmt = mysqli_prepare($conn, 'SELECT id, user_id, public_key_pem, sign_count FROM user_passkeys WHERE credential_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $credentialIdB64);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pk = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$pk) {
        return ['ok' => false, 'error' => 'unknown_credential'];
    }

    $clientDataJsonRaw = ff_webauthn_b64url_decode((string) ($cred['response']['clientDataJSON'] ?? ''));
    $authDataRaw = ff_webauthn_b64url_decode((string) ($cred['response']['authenticatorData'] ?? ''));
    $signature = ff_webauthn_b64url_decode((string) ($cred['response']['signature'] ?? ''));
    if ($clientDataJsonRaw === '' || $authDataRaw === '' || $signature === '') {
        return ['ok' => false, 'error' => 'bad_request'];
    }

    $clientData = json_decode($clientDataJsonRaw, true);
    if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.get') {
        return ['ok' => false, 'error' => 'bad_client_data'];
    }
    if (!hash_equals($expectedChallenge, (string) ($clientData['challenge'] ?? ''))) {
        return ['ok' => false, 'error' => 'challenge_mismatch'];
    }
    if (!hash_equals(ff_webauthn_origin(), (string) ($clientData['origin'] ?? ''))) {
        return ['ok' => false, 'error' => 'origin_mismatch'];
    }
    if (strlen($authDataRaw) < 37) {
        return ['ok' => false, 'error' => 'auth_data_error'];
    }

    $rpIdHash = substr($authDataRaw, 0, 32);
    if (!hash_equals(hash('sha256', ff_webauthn_rp_id(), true), $rpIdHash)) {
        return ['ok' => false, 'error' => 'rp_id_mismatch'];
    }
    $flags = ord($authDataRaw[32]);
    if (($flags & 0x01) === 0) {
        return ['ok' => false, 'error' => 'user_not_present'];
    }
    $signCount = unpack('N', substr($authDataRaw, 33, 4))[1];

    $pubKey = openssl_pkey_get_public($pk['public_key_pem']);
    if ($pubKey === false) {
        return ['ok' => false, 'error' => 'bad_public_key'];
    }
    $signedData = $authDataRaw . hash('sha256', $clientDataJsonRaw, true);
    if (openssl_verify($signedData, $signature, $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'error' => 'signature_invalid'];
    }

    $upd = mysqli_prepare($conn, 'UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?');
    $pkId = (int) $pk['id'];
    mysqli_stmt_bind_param($upd, 'ii', $signCount, $pkId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    $userId = (int) $pk['user_id'];
    $ustmt = mysqli_prepare(
        $conn,
        'SELECT id, username, password, admin, COALESCE(can_finance,0) AS can_finance, ' .
        'COALESCE(can_direktverkauf,0) AS can_direktverkauf, start_page, start_print_target, menu_permissions, ' .
        'COALESCE(is_active,1) AS is_active, active_from, active_until, ' .
        'COALESCE(force_password_change,0) AS force_password_change, COALESCE(auth_rev,0) AS auth_rev ' .
        'FROM users WHERE id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($ustmt, 'i', $userId);
    mysqli_stmt_execute($ustmt);
    $ures = mysqli_stmt_get_result($ustmt);
    $userRow = $ures ? mysqli_fetch_assoc($ures) : null;
    mysqli_stmt_close($ustmt);

    if (!$userRow) {
        return ['ok' => false, 'error' => 'user_not_found'];
    }

    return ['ok' => true, 'user_row' => $userRow];
}
