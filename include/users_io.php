<?php
/**
 * Separater Export & Import für Benutzer.
 *
 * Hintergrund: Benutzer (users) gehören NICHT zu einem konkreten Fest. Damit
 * Hülle-/Voll-Imports lokale Admin-Konten und Passwörter nicht überschreiben,
 * werden User über diese eigenständige Schnittstelle gepflegt.
 *
 * Format (JSON):
 *   {
 *     "format":  "FBS_USERS_EXPORT",
 *     "version": 1,
 *     "exported_at": "2026-05-05T10:11:12+02:00",
 *     "users": [
 *        { "id": 1, "username": "admin", "password": "<hash>", "admin": 2, ... },
 *        ...
 *     ]
 *   }
 *
 * Importregeln (Variante A – sicher):
 *   - Match per username (Primärschlüssel id wird beim Import nie übernommen).
 *   - Username existiert nicht → INSERT (neue auto-id).
 *   - Username existiert + overwrite=false → SKIP.
 *   - Username existiert + overwrite=true  → UPDATE alle bekannten Spalten außer id/username/timestamp.
 *   - Es wird NIE ein User gelöscht.
 */

if (!function_exists('users_io_pdo')) {
    function users_io_pdo(): PDO
    {
        require_once __DIR__ . '/fest_io.php';
        return db();
    }
}

if (!function_exists('users_io_table_columns')) {
    /** @return string[] */
    function users_io_table_columns(PDO $pdo): array
    {
        $cols = [];
        $st = $pdo->query('SHOW COLUMNS FROM `users`');
        if ($st) {
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = (string) $r['Field'];
            }
        }
        return $cols;
    }
}

if (!function_exists('users_io_export_payload')) {
    /**
     * Liefert den vollständigen Benutzerexport als assoziatives Array (Payload).
     */
    function users_io_export_payload(PDO $pdo): array
    {
        $rows = [];
        $st = $pdo->query('SELECT * FROM `users` ORDER BY id ASC');
        if ($st) {
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return [
            'format' => 'FBS_USERS_EXPORT',
            'version' => 1,
            'exported_at' => date(DATE_ATOM),
            'users' => $rows,
        ];
    }
}

if (!function_exists('users_io_import')) {
    /**
     * Importiert Benutzer aus einem Payload.
     *
     * @param PDO   $pdo       Aktive PDO-Verbindung
     * @param array $payload   Decodiertes JSON (mind. format=FBS_USERS_EXPORT, users=[...])
     * @param bool  $overwrite Wenn true: bestehende User (Match per username) werden aktualisiert. Default false.
     *
     * @return array{inserted:int, updated:int, skipped:int, invalid:int, total:int}
     * @throws Exception bei Format-/Strukturfehlern
     */
    function users_io_import(PDO $pdo, array $payload, bool $overwrite = false): array
    {
        if (($payload['format'] ?? '') !== 'FBS_USERS_EXPORT') {
            throw new Exception('Unbekanntes oder fehlendes Format (erwartet: FBS_USERS_EXPORT).');
        }
        $rows = $payload['users'] ?? null;
        if (!is_array($rows)) {
            throw new Exception('Kein gültiges users-Array im Import.');
        }

        $cols = users_io_table_columns($pdo);
        if ($cols === []) {
            throw new Exception('Tabelle users nicht gefunden.');
        }
        // Diese Spalten werden NIE direkt aus der Quelle übernommen.
        $skipCols = ['id', 'timestamp'];

        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'total' => 0];

        $stFind = $pdo->prepare('SELECT id FROM `users` WHERE username = ? LIMIT 1');

        foreach ($rows as $u) {
            $stats['total']++;
            if (!is_array($u)) {
                $stats['invalid']++;
                continue;
            }
            $username = isset($u['username']) ? trim((string) $u['username']) : '';
            if ($username === '') {
                $stats['invalid']++;
                continue;
            }

            $row = ['username' => $username];
            foreach ($cols as $c) {
                if (in_array($c, $skipCols, true) || $c === 'username') {
                    continue;
                }
                if (array_key_exists($c, $u)) {
                    $row[$c] = $u[$c];
                }
            }

            $stFind->execute([$username]);
            $existingId = $stFind->fetchColumn();
            $stFind->closeCursor();

            if ($existingId !== false && $existingId !== null) {
                if (!$overwrite) {
                    $stats['skipped']++;
                    continue;
                }
                $set = [];
                $params = [];
                foreach ($row as $c => $v) {
                    if ($c === 'username') {
                        continue;
                    }
                    $set[] = '`' . $c . '`=?';
                    $params[] = $v;
                }
                if ($set === []) {
                    $stats['skipped']++;
                    continue;
                }
                $params[] = (int) $existingId;
                $sql = 'UPDATE `users` SET ' . implode(',', $set) . ' WHERE `id`=?';
                $pdo->prepare($sql)->execute($params);
                $stats['updated']++;
            } else {
                $colList = array_keys($row);
                $ph = implode(',', array_fill(0, count($colList), '?'));
                $sql = 'INSERT INTO `users` (`' . implode('`,`', $colList) . '`) VALUES (' . $ph . ')';
                $pdo->prepare($sql)->execute(array_values($row));
                $stats['inserted']++;
            }
        }
        return $stats;
    }
}
