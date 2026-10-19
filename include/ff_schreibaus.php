<?php
/**
 * „Schreibaus“: unbezahlte, fachlich abgeschlossene Zeilen ohne DELETE schließen (timestampBezahlung + Flag).
 */

/** @return void */
function ff_schreibaus_ensure_column(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (function_exists('ff_schema_flag_is_set') && ff_schema_flag_is_set('bestellungen_schreibaus')) {
        return;
    }

    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'schreibaus'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        mysqli_free_result($chk);
    } else {
        if ($chk) {
            mysqli_free_result($chk);
        }
        @mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN schreibaus TINYINT(1) NOT NULL DEFAULT 0");
    }

    if (function_exists('ff_schema_flag_set')) {
        ff_schema_flag_set('bestellungen_schreibaus');
    }
}

/**
 * @return string 'after'|'instant'
 */
function ff_aktiver_payment_mode(mysqli $conn): string {
    $res = @mysqli_query($conn, "SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $m = (string)($row['payment_mode'] ?? 'after');
        return $m === 'instant' ? 'instant' : 'after';
    }
    return 'after';
}

/**
 * Bedingung für „noch zu zahlende, aber fachlich fertige“ Zeilen je Modus.
 */
function ff_schreibaus_open_sql_condition(string $paymentMode): string {
    $unpaid = "(b.timestampBezahlung IS NULL OR b.timestampBezahlung='0000-00-00 00:00:00')";
    $base = "b.`delete`=0 AND IFNULL(b.schreibaus,0)=0 AND {$unpaid} AND IFNULL(b.is_gratis,0)=0";
    if ($paymentMode === 'instant') {
        return $base . " AND b.bestellt=1";
    }
    return $base . " AND b.kueche=1";
}

/** Ist der Tisch als Ehrengast markiert? */
function ff_tisch_is_ehrengast(mysqli $conn, int $tischnummer): bool
{
    if ($tischnummer <= 0) {
        return false;
    }
    $tres = @mysqli_query($conn, 'SELECT IFNULL(is_ehrengast,0) AS is_ehrengast FROM tische WHERE tischnummer=' . (int) $tischnummer . ' LIMIT 1');
    if ($tres && ($tr = mysqli_fetch_assoc($tres))) {
        return (int) ($tr['is_ehrengast'] ?? 0) === 1;
    }

    return false;
}

/**
 * Offene Positionen für die Bezahl-/Ehrengast-Ansicht.
 * Ehrengast: alle unbezahlten Zeilen (ohne is_gratis-/Küche-Filter), damit „Tisch abschließen“ möglich ist.
 */
function ff_tisch_open_pay_sql_condition(mysqli $conn, int $tischnummer, string $paymentMode): string
{
    $unpaid = "(b.timestampBezahlung IS NULL OR b.timestampBezahlung='0000-00-00 00:00:00')";
    if (ff_tisch_is_ehrengast($conn, $tischnummer)) {
        return "b.`delete`=0 AND IFNULL(b.schreibaus,0)=0 AND {$unpaid}";
    }

    return ff_schreibaus_open_sql_condition($paymentMode);
}

/**
 * Prüft, ob SumUp konfiguriert / aktiviert ist.
 */
function ff_sumup_is_configured(mysqli $conn): bool {
    require_once __DIR__ . '/settings.php';

    $affiliate = trim((string)setting_get($conn, 'sumup_affiliate_key', ''));
    if ($affiliate !== '') {
        return true;
    }
    if ((string)setting_get($conn, 'sumup_open_app_button', '0') === '1') {
        return true;
    }
    if ((string)setting_get($conn, 'sumup_enabled', '0') === '1') {
        return true;
    }
    if (trim((string)setting_get($conn, 'sumup_merchant_code', '')) !== '') {
        return true;
    }
    if (trim((string)setting_get($conn, 'sumup_api_key', '')) !== '') {
        return true;
    }

    $envAffiliate = trim((string)(getenv('SUMUP_AFFILIATE_KEY') ?: ''));
    if ($envAffiliate !== '') {
        return true;
    }
    $envMerchant = trim((string)(getenv('SUMUP_MERCHANT_CODE') ?: ''));
    if ($envMerchant !== '') {
        return true;
    }
    $envKey = trim((string)(getenv('SUMUP_API_KEY') ?: ''));
    if ($envKey !== '') {
        return true;
    }

    return false;
}

/**
 * Erzeugt den gastseitigen Bezahlhinweis abhängig von payment_mode und SumUp-Konfiguration.
 */
function ff_customer_payment_notice_html(mysqli $conn): string {
    $mode = ff_aktiver_payment_mode($conn);
    $hasSumup = ff_sumup_is_configured($conn);

    if ($mode === 'instant') {
        if ($hasSumup) {
            $mainText = 'Bitte bezahlen Sie direkt bei Erhalt der Bestellung bei der Bedienung (bar oder mit Bankomatkarte).';
        } else {
            $mainText = 'Bitte bezahlen Sie direkt bei Erhalt der Bestellung bei der Bedienung in bar.';
        }
    } else {
        if ($hasSumup) {
            $mainText = 'Bezahlt wird wie gewohnt bei der Bedienung am Tisch (bar oder mit Bankomatkarte).';
        } else {
            $mainText = 'Bezahlt wird wie gewohnt bei der Bedienung am Tisch.';
        }
    }

    $safeMain = htmlspecialchars($mainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTip = '<span style="display:block; margin-top:4px; font-size:13px; color:#666;">💡 <em>Tipp: Bitte halten Sie nach Möglichkeit passendes Bargeld bereit.</em></span>';

    return $safeMain . ' ' . $safeTip;
}

/**
 * Einheitliche Formatierung des Tischnamens für Gäste (stellt sicher, dass immer "Tisch " vorangestellt ist).
 */
function ff_format_table_label(?string $tischname, $tischnummer): string {
    $raw = trim((string)($tischname ?? ''));
    if ($raw === '') {
        $raw = (string)$tischnummer;
    }

    if (preg_match('/^tisch\s*(.+)$/i', $raw, $m)) {
        return 'Tisch ' . trim($m[1]);
    }
    if (strcasecmp($raw, 'tisch') === 0) {
        return 'Tisch ' . $tischnummer;
    }
    return 'Tisch ' . $raw;
}

