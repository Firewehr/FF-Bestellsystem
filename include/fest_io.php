<?php
require_once __DIR__ . '/settings.php';

/**
 * Fest-Export/Import (JSON).
 * - mode=full: Vollbackup inkl. Bestellungen, Rechnungen, Buchungen, Druck-Queue, Nutzer, Settings, Stammdaten …
 * - mode=template (Hülle): wie full, aber ohne Verkäufe/Abrechnung (keine bestellungen, rechnungen, …), inkl. User + komplette Settings.
 */

function festio_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
  $st->execute([$table]);

    return (bool) $st->fetchColumn();
}

function festio_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
  $st = $pdo->prepare($sql);
  $st->execute($params);

  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function festio_table_columns(PDO $pdo, string $table): array
{
    if (!festio_table_exists($pdo, $table)) {
        return [];
    }
    $t = festio_safe_table($table);
    $rows = festio_fetch_all($pdo, 'SHOW COLUMNS FROM `' . $t . '`');

    return array_map(fn ($r) => strtolower((string) ($r['Field'] ?? '')), $rows);
}

function festio_has_column(PDO $pdo, string $table, string $column): bool
{
    $c = strtolower($column);

    return in_array($c, festio_table_columns($pdo, $table), true);
}

/** Tabellen-Identifier (inkl. Umlaute) */
function festio_safe_table(string $t): string
{
    if (!preg_match('/^[\p{L}0-9_]+$/u', $t)) {
        throw new Exception('Ungültiger Tabellenname.');
    }

    return $t;
}

/**
 * Map: Spaltenname lowercase → exakter Feldname laut SHOW COLUMNS (für Imports mit Extra-Feldern aus anderen DB-Versionen).
 *
 * @return array<string, string>
 */
function festio_table_column_name_map(PDO $pdo, string $table): array
{
    static $cache = [];
    $t = festio_safe_table($table);
    if (isset($cache[$t])) {
        return $cache[$t];
    }
    if (!festio_table_exists($pdo, $t)) {
        return $cache[$t] = [];
    }
    $map = [];
    foreach (festio_fetch_all($pdo, 'SHOW COLUMNS FROM `' . $t . '`') as $r) {
        $field = (string) ($r['Field'] ?? '');
        if ($field !== '') {
            $map[strtolower($field)] = $field;
        }
    }

    return $cache[$t] = $map;
}

/**
 * Nur Spalten behalten, die in der Zieltabelle existieren (verhindert z. B. „Unknown column 'description'“ bei print_targets).
 *
 * @return array<string, mixed>
 */
function festio_filter_row_for_table(PDO $pdo, string $table, array $row): array
{
    if ($row === [] || !festio_table_exists($pdo, $table)) {
        return $row;
    }
    $map = festio_table_column_name_map($pdo, $table);
    if ($map === []) {
        return [];
    }
    $out = [];
    foreach ($row as $k => $v) {
        $lk = strtolower((string) $k);
        if (isset($map[$lk])) {
            $out[$map[$lk]] = $v;
        }
    }

    return $out;
}

function festio_pdo_replace_row(PDO $pdo, string $table, array $row): void
{
    $table = festio_safe_table($table);
    $row = festio_filter_row_for_table($pdo, $table, $row);
    if ($row === []) {
        return;
    }
    $cols = array_keys($row);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'REPLACE INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')';
    $pdo->prepare($sql)->execute(array_values($row));
}

function festio_pdo_insert_row(PDO $pdo, string $table, array $row): void
{
    $table = festio_safe_table($table);
    $row = festio_filter_row_for_table($pdo, $table, $row);
    if ($row === []) {
        return;
    }
    $cols = array_keys($row);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')';
    $pdo->prepare($sql)->execute(array_values($row));
}

function festio_table_count(PDO $pdo, string $table): int
{
    if (!festio_table_exists($pdo, $table)) {
        return 0;
    }
    $t = festio_safe_table($table);

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
}

/** Primärschlüssel-Spalte von bestellungen (rowid vs id) */
function festio_bestellungen_pk_column(PDO $pdo): string
{
    $cols = festio_table_columns($pdo, 'bestellungen');
    if (in_array('rowid', $cols, true)) {
        return 'rowid';
    }
    if (in_array('id', $cols, true)) {
        return 'id';
    }

    return 'rowid';
}

/**
 * Alle Bestellzeilen-IDs dieses Fests (PK von bestellungen).
 */
