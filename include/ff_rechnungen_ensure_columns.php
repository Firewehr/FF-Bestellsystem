<?php
/**
 * Fehlende Spalten an rechnungen anlegen (ohne Duplicate-Column-Fehler bei mysqli strict mode).
 */
function ff_bestellungen_ensure_rechnung_id_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'rechnung_id'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, 'ALTER TABLE bestellungen ADD COLUMN rechnung_id INT NULL');
    }
}

function ff_rechnungen_ensure_extra_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $ensure = static function (mysqli $conn, string $column, string $alterSql): void {
        $esc = mysqli_real_escape_string($conn, $column);
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM rechnungen LIKE '{$esc}'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, $alterSql);
        }
    };

    $ensure($conn, 'order_nr', 'ALTER TABLE rechnungen ADD COLUMN order_nr INT NULL');
    $ensure($conn, 'sammelrechnung_id', 'ALTER TABLE rechnungen ADD COLUMN sammelrechnung_id INT NULL');
    $ensure($conn, 'is_proforma', 'ALTER TABLE rechnungen ADD COLUMN is_proforma TINYINT(1) NOT NULL DEFAULT 0');
    $ensure($conn, 'lines_json', 'ALTER TABLE rechnungen ADD COLUMN lines_json MEDIUMTEXT NULL');
    $ensure($conn, 'storniert', 'ALTER TABLE rechnungen ADD COLUMN storniert TINYINT(1) NOT NULL DEFAULT 0');
    $ensure($conn, 'storno_at', 'ALTER TABLE rechnungen ADD COLUMN storno_at DATETIME NULL');
    $ensure($conn, 'storno_by', 'ALTER TABLE rechnungen ADD COLUMN storno_by VARCHAR(64) NULL');
    $ensure($conn, 'storno_reason', 'ALTER TABLE rechnungen ADD COLUMN storno_reason VARCHAR(255) NULL');

    ff_rechnungen_ensure_unique_rechnungsnummer($conn);

    // Alle Spalten an rechnungen prüfen: Jede Spalte ohne DEFAULT und NOT NULL (außer PK/Auto-Increment)
    // auf NULL DEFAULT korrigieren, damit MySQL Strict Mode bei INSERTs (z. B. kellner, betrag) niemals abbricht.
    $resCols = @mysqli_query($conn, "SHOW COLUMNS FROM rechnungen");
    if ($resCols) {
        while ($col = mysqli_fetch_assoc($resCols)) {
            $fieldName = (string)($col['Field'] ?? '');
            $isNullable = (strcasecmp((string)($col['Null'] ?? ''), 'YES') === 0);
            $hasDefault = ($col['Default'] !== null);
            $isAutoInc = (stripos((string)($col['Extra'] ?? ''), 'auto_increment') !== false);
            $isKey = (strcasecmp((string)($col['Key'] ?? ''), 'PRI') === 0);

            if (!$isNullable && !$hasDefault && !$isAutoInc && !$isKey) {
                $type = (string)($col['Type'] ?? 'varchar(255)');
                $defVal = (stripos($type, 'int') !== false || stripos($type, 'decimal') !== false || stripos($type, 'float') !== false || stripos($type, 'double') !== false) ? '0' : "''";
                @mysqli_query($conn, "ALTER TABLE rechnungen MODIFY COLUMN `{$fieldName}` {$type} NULL DEFAULT {$defVal}");
            }
        }
        mysqli_free_result($resCols);
    }
}

function ff_rechnungen_ensure_unique_rechnungsnummer(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Prüfen, ob bereits ein UNIQUE-Index auf rechnungsnummer existiert
    $res = @mysqli_query($conn, "SHOW INDEX FROM rechnungen WHERE Column_name = 'rechnungsnummer' AND Non_unique = 0");
    if ($res && mysqli_num_rows($res) > 0) {
        return;
    }

    // Prüfen, ob Duplikate vorliegen, bevor UNIQUE hinzugefügt wird
    $dupChk = @mysqli_query($conn, "SELECT rechnungsnummer, COUNT(*) AS c FROM rechnungen WHERE rechnungsnummer IS NOT NULL AND rechnungsnummer != '' GROUP BY rechnungsnummer HAVING c > 1");
    if ($dupChk && mysqli_num_rows($dupChk) === 0) {
        @mysqli_query($conn, "ALTER TABLE rechnungen ADD UNIQUE INDEX uq_rechnungsnummer (rechnungsnummer)");
    }
}

function ff_bestellungen_ensure_is_gratis_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'is_gratis'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, 'ALTER TABLE bestellungen ADD COLUMN is_gratis TINYINT(1) NOT NULL DEFAULT 0');
    }
}

function ff_rechnungen_ensure_print_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $ensure = static function (mysqli $conn, string $column, string $alterSql): void {
        $esc = mysqli_real_escape_string($conn, $column);
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM rechnungen LIKE '{$esc}'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, $alterSql);
        }
    };

    $ensure($conn, 'druck_status', "ALTER TABLE rechnungen ADD COLUMN druck_status VARCHAR(10) NOT NULL DEFAULT 'pending'");
    $ensure($conn, 'druck_attempts', 'ALTER TABLE rechnungen ADD COLUMN druck_attempts INT NOT NULL DEFAULT 0');
    $ensure($conn, 'druck_last_error', 'ALTER TABLE rechnungen ADD COLUMN druck_last_error VARCHAR(255) NULL');
    $ensure($conn, 'reserved_at', 'ALTER TABLE rechnungen ADD COLUMN reserved_at TIMESTAMP NULL');
    $ensure($conn, 'reserved_by', 'ALTER TABLE rechnungen ADD COLUMN reserved_by VARCHAR(64) NULL');
}

/**
 * Bezahlte Positionen am Tisch, die noch keiner Rechnung zugeordnet sind (Basis „Bereits bezahlt“).
 */
function ff_tisch_count_paid_without_rechnung(mysqli $conn, int $tischnummer): int
{
    if ($tischnummer <= 0) {
        return 0;
    }
    ff_bestellungen_ensure_rechnung_id_column($conn);
    ff_bestellungen_ensure_is_gratis_column($conn);

    $sql = 'SELECT COUNT(*) AS c FROM bestellungen b WHERE b.`delete`=0 AND b.tischnummer=' . (int) $tischnummer
        . " AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>'0000-00-00 00:00:00'"
        . ' AND (b.is_gratis IS NULL OR b.is_gratis=0)'
        . ' AND (b.rechnung_id IS NULL OR b.rechnung_id=0)';
    $res = @mysqli_query($conn, $sql);
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        return 0;
    }

    return (int) ($row['c'] ?? 0);
}

function ff_rechnung_nachtraeglich_button_html(int $tischnummer, int $positionCount, string $btnClass = 'btn btn-info btn-sm'): string
{
    if ($tischnummer <= 0 || $positionCount <= 0) {
        return '';
    }
    $label = 'Nachträgliche Rechnung (' . (int) $positionCount . ')';
    $t = (int) $tischnummer;
    $cls = htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8');
    $lbl = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return '<button type="button" class="' . $cls . '" onclick="if(typeof window.TischRechnungNachtraeglich===\'function\'){window.TischRechnungNachtraeglich(' . $t . ');} return false;">'
        . '📄 ' . $lbl . '</button>';
}
