<?php
/**
 * Schema Unterkategorien + Kachelfarben.
 * Nur extrem gesättigtes Rot/Orange blockieren (Ausverkauft / wenig Bestand); helle Pastelltöne sind erlaubt.
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_schema_login.php';

function ff_menu_ensure_schema(mysqli $conn): void
{
    if (ff_schema_session_ok()) {
        return;
    }
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `position_subcategories` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `type` TINYINT(4) NOT NULL COMMENT '1=Speise 2=Getränk',
      `name` VARCHAR(128) NOT NULL,
      `sort_order` INT(11) NOT NULL DEFAULT 0,
      `tile_bg` VARCHAR(32) NULL DEFAULT NULL,
      `kassa_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=nur Kasse',
      PRIMARY KEY (`id`),
      KEY `idx_type_sort` (`type`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `positionen` LIKE 'subcategory_id'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, "ALTER TABLE `positionen` ADD COLUMN `subcategory_id` INT(11) NULL DEFAULT NULL");
    }
    $chk2 = @mysqli_query($conn, "SHOW COLUMNS FROM `positionen` LIKE 'tile_bg'");
    if ($chk2 && mysqli_num_rows($chk2) === 0) {
        @mysqli_query($conn, "ALTER TABLE `positionen` ADD COLUMN `tile_bg` VARCHAR(32) NULL DEFAULT NULL");
    }
    $chkSk = @mysqli_query($conn, "SHOW COLUMNS FROM `positionen` LIKE 'selbstkosten'");
    if ($chkSk && mysqli_num_rows($chkSk) === 0) {
        @mysqli_query($conn, "ALTER TABLE `positionen` ADD COLUMN `selbstkosten` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'EK pro Stück'");
    }
    $kassaHelpers = __DIR__ . '/ff_position_kassa_helpers.php';
    if (is_readable($kassaHelpers)) {
        require_once $kassaHelpers;
        ff_position_kassa_ensure_schema($conn);
    }
}

/**
 * Kachel-Hintergrund: #RRGGBB; blockiert nur knallige Warn-Rot/Orange-Töne, erlaubt helle Varianten.
 */
function ff_sanitize_category_tile_bg(?string $hex): ?string
{
    if ($hex === null || $hex === '') {
        return null;
    }
    $h = strtoupper(trim($hex));
    if ($h[0] !== '#') {
        $h = '#' . $h;
    }
    if (!preg_match('/^#[0-9A-F]{6}$/', $h)) {
        return null;
    }
    $r = hexdec(substr($h, 1, 2));
    $g = hexdec(substr($h, 3, 2));
    $b = hexdec(substr($h, 5, 2));
    $avg = ($r + $g + $b) / 3;
    $mx = max($r, $g, $b);
    $mn = min($r, $g, $b);
    $spread = $mx - $mn;

    // Sehr helle Pastelltöne (inkl. zartes Rot/Orange) immer erlauben
    if ($avg >= 198 && $spread <= 140) {
        return $h;
    }

    // Extrem gesättigtes Rot (z. B. reines Warnrot)
    if ($r >= 235 && $g < 95 && $b < 95 && ($r - max($g, $b)) >= 110) {
        return null;
    }

    // Extremes Orange (knallig, typisch Warn-/Aktionston)
    if ($r >= 225 && $g >= 85 && $g <= 165 && $b < 85 && $r > $g && ($g - $b) >= 45) {
        return null;
    }

    return $h;
}

function ff_menu_palette_bg(int $index): string
{
    $palette = ['#E8EEF6', '#E3F2E9', '#EDE7F6', '#E0F2F1', '#E3F2FD', '#F3E5F5'];
    return $palette[$index % count($palette)];
}

function ff_menu_font_color_row(array $rowww, string $default = '#0f172a'): string
{
    $c = trim((string)($rowww['color'] ?? ''));
    if ($c === '') {
        return $default;
    }
    if (isset($c[0]) && $c[0] === '#') {
        return strlen($c) >= 4 ? $c : $default;
    }

    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $c);
    return in_array(strlen($hex), [3, 6], true) ? '#' . $hex : $default;
}

/** Karten-Spalten aus Einstellung (2–6), Desktop / Tablet. */
function ff_menu_column_count(mysqli $conn): int
{
    require_once __DIR__ . '/settings.php';
    $n = (int)setting_get($conn, 'karte_spalten', '3');
    if ($n < 2) {
        $n = 2;
    }
    if ($n > 6) {
        $n = 6;
    }
    return $n;
}

/** Karten-Spalten auf schmalen Viewports (2–3), separat einstellbar. */
function ff_menu_column_count_mobile(mysqli $conn): int
{
    require_once __DIR__ . '/settings.php';
    $n = (int)setting_get($conn, 'karte_spalten_mobil', '3');
    if ($n < 2) {
        $n = 2;
    }
    if ($n > 3) {
        $n = 3;
    }
    return $n;
}

/** Tisch-Raster: max. Spalten pro Zeile im Koordinatenlayout (3–8). */
function ff_tisch_grid_max_x(mysqli $conn): int
{
    require_once __DIR__ . '/settings.php';
    $n = (int)setting_get($conn, 'tisch_raster_spalten', '5');
    if ($n < 3) {
        $n = 3;
    }
    if ($n > 8) {
        $n = 8;
    }
    return $n;
}

/** Tisch-Raster mobil (3–5), separat von Desktop. */
function ff_tisch_grid_max_x_mobile(mysqli $conn): int
{
    require_once __DIR__ . '/settings.php';
    $n = (int)setting_get($conn, 'tisch_raster_spalten_mobil', '5');
    if ($n < 3) {
        $n = 3;
    }
    if ($n > 5) {
        $n = 5;
    }
    return $n;
}

/**
 * Kachel-Hintergrund: zuerst Positionsfarbe, dann Unterkategorie, sonst weiß.
 */
function ff_menu_resolve_base_bg(array $rowww, ?string $subTileBg): string
{
    $fromPos = ff_sanitize_category_tile_bg($rowww['tile_bg'] ?? null);
    if ($fromPos !== null) {
        return $fromPos;
    }
    $fromSub = ff_sanitize_category_tile_bg($subTileBg);
    if ($fromSub !== null) {
        return $fromSub;
    }
    // Ohne Positions- und ohne Kategoriefarbe: weiße Kachel (wie früher)
    return '#ffffff';
}


/** Trennt eine führende Mengenangabe (z. B. 0.5L, 1/4, 0,33L) vom Rest des Positionsnamens ab. */
function ff_menu_split_leading_quantity(string &$rest): string
{
    if ($rest !== '' && preg_match('/^(\d+(?:[.,]\d+)?(?:\/\d+)?\s?(?:ml|cl|dl|l)?)(?=\s|$)/iu', $rest, $m)) {
        $rest = trim(substr($rest, strlen($m[1])));
        return trim($m[1]);
    }
    return '';
}


/** CSS-Variablen für den Kategorie-Rahmen aus der hinterlegten Unterkategorie-Farbe (leer, wenn ungültig). */
function ff_menu_subcat_color_style($hex): string
{
    $hex = trim((string) $hex);
    if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex)) {
        return '';
    }
    $h = substr($hex, 1);
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    $lum = (0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2))) / 255;
    $text = $lum > 0.6 ? '#111827' : '#ffffff';
    return '--sub-color:#' . $h . ';--sub-text:' . $text . ';';
}