function festio_bestellung_ids_for_fest(PDO $pdo, int $fest_id): array
{
    if (!festio_table_exists($pdo, 'bestellungen')) {
        return [];
    }
    $pk = festio_bestellungen_pk_column($pdo);
    $ids = [];

    if (festio_has_column($pdo, 'bestellungen', 'fest_id')) {
        $sql = "SELECT DISTINCT `{$pk}` FROM bestellungen WHERE fest_id = ?";
        foreach (festio_fetch_all($pdo, $sql, [$fest_id]) as $r) {
            $ids[] = (int) ($r[$pk] ?? 0);
        }
    }

    if (festio_table_exists($pdo, 'bestellung_meta') && festio_has_column($pdo, 'bestellung_meta', 'fest_id')) {
        $sql = 'SELECT DISTINCT bestellung_id FROM bestellung_meta WHERE fest_id = ?';
        foreach (festio_fetch_all($pdo, $sql, [$fest_id]) as $r) {
            $ids[] = (int) ($r['bestellung_id'] ?? 0);
        }
    }

    $ids = array_values(array_unique(array_filter($ids)));

    return $ids;
}

/**
 * Settings-Keys für Hülle-Export nicht mitschicken (Zähler & aktuelles Fest).
 */
function festio_shell_strip_settings_row(array $row): ?array
{
    $k = (string) ($row['k'] ?? '');
    if ($k === '' || preg_match('/^(order_nr_seq|current_fest_id|current_fest_code|rechnung_next)$/i', $k)) {
        return null;
    }
    if (preg_match('/^rechnung_next_[0-9]{4}$/i', $k)) {
        return null;
    }
    if (preg_match('/^RECHNUNG_COUNTER_/i', $k)) {
        return null;
    }

    return $row;
}

/**
 * Menü + Druckziele importieren. $onlyIfEmpty: bei true nur leere Tabellen (Schutz).
 *
 * @return array{menu_imported:bool, menu_skip_reason:?string}
 */
function festio_import_menu_bundle(PDO $pdo, array $tables, bool $onlyIfEmpty, int $newFestId = 0): array
{
    if (!festio_table_exists($pdo, 'positionen')) {
        return ['menu_imported' => false, 'menu_skip_reason' => 'Tabelle positionen fehlt.'];
    }
    if ($onlyIfEmpty) {
        if (festio_table_count($pdo, 'positionen') > 0) {
            return ['menu_imported' => false, 'menu_skip_reason' => 'positionen ist nicht leer – Speisekarte aus Export nicht eingespielt (Absicherung vor Duplikaten).'];
        }
        if (festio_table_exists($pdo, 'position_subcategories') && festio_table_count($pdo, 'position_subcategories') > 0) {
            return ['menu_imported' => false, 'menu_skip_reason' => 'position_subcategories ist nicht leer – nicht eingespielt.'];
        }
    }

    if (isset($tables['print_targets']) && festio_table_exists($pdo, 'print_targets')) {
        foreach ($tables['print_targets'] as $row) {
            festio_pdo_replace_row($pdo, 'print_targets', $row);
        }
    }

    // Hybrid-Fest-Scoping: Spalten anlegen (best-effort) und neue Datensätze direkt
    // dem neuen Fest zuordnen, damit sie beim späteren Fest-Löschen mitgenommen werden.
    foreach (['positionen', 'position_subcategories', 'beilagen'] as $tbl) {
        if (festio_table_exists($pdo, $tbl) && !festio_has_column($pdo, $tbl, 'fest_id')) {
            try {
                $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `fest_id` INT(11) NULL DEFAULT NULL");
                $pdo->exec("ALTER TABLE `{$tbl}` ADD KEY `idx_fest_id` (`fest_id`)");
            } catch (Throwable $e) {
                // best-effort
            }
        }
    }
    $hasFestSub = festio_has_column($pdo, 'position_subcategories', 'fest_id');
    $hasFestPos = festio_has_column($pdo, 'positionen', 'fest_id');
    $hasFestBei = festio_has_column($pdo, 'beilagen', 'fest_id');

    if (isset($tables['position_subcategories']) && festio_table_exists($pdo, 'position_subcategories')) {
        foreach ($tables['position_subcategories'] as $row) {
            if ($hasFestSub && $newFestId > 0) {
                $row['fest_id'] = $newFestId;
            }
            festio_pdo_insert_row($pdo, 'position_subcategories', $row);
        }
    }

    if (isset($tables['positionen'])) {
        foreach ($tables['positionen'] as $row) {
            if ($hasFestPos && $newFestId > 0) {
                $row['fest_id'] = $newFestId;
            }
            festio_pdo_insert_row($pdo, 'positionen', $row);
        }
    }

    if (isset($tables['beilagen']) && festio_table_exists($pdo, 'beilagen')) {
        foreach ($tables['beilagen'] as $row) {
            if ($hasFestBei && $newFestId > 0) {
                $row['fest_id'] = $newFestId;
            }
            festio_pdo_insert_row($pdo, 'beilagen', $row);
        }
    }

    return ['menu_imported' => true, 'menu_skip_reason' => null];
}

