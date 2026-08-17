<?php
/**
 * SQL-Ausdruck für eine „Bestellungsrunde“ am Tisch:
 * - Direktverkauf: pro Bon
 * - Nach Abschicken: order_nr (bzw. gemeinsamer timestampBestellung)
 * - Noch nicht abgeschickt (bestellt=0): alle offenen Zeilen desselben Tisches = eine Runde
 *   (sonst würde jeder Klick mit eigenem timestampBestellung eine eigene Nr. erzeugen)
 *
 * @param string $alias Tabellenname oder Alias der bestellungen-Tabelle
 */
function ff_sql_bestellung_batch_key(string $alias = 'bestellungen'): string
{
    $b = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($b === '') {
        $b = 'bestellungen';
    }

    // Einheitliche Collation: sonst „Illegal mix of collations (utf8mb4_bin …) and (utf8mb4_general_ci …)“
    // z. B. wenn bon_id utf8mb4_bin ist und mit Literalen/CONCAT verglichen wird.
    $coll = 'utf8mb4_unicode_ci';

    return '(CASE '
        // Direktverkauf (999999): immer pro Bon trennen.
        . "WHEN {$b}.tischnummer = 999999 AND {$b}.bon_id IS NOT NULL AND CHAR_LENGTH(TRIM({$b}.bon_id)) > 0 "
        . "THEN (CONCAT('DV_', TRIM(CAST({$b}.bon_id AS CHAR CHARACTER SET utf8mb4))) COLLATE {$coll}) "
        // Wenn eine Bestellnummer existiert, ist sie die fachlich korrekte Gruppierung.
        . "WHEN {$b}.order_nr IS NOT NULL AND {$b}.order_nr > 0 "
        . "THEN (CONCAT('ORD_', CAST({$b}.order_nr AS CHAR CHARACTER SET utf8mb4)) COLLATE {$coll}) "
        // Noch nicht abgeschickt: eine offene Runde pro Tisch (wird beim Abschicken zu ORD_/TS_).
        . "WHEN {$b}.tischnummer <> 999999 AND ({$b}.bestellt IS NULL OR {$b}.bestellt = 0) "
        . "THEN (CONCAT('OPEN_', CAST({$b}.tischnummer AS CHAR CHARACTER SET utf8mb4)) COLLATE {$coll}) "
        . "WHEN {$b}.timestampBestellung IS NULL OR {$b}.timestampBestellung IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00') "
        . "THEN (CONCAT('LEG_', CAST({$b}.tischnummer AS CHAR CHARACTER SET utf8mb4), '_', FLOOR(UNIX_TIMESTAMP({$b}.zeitstempel) / 300)) COLLATE {$coll}) "
        . "ELSE (CONCAT('TS_', UNIX_TIMESTAMP({$b}.timestampBestellung)) COLLATE {$coll}) "
        . 'END)';
}
