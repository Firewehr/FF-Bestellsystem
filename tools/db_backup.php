<?php
/**
 * Datenbank-Backup per mysqldump (CLI, für Cron).
 *
 *   php tools/db_backup.php [--copy-to=/media/usb/ff-backups] [--quiet]
 *
 * - Schreibt backups/db_<datenbank>_YYYYmmdd_HHMMSS.sql.gz (Rechte 0600).
 * - InnoDB: --single-transaction, d. h. konsistenter Stand ohne Tabellensperren
 *   (Bestellannahme läuft währenddessen normal weiter).
 * - Aufbewahrung: alle Stände der letzten 48 Stunden, danach einer pro Tag
 *   (der letzte des Tages) für 60 Tage. Ältere werden gelöscht.
 * - --copy-to (oder Umgebungsvariable FF_BACKUP_COPY_DIR): zusätzlich auf ein
 *   zweites Laufwerk kopieren, damit ein Serverausfall nicht alles mitnimmt.
 *   Fehler beim Kopieren lassen das Backup selbst nicht scheitern (Exit-Code 2).
 *
 * Zugangsdaten wie include/db.php (Umgebungsvariablen FF_DB_*, include/db.local.php).
 * Das Passwort geht über eine temporäre Defaults-Datei (0600) an mysqldump, nie über
 * die Kommandozeile (sonst in `ps` sichtbar).
 *
 * Exit-Codes: 0 ok, 1 Backup fehlgeschlagen, 2 Backup ok, aber Kopie fehlgeschlagen.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

date_default_timezone_set('Europe/Vienna'); // wie include/runtime_bootstrap.php
$root = dirname(__DIR__);
$opts = getopt('', ['copy-to:', 'quiet']);
$quiet = isset($opts['quiet']);
$copyTo = (string) ($opts['copy-to'] ?? (getenv('FF_BACKUP_COPY_DIR') ?: ''));

$log = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$msg}\n");
    }
};
$fail = static function (string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] FEHLER: {$msg}\n");
    exit(1);
};

// Zugangsdaten auflösen wie include/db.php – ohne Verbindung aufzubauen.
$hostname = 'localhost';
$username = '';
$password = '';
$dbname = '';
foreach (['FF_DB_HOST' => 'hostname', 'FF_DB_USER' => 'username', 'FF_DB_NAME' => 'dbname'] as $env => $var) {
    $v = getenv($env);
    if ($v !== false && $v !== '') {
        $$var = $v;
    }
}
if (getenv('FF_DB_PASS') !== false) {
    $password = (string) getenv('FF_DB_PASS');
}
if (is_readable($root . '/include/db.local.php')) {
    require $root . '/include/db.local.php';
}
if ($dbname === '' || $username === '') {
    $fail('Keine Datenbank-Zugangsdaten gefunden (include/db.local.php oder FF_DB_*).');
}

$host = (string) $hostname;
$port = '';
$socket = '';
if (strpos($host, 'p:') === 0) {
    $host = substr($host, 2);
}
if ($host !== '' && $host[0] === '/') {
    $socket = $host;
    $host = '';
} elseif (preg_match('~^(.+):(\d+)$~', $host, $m)) {
    $host = $m[1];
    $port = $m[2];
}

$dumpBin = '';
foreach (['mariadb-dump', 'mysqldump'] as $bin) {
    $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
    if ($path !== '') {
        $dumpBin = $path;
        break;
    }
}
if ($dumpBin === '') {
    $fail('mysqldump/mariadb-dump nicht gefunden.');
}

$backupDir = $root . '/backups';
if (!is_dir($backupDir) || !is_writable($backupDir)) {
    $fail("Ordner {$backupDir} fehlt oder ist nicht beschreibbar (Besitzer prüfen: www-data).");
}

$quote = static function (string $v): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
};
$cnf = tempnam(sys_get_temp_dir(), 'ffdump');
chmod($cnf, 0600);
$cnfBody = "[client]\nuser=" . $quote($username) . "\npassword=" . $quote((string) $password) . "\n";
if ($socket !== '') {
    $cnfBody .= 'socket=' . $quote($socket) . "\n";
} else {
    $cnfBody .= 'host=' . $quote($host === '' ? 'localhost' : $host) . "\n";
    if ($port !== '') {
        $cnfBody .= "port={$port}\n";
    }
}
file_put_contents($cnf, $cnfBody);

$safeDb = preg_replace('~[^A-Za-z0-9_-]~', '_', $dbname);
$stamp = date('Ymd_His');
$target = "{$backupDir}/db_{$safeDb}_{$stamp}.sql.gz";
$tmp = $target . '.part';

$cmd = escapeshellarg($dumpBin)
    . ' --defaults-extra-file=' . escapeshellarg($cnf)
    . ' --single-transaction --quick --routines --triggers --events'
    . ' --default-character-set=utf8mb4 --hex-blob --no-tablespaces'
    . ' ' . escapeshellarg($dbname);

$started = microtime(true);
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    @unlink($cnf);
    $fail('mysqldump konnte nicht gestartet werden.');
}
$gz = gzopen($tmp, 'wb6');
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 1 << 16);
    if ($chunk === false) {
        break;
    }
    gzwrite($gz, $chunk);
}
gzclose($gz);
$stderr = trim((string) stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
$rc = proc_close($proc);
@unlink($cnf);

// Ein vollständiger Dump endet mit "-- Dump completed".
$tail = '';
$fh = gzopen($tmp, 'rb');
if ($fh) {
    while (!gzeof($fh)) {
        $tail = substr($tail . gzread($fh, 1 << 16), -4096);
    }
    gzclose($fh);
}
if ($rc !== 0 || strpos($tail, '-- Dump completed') === false) {
    @unlink($tmp);
    $fail("mysqldump fehlgeschlagen (Exit {$rc}): " . ($stderr !== '' ? $stderr : 'Dump unvollständig'));
}
chmod($tmp, 0600);
rename($tmp, $target);
$log(sprintf('Backup %s (%.1f KB, %.1f s)', basename($target), filesize($target) / 1024, microtime(true) - $started));

// Aufbewahrung: 48 h alles, danach der jüngste Stand pro Tag für 60 Tage.
$rotate = static function (string $dir) use ($safeDb, $log): void {
    $files = glob($dir . '/db_' . $safeDb . '_*.sql.gz') ?: [];
    rsort($files); // neueste zuerst (Zeitstempel im Namen)
    $now = time();
    $keptDays = [];
    foreach ($files as $f) {
        if (!preg_match('~_(\d{8})_(\d{6})\.sql\.gz$~', $f, $m)) {
            continue;
        }
        $ts = strtotime($m[1] . 'T' . $m[2]);
        $age = $now - (int) $ts;
        if ($age <= 48 * 3600) {
            continue;
        }
        if ($age <= 60 * 86400 && !isset($keptDays[$m[1]])) {
            $keptDays[$m[1]] = true;
            continue;
        }
        if (@unlink($f)) {
            $log('Alter Stand gelöscht: ' . basename($f));
        }
    }
};
$rotate($backupDir);

if ($copyTo !== '') {
    $copyTo = rtrim($copyTo, '/');
    if (!is_dir($copyTo) || !is_writable($copyTo) || !@copy($target, $copyTo . '/' . basename($target))) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] WARNUNG: Kopie nach {$copyTo} fehlgeschlagen (Laufwerk eingesteckt?).\n");
        exit(2);
    }
    @chmod($copyTo . '/' . basename($target), 0600);
    $rotate($copyTo);
    $log("Kopie nach {$copyTo}");
}
exit(0);