function festio_import_settings_rows(PDO $pdo, array $rows): void
{
    if (!festio_table_exists($pdo, 'settings') || $rows === []) {
        return;
    }
    $st = $pdo->prepare('REPLACE INTO settings (`k`,`v`) VALUES (?,?)');
    foreach ($rows as $s) {
        if (!isset($s['k'])) {
            continue;
        }
        $st->execute([(string) $s['k'], (string) ($s['v'] ?? '')]);
    }
}

function festio_import_tische_for_fest(PDO $pdo, array $tischeRows, int $newFestId): void
{
    if ($tischeRows === [] || !festio_table_exists($pdo, 'tische')) {
        return;
    }
    $hasFest = festio_has_column($pdo, 'tische', 'fest_id');
    foreach ($tischeRows as $row) {
        $r = $row;
        unset($r['tischnummer'], $r['id']);
        if ($hasFest) {
            $r['fest_id'] = $newFestId;
        }
        festio_pdo_insert_row($pdo, 'tische', $r);
    }
}

/**
 * @return int neues feste.id
 */
function festio_insert_feste_from_export(PDO $pdo, array $festRow, string $importName, string $importCode): int
{
    if (!festio_table_exists($pdo, 'feste')) {
        throw new Exception("Tabelle 'feste' fehlt.");
    }
    $name = $importName !== '' ? $importName : ($festRow['name'] ?? 'Import');
    $code = $importCode !== '' ? $importCode : ($festRow['code'] ?? 'IMP');
    $festDatum = $festRow['fest_datum'] ?? $festRow['datum'] ?? null;
    if ($festDatum === '' || $festDatum === '0000-00-00') {
        $festDatum = null;
    }
    $aktiv = (int) ($festRow['aktiv'] ?? 0);
    $paymentMode = $festRow['payment_mode'] ?? 'after';
    if ($paymentMode !== 'after' && $paymentMode !== 'instant') {
        $paymentMode = 'after';
    }
    $st = $pdo->prepare('INSERT INTO feste (name, code, fest_datum, aktiv, payment_mode) VALUES (?,?,?,?,?)');
    $st->execute([$name, $code, $festDatum, $aktiv, $paymentMode]);

    return (int) $pdo->lastInsertId();
}

/**
 * Nach Import: aktuelles Fest in settings setzen.
 */
function festio_settings_set_current_fest(PDO $pdo, int $festId, string $festCode): void
{
    if (!festio_table_exists($pdo, 'settings')) {
        return;
    }
    $st = $pdo->prepare('REPLACE INTO settings (`k`,`v`) VALUES (?,?)');
    $st->execute(['current_fest_id', (string) $festId]);
    $st->execute(['current_fest_code', $festCode]);
}

/** Stammdaten-Tabellen komplett exportieren (global, nicht festgebunden) */
function festio_export_global_stammdaten(PDO $pdo, array &$tables): void
{
    foreach (['type', 'mitarbeiter_bereiche', 'print_targets', 'position_subcategories', 'positionen', 'beilagen'] as $t) {
        if (festio_table_exists($pdo, $t)) {
            $tables[$t] = festio_fetch_all($pdo, 'SELECT * FROM `' . festio_safe_table($t) . '`');
        }
    }
    $legacy = ['getraenke', 'getränke', 'speisen', 'artikel', 'produkte', 'preise', 'kategorien', 'warengruppen'];
    foreach ($legacy as $t) {
        if (festio_table_exists($pdo, $t)) {
            $tables[$t] = festio_fetch_all($pdo, 'SELECT * FROM `' . festio_safe_table($t) . '`');
        }
    }
}

