<?php
/**
 * Aufpreis aus vordefinierten Beilagen anhand der gespeicherten Zusatzinfo-Zeichenkette.
 * Format wie im Hinweis-Dialog: "Preset1, Preset2 | Freitext" — nur der linke Teil (kommagetrennt) zählt.
 */
declare(strict_types=1);

function ff_beilagen_surcharge_for_hint(mysqli $conn, int $positionId, string $hinweis): float
{
    if ($positionId <= 0) {
        return 0.0;
    }
    $hinweis = trim($hinweis);
    if ($hinweis === '') {
        return 0.0;
    }
    $left = $hinweis;
    $sep = strpos($hinweis, ' | ');
    if ($sep !== false) {
        $left = trim(substr($hinweis, 0, $sep));
    }
    if ($left === '') {
        return 0.0;
    }
    $parts = array_map('trim', explode(',', $left));
    $parts = array_values(array_filter($parts, static function ($x) {
        return $x !== '';
    }));
    if ($parts === []) {
        return 0.0;
    }

    $stmt = mysqli_prepare($conn, 'SELECT name, betrag FROM beilagen WHERE position = ?');
    if (!$stmt) {
        return 0.0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $positionId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $bname, $bbetrag);
    $map = [];
    while (mysqli_stmt_fetch($stmt)) {
        $nm = trim((string)$bname);
        if ($nm !== '') {
            $map[$nm] = (float)$bbetrag;
        }
    }
    mysqli_stmt_close($stmt);

    $sum = 0.0;
    foreach ($parts as $p) {
        if (isset($map[$p])) {
            $sum += $map[$p];
        }
    }

    return $sum;
}

function ff_bestellung_line_betrag(mysqli $conn, int $positionId, float $baseCatalogBetrag, string $hinweis): float
{
    $s = ff_beilagen_surcharge_for_hint($conn, $positionId, $hinweis);

    return round($baseCatalogBetrag + $s, 2);
}

/**
 * Vordefinierte Zusatzpositionen (Beilagen) einer Speisekarten-Position.
 *
 * @return list<array{name: string, betrag: float}>
 */
function ff_beilagen_list_for_position(mysqli $conn, int $positionId): array
{
    if ($positionId <= 0) {
        return [];
    }
    static $cache = [];
    if (isset($cache[$positionId])) {
        return $cache[$positionId];
    }
    $out = [];
    $stmt = mysqli_prepare($conn, 'SELECT name, betrag FROM beilagen WHERE position = ? ORDER BY name ASC');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $positionId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $out[] = ['name' => $name, 'betrag' => (float) ($row['betrag'] ?? 0)];
            }
        }
        mysqli_stmt_close($stmt);
    }
    $cache[$positionId] = $out;

    return $out;
}

/** HTML: Zusatzpositionen als Badges + optional Freitext (wie Hinweis-Dialog / Tischkarte). */
function ff_zusatzinfo_display_html(mysqli $conn, int $positionId, string $zusatzinfo): string
{
    $zusatzinfo = trim($zusatzinfo);
    if ($zusatzinfo === '') {
        return '';
    }

    $presets = ff_beilagen_list_for_position($conn, $positionId);
    $presetNames = array_map(static function (array $p): string {
        return $p['name'];
    }, $presets);
    $nameToBetrag = [];
    foreach ($presets as $p) {
        $nameToBetrag[$p['name']] = $p['betrag'];
    }

    if ($presets === []) {
        return '<div class="ff-zusatzinfo-display small mt-1 text-muted fst-italic">→ '
            . htmlspecialchars($zusatzinfo, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $left = $zusatzinfo;
    $sep = strpos($zusatzinfo, ' | ');
    if ($sep !== false) {
        $left = trim(substr($zusatzinfo, 0, $sep));
    }

    $picked = [];
    if ($left !== '') {
        foreach (array_map('trim', explode(',', $left)) as $part) {
            if ($part !== '' && isset($nameToBetrag[$part])) {
                $picked[] = $part;
            }
        }
    }

    require_once __DIR__ . '/beilage_freetext_extract.php';
    $free = ff_beilage_extract_freetext_from_zusatzinfo($zusatzinfo, $presetNames);

    if ($picked === [] && $free === '') {
        return '<div class="ff-zusatzinfo-display small mt-1 text-muted fst-italic">→ '
            . htmlspecialchars($zusatzinfo, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $html = '<div class="ff-zusatzinfo-display small mt-1 d-flex flex-wrap align-items-center gap-1">';
    foreach ($picked as $nm) {
        $bet = (float) ($nameToBetrag[$nm] ?? 0);
        $lbl = htmlspecialchars($nm, ENT_QUOTES, 'UTF-8');
        if ($bet > 0.001) {
            $lbl .= ' +' . number_format($bet, 2, ',', '.') . ' €';
        }
        $html .= '<span class="badge bg-light text-dark border">' . $lbl . '</span>';
    }
    if ($free !== '') {
        $html .= '<span class="text-muted fst-italic">' . htmlspecialchars($free, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</div>';

    return $html;
}