function festio_export_sales_for_fest(PDO $pdo, int $fest_id, array &$tables): void
{
    $pk = festio_bestellungen_pk_column($pdo);
    $ids = festio_bestellung_ids_for_fest($pdo, $fest_id);

    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tables['bestellungen'] = festio_fetch_all($pdo, "SELECT * FROM bestellungen WHERE `{$pk}` IN ($in)", $ids);
    } elseif (festio_has_column($pdo, 'bestellungen', 'fest_id')) {
        $tables['bestellungen'] = festio_fetch_all($pdo, 'SELECT * FROM bestellungen WHERE fest_id=?', [$fest_id]);
    } else {
        $tables['bestellungen'] = [];
    }

    if (festio_table_exists($pdo, 'bestellung_meta') && festio_has_column($pdo, 'bestellung_meta', 'fest_id')) {
        $tables['bestellung_meta'] = festio_fetch_all($pdo, 'SELECT * FROM bestellung_meta WHERE fest_id=?', [$fest_id]);
    }

    $bidList = array_map(fn ($r) => (int) ($r[$pk] ?? 0), $tables['bestellungen'] ?? []);
    $bidList = array_values(array_unique(array_filter($bidList)));

    if ($bidList !== [] && festio_table_exists($pdo, 'print')) {
        $inb = implode(',', array_fill(0, count($bidList), '?'));
        $tables['print'] = festio_fetch_all($pdo, "SELECT * FROM print WHERE bestellungID IN ($inb)", $bidList);
    } elseif (festio_table_exists($pdo, 'print')) {
        $tables['print'] = [];
    }

    $srIds = [];
    foreach ($tables['bestellungen'] ?? [] as $b) {
        if (!empty($b['sammelrechnung_id'])) {
            $srIds[] = (int) $b['sammelrechnung_id'];
        }
    }
    $srIds = array_values(array_unique(array_filter($srIds)));
    if (festio_table_exists($pdo, 'sammelrechnungen')) {
        if (festio_has_column($pdo, 'sammelrechnungen', 'fest_id')) {
            if ($srIds !== []) {
                $inu = implode(',', array_fill(0, count($srIds), '?'));
                $params = array_merge([$fest_id], $srIds);
                $tables['sammelrechnungen'] = festio_fetch_all(
                    $pdo,
                    "SELECT * FROM sammelrechnungen WHERE fest_id = ? OR id IN ($inu)",
                    $params
                );
            } else {
                $tables['sammelrechnungen'] = festio_fetch_all($pdo, 'SELECT * FROM sammelrechnungen WHERE fest_id=?', [$fest_id]);
            }
        } elseif ($srIds !== []) {
            $inu = implode(',', array_fill(0, count($srIds), '?'));
            $tables['sammelrechnungen'] = festio_fetch_all($pdo, "SELECT * FROM sammelrechnungen WHERE id IN ($inu)", $srIds);
        } else {
            $tables['sammelrechnungen'] = [];
        }
    }

    if (festio_table_exists($pdo, 'rechnungen')) {
        if (festio_has_column($pdo, 'rechnungen', 'fest_id')) {
            $tables['rechnungen'] = festio_fetch_all($pdo, 'SELECT * FROM rechnungen WHERE fest_id=?', [$fest_id]);
        } else {
            $tables['rechnungen'] = [];
        }
    }
}

function festio_export(int $fest_id, string $mode = 'full'): array
{
  $pdo = db();
    if (!festio_table_exists($pdo, 'feste')) {
        throw new Exception("Tabelle 'feste' fehlt.");
    }
    $fest = festio_fetch_all($pdo, 'SELECT * FROM feste WHERE id=?', [$fest_id]);
    if (!$fest) {
        throw new Exception('Fest nicht gefunden.');
    }
  $fest = $fest[0];

    $isShell = ($mode === 'template');
  $tables = [];
  $tables['feste'] = [$fest];

    if (festio_table_exists($pdo, 'tische')) {
        $hasFest = festio_has_column($pdo, 'tische', 'fest_id');
        // Tische ohne fest_id (z. B. ältere Anlage über neuerTisch_save) sonst leerer Export trotz vorhandener Tische.
        $tables['tische'] = $hasFest
            ? festio_fetch_all(
                $pdo,
                'SELECT * FROM tische WHERE fest_id = ? OR fest_id IS NULL OR fest_id = 0 ORDER BY tischnummer ASC',
                [$fest_id]
            )
            : festio_fetch_all($pdo, 'SELECT * FROM tische ORDER BY tischnummer ASC');
    }

    festio_export_global_stammdaten($pdo, $tables);

    if (festio_table_exists($pdo, 'users')) {
        $tables['users'] = festio_fetch_all($pdo, 'SELECT * FROM users');
    }

    if (festio_table_exists($pdo, 'settings')) {
        $allSettings = festio_fetch_all($pdo, 'SELECT * FROM settings');
        if ($isShell) {
            $filtered = [];
            foreach ($allSettings as $s) {
                $keep = festio_shell_strip_settings_row($s);
                if ($keep !== null) {
                    $filtered[] = $keep;
                }
            }
            $tables['settings'] = $filtered;
    } else {
            $tables['settings'] = $allSettings;
        }
    }

    if (!$isShell) {
        festio_export_sales_for_fest($pdo, $fest_id, $tables);

        if (festio_table_exists($pdo, 'buchungen')) {
            $tables['buchungen'] = festio_fetch_all($pdo, 'SELECT * FROM buchungen');
        }
        if (festio_table_exists($pdo, 'mitarbeiter_verpflegung')) {
            $tables['mitarbeiter_verpflegung'] = festio_fetch_all($pdo, 'SELECT * FROM mitarbeiter_verpflegung');
        }
        if (festio_table_exists($pdo, 'menu_locks')) {
            $tables['menu_locks'] = festio_fetch_all($pdo, 'SELECT * FROM menu_locks');
        }
        if (festio_table_exists($pdo, 'menu_lock_exceptions')) {
            $tables['menu_lock_exceptions'] = festio_fetch_all($pdo, 'SELECT * FROM menu_lock_exceptions');
        }
        if (festio_table_exists($pdo, 'printer_jobs')) {
            $tables['printer_jobs'] = festio_fetch_all($pdo, 'SELECT * FROM printer_jobs');
        }
      } else {
        $tables['bestellungen'] = [];
        $tables['bestellung_meta'] = [];
        $tables['print'] = [];
        $tables['sammelrechnungen'] = [];
        $tables['rechnungen'] = [];
        $tables['buchungen'] = [];
        $tables['mitarbeiter_verpflegung'] = [];
        $tables['menu_locks'] = [];
        $tables['menu_lock_exceptions'] = [];
        $tables['printer_jobs'] = [];
  }

  return [
    'format' => 'FBS_FEST_EXPORT',
        'version' => 'A24',
    'mode' => $mode,
    'exported_at' => date('c'),
        'fest_id' => (int) $fest_id,
    'fest_code' => $fest['code'] ?? '',
    'fest_name' => $fest['name'] ?? '',
        'tables' => $tables,
    ];
}

/** Tabellen, die bei Vollimport leer sein müssen (oder force). Ohne type/users/settings/print_targets – die werden per REPLACE überschrieben. */
function festio_full_import_must_be_empty_tables(): array
{
    return [
        'bestellungen', 'bestellung_meta', 'print', 'sammelrechnungen', 'rechnungen',
        'buchungen', 'mitarbeiter_verpflegung', 'printer_jobs', 'menu_locks', 'menu_lock_exceptions',
        'feste', 'tische', 'positionen', 'position_subcategories', 'beilagen',
    ];
}

function festio_shell_import_must_be_empty_tables(): array
{
    return [
        'bestellungen', 'bestellung_meta', 'print', 'sammelrechnungen', 'rechnungen', 'printer_jobs', 'tische',
    ];
}

function festio_import_users(PDO $pdo, array $rows): void
{
    if ($rows === [] || !festio_table_exists($pdo, 'users')) {
        return;
    }
    foreach ($rows as $u) {
        festio_pdo_replace_row($pdo, 'users', $u);
    }
}

function festio_import_replace_rows(PDO $pdo, string $table, array $rows): void
{
    if ($rows === [] || !festio_table_exists($pdo, $table)) {
        return;
    }
    foreach ($rows as $row) {
        festio_pdo_replace_row($pdo, $table, $row);
    }
}

function festio_import_generic_rows(PDO $pdo, string $table, array $rows): void
{
    if ($rows === [] || !festio_table_exists($pdo, $table)) {
        return;
    }
    foreach ($rows as $row) {
        festio_pdo_insert_row($pdo, $table, $row);
    }
}

function festio_import_rows_remap_fest_id(PDO $pdo, string $table, array $rows, int $newFestId): void
{
    if ($rows === [] || !festio_table_exists($pdo, $table)) {
        return;
    }
    if (!festio_has_column($pdo, $table, 'fest_id')) {
        festio_import_generic_rows($pdo, $table, $rows);

        return;
    }
    foreach ($rows as $row) {
          $r = $row;
        $r['fest_id'] = $newFestId;
        festio_pdo_insert_row($pdo, $table, $r);
    }
}

/**
 * Vollbackup in eine laufende Datenbank: neues Fest, neue IDs (Tische, Sammelrechnungen, Rechnungen, Bestellzeilen).
 * Stammdaten (Nutzer, Settings, Speisekarte) der Live-Installation bleiben unverändert.
 * Voraussetzung: position-IDs in bestellungen passen zur aktuellen Speisekarte (gleiche DB/Export-Quelle).
 */
function festio_run_merge_archival_import(PDO $pdo, array $tables, string $importName, string $importCode): array
{
    $newFestId = festio_insert_feste_from_export($pdo, $tables['feste'][0], $importName, $importCode);

    $tischMap = [];
    if (festio_table_exists($pdo, 'tische')) {
        $hasFest = festio_has_column($pdo, 'tische', 'fest_id');
        foreach ($tables['tische'] ?? [] as $tr) {
            $oldT = (int) ($tr['tischnummer'] ?? 0);
            $r = $tr;
            unset($r['tischnummer']);
            if ($hasFest) {
                $r['fest_id'] = $newFestId;
            }
            festio_pdo_insert_row($pdo, 'tische', $r);
            $newT = (int) $pdo->lastInsertId();
            if ($oldT > 0) {
                $tischMap[$oldT] = $newT;
            }
        }
    }

    $sammelMap = [];
    if (festio_table_exists($pdo, 'sammelrechnungen')) {
        foreach ($tables['sammelrechnungen'] ?? [] as $sr) {
            $oid = (int) ($sr['id'] ?? 0);
            $r = $sr;
            unset($r['id']);
            if (festio_has_column($pdo, 'sammelrechnungen', 'fest_id')) {
                $r['fest_id'] = $newFestId;
            }
            festio_pdo_insert_row($pdo, 'sammelrechnungen', $r);
            if ($oid > 0) {
                $sammelMap[$oid] = (int) $pdo->lastInsertId();
            }
        }
    }

    $reMap = [];
    if (festio_table_exists($pdo, 'rechnungen')) {
        foreach ($tables['rechnungen'] ?? [] as $rec) {
            $oid = (int) ($rec['id'] ?? 0);
            $r = $rec;
            unset($r['id']);
            if (festio_has_column($pdo, 'rechnungen', 'fest_id')) {
                $r['fest_id'] = $newFestId;
            }
            if (!empty($r['sammelrechnung_id']) && festio_has_column($pdo, 'rechnungen', 'sammelrechnung_id')) {
                $sid = (int) $r['sammelrechnung_id'];
                $r['sammelrechnung_id'] = $sammelMap[$sid] ?? null;
            }
            festio_pdo_insert_row($pdo, 'rechnungen', $r);
            if ($oid > 0) {
                $reMap[$oid] = (int) $pdo->lastInsertId();
            }
        }
    }

    $pk = festio_bestellungen_pk_column($pdo);
    $bMap = [];
    if (festio_table_exists($pdo, 'bestellungen')) {
        foreach ($tables['bestellungen'] ?? [] as $b) {
            $oldPk = (int) ($b[$pk] ?? 0);
            $r = $b;
            unset($r[$pk]);
            if (festio_has_column($pdo, 'bestellungen', 'fest_id')) {
                $r['fest_id'] = $newFestId;
            }
            if (festio_has_column($pdo, 'bestellungen', 'tischnummer') && isset($r['tischnummer'])) {
                $tn = (int) $r['tischnummer'];
                if ($tn > 0 && !isset($tischMap[$tn])) {
                    throw new Exception("Archiv-Import: Tischnummer {$tn} aus Backup hat kein Mapping (Tische prüfen).");
                }
                if ($tn > 0) {
                    $r['tischnummer'] = $tischMap[$tn];
                }
            }
            if (array_key_exists('sammelrechnung_id', $r) && festio_has_column($pdo, 'bestellungen', 'sammelrechnung_id')) {
                $sid = (int) $r['sammelrechnung_id'];
                $r['sammelrechnung_id'] = $sid > 0 ? ($sammelMap[$sid] ?? null) : null;
            }
            if (array_key_exists('rechnung_id', $r) && festio_has_column($pdo, 'bestellungen', 'rechnung_id')) {
                $rid = (int) $r['rechnung_id'];
                $r['rechnung_id'] = $rid > 0 ? ($reMap[$rid] ?? null) : null;
            }
            festio_pdo_insert_row($pdo, 'bestellungen', $r);
            if ($oldPk > 0) {
                $bMap[$oldPk] = (int) $pdo->lastInsertId();
            }
        }
    }

    if (festio_table_exists($pdo, 'bestellung_meta')) {
        foreach ($tables['bestellung_meta'] ?? [] as $m) {
            $oldBid = (int) ($m['bestellung_id'] ?? 0);
            if ($oldBid <= 0 || empty($bMap[$oldBid])) {
                continue;
            }
            $m2 = $m;
            $m2['bestellung_id'] = $bMap[$oldBid];
            if (festio_has_column($pdo, 'bestellung_meta', 'fest_id')) {
                $m2['fest_id'] = $newFestId;
            }
            festio_pdo_insert_row($pdo, 'bestellung_meta', $m2);
        }
    }

    if (festio_table_exists($pdo, 'print')) {
        $printPk = 'rowid';
        if (!festio_has_column($pdo, 'print', 'rowid') && festio_has_column($pdo, 'print', 'id')) {
            $printPk = 'id';
        }
        foreach ($tables['print'] ?? [] as $p) {
            $p2 = $p;
            unset($p2[$printPk]);
            $oldBid = (int) ($p2['bestellungID'] ?? 0);
            if ($oldBid <= 0 || !isset($bMap[$oldBid])) {
                continue;
            }
            $p2['bestellungID'] = $bMap[$oldBid];
            festio_pdo_insert_row($pdo, 'print', $p2);
        }
    }

    festio_import_generic_rows($pdo, 'buchungen', $tables['buchungen'] ?? []);
    festio_import_generic_rows($pdo, 'mitarbeiter_verpflegung', $tables['mitarbeiter_verpflegung'] ?? []);

    return [
        'ok' => true,
        'new_fest_id' => $newFestId,
        'mode' => 'merge_archival',
        'menu_imported' => false,
        'menu_skip_reason' => 'Archiv-Import: Speisekarte/Nutzer/Settings der Live-Installation unverändert.',
        'rows_bestellungen' => count($bMap),
    ];
}

function festio_import(array $payload, string $mode = 'template', array $opts = []): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $menuInfo = ['menu_imported' => false, 'menu_skip_reason' => null];

    try {
        if (($payload['format'] ?? '') !== 'FBS_FEST_EXPORT') {
            throw new Exception('Unbekanntes Export-Format.');
        }
        $tables = $payload['tables'] ?? [];
        if (!isset($tables['feste'][0])) {
            throw new Exception('Fest-Daten fehlen.');
        }

        $import_as_name = trim((string) ($opts['name'] ?? ''));
        $import_as_code = trim((string) ($opts['code'] ?? ''));
        $force = (int) ($opts['force'] ?? 0) === 1;
        // Hinweis: Benutzer werden ab sofort NICHT mehr standardmäßig aus der Hülle importiert,
        // damit lokale Admins/Mitarbeiter (Username, Passwort, Rechte) durch Import nicht überschrieben werden.
        // Eigener Endpunkt: users_export.php / users_import.php.
        $importUsers = (int) ($opts['import_users'] ?? 0) === 1;

        if ($mode === 'template') {
            foreach (festio_shell_import_must_be_empty_tables() as $t) {
                if (!festio_table_exists($pdo, $t)) {
                    continue;
                }
                $c = festio_table_count($pdo, $t);
                if ($c > 0 && !$force) {
                    throw new Exception("Hülle-Import: Tabelle '$t' ist nicht leer ($c Zeilen). Bitte leeren (z. B. Bestellungen) oder „force“ nutzen.");
                }
            }

            $new_fest_id = festio_insert_feste_from_export($pdo, $tables['feste'][0], $import_as_name, $import_as_code);

            festio_import_replace_rows($pdo, 'type', $tables['type'] ?? []);
            festio_import_replace_rows($pdo, 'mitarbeiter_bereiche', $tables['mitarbeiter_bereiche'] ?? []);

            festio_import_settings_rows($pdo, $tables['settings'] ?? []);
            if ($importUsers) {
                festio_import_users($pdo, $tables['users'] ?? []);
            }

            $menuInfo = festio_import_menu_bundle($pdo, $tables, true, $new_fest_id);
            festio_import_tische_for_fest($pdo, $tables['tische'] ?? [], $new_fest_id);

            foreach (['getraenke', 'getränke', 'speisen', 'artikel', 'produkte', 'preise', 'kategorien', 'warengruppen'] as $lt) {
                if (!empty($tables[$lt])) {
                    festio_import_generic_rows($pdo, $lt, $tables[$lt]);
                }
            }

            $codeForSetting = $import_as_code !== '' ? $import_as_code : (string) ($tables['feste'][0]['code'] ?? '');
            festio_settings_set_current_fest($pdo, $new_fest_id, $codeForSetting);

            $pdo->commit();

            return [
                'ok' => true,
                'new_fest_id' => $new_fest_id,
                'mode' => 'template',
                'menu_imported' => $menuInfo['menu_imported'],
                'menu_skip_reason' => $menuInfo['menu_skip_reason'],
            ];
        }

        if ($mode === 'merge_archival') {
            if (empty($tables['bestellungen'])) {
                throw new Exception('Archiv-Import: Export enthält keine Bestellungen (kein Vollbackup?).');
            }
            $out = festio_run_merge_archival_import($pdo, $tables, $import_as_name, $import_as_code);
            $pdo->commit();

            return $out;
        }

        if ($mode === 'full') {
            foreach (festio_full_import_must_be_empty_tables() as $t) {
                if (!festio_table_exists($pdo, $t)) {
                    continue;
                }
                $c = festio_table_count($pdo, $t);
                if ($c > 0 && !$force) {
                    throw new Exception("Vollbackup-Import: Tabelle '$t' ist nicht leer ($c Zeilen). Nur in leere Datenbank oder mit force=1.");
                }
            }

            $new_fest_id = festio_insert_feste_from_export($pdo, $tables['feste'][0], $import_as_name, $import_as_code);

            festio_import_replace_rows($pdo, 'type', $tables['type'] ?? []);
            festio_import_replace_rows($pdo, 'mitarbeiter_bereiche', $tables['mitarbeiter_bereiche'] ?? []);

            festio_import_settings_rows($pdo, $tables['settings'] ?? []);
            if ($importUsers) {
                festio_import_users($pdo, $tables['users'] ?? []);
            }

            $menuInfo = festio_import_menu_bundle($pdo, $tables, true, $new_fest_id);
            festio_import_tische_for_fest($pdo, $tables['tische'] ?? [], $new_fest_id);

            foreach (['getraenke', 'getränke', 'speisen', 'artikel', 'produkte', 'preise', 'kategorien', 'warengruppen'] as $lt) {
                if (!empty($tables[$lt])) {
                    festio_import_generic_rows($pdo, $lt, $tables[$lt]);
                }
            }

            festio_import_rows_remap_fest_id($pdo, 'sammelrechnungen', $tables['sammelrechnungen'] ?? [], $new_fest_id);
            festio_import_rows_remap_fest_id($pdo, 'rechnungen', $tables['rechnungen'] ?? [], $new_fest_id);
            festio_import_rows_remap_fest_id($pdo, 'bestellungen', $tables['bestellungen'] ?? [], $new_fest_id);

            if (isset($tables['bestellung_meta']) && festio_table_exists($pdo, 'bestellung_meta')) {
                foreach ($tables['bestellung_meta'] as $m) {
                    $m['fest_id'] = $new_fest_id;
                    festio_pdo_insert_row($pdo, 'bestellung_meta', $m);
                }
            }

            festio_import_generic_rows($pdo, 'print', $tables['print'] ?? []);
            festio_import_generic_rows($pdo, 'buchungen', $tables['buchungen'] ?? []);
            festio_import_generic_rows($pdo, 'mitarbeiter_verpflegung', $tables['mitarbeiter_verpflegung'] ?? []);
            festio_import_generic_rows($pdo, 'menu_locks', $tables['menu_locks'] ?? []);
            festio_import_generic_rows($pdo, 'menu_lock_exceptions', $tables['menu_lock_exceptions'] ?? []);
            festio_import_generic_rows($pdo, 'printer_jobs', $tables['printer_jobs'] ?? []);

            $codeForSetting = $import_as_code !== '' ? $import_as_code : (string) ($tables['feste'][0]['code'] ?? '');
            festio_settings_set_current_fest($pdo, $new_fest_id, $codeForSetting);

      $pdo->commit();

            return [
                'ok' => true,
                'new_fest_id' => $new_fest_id,
                'mode' => 'full',
                'menu_imported' => $menuInfo['menu_imported'],
                'menu_skip_reason' => $menuInfo['menu_skip_reason'],
            ];
    }

    throw new Exception('Unbekannter Import-Modus.');
    } catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
  }
}
