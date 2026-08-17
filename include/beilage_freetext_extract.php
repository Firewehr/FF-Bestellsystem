<?php
/**
 * Extrahiert den Freitext-Anteil aus gespeicherter Zusatzinfo (analog zu ffParseHinweisToFreeAndChecks in app.js).
 *
 * @param string $zusatzinfo Rohwert aus bestellungen.Zusatzinfo
 * @param list<string> $presetNames Namen der vordefinierten Beilagen für diese Position
 */
function ff_beilage_extract_freetext_from_zusatzinfo(string $zusatzinfo, array $presetNames): string
{
    $s = trim($zusatzinfo);
    if ($s === '') {
        return '';
    }
    $nameSet = [];
    foreach ($presetNames as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $nameSet[$n] = true;
        }
    }
    $left = $s;
    $free = '';
    $sep = strpos($s, ' | ');
    if ($sep !== false) {
        $left = trim(substr($s, 0, $sep));
        $free = trim(substr($s, $sep + 3));
    }
    if ($left !== '' && $nameSet !== []) {
        $parts = array_filter(array_map('trim', explode(',', $left)), static function ($p) {
            return $p !== '';
        });
        $unmatched = [];
        foreach ($parts as $p) {
            if (!isset($nameSet[$p])) {
                $unmatched[] = $p;
            }
        }
        if ($unmatched !== []) {
            $extra = implode(', ', $unmatched);
            $free = $free !== '' ? ($extra . ', ' . $free) : $extra;
        }
    } elseif ($left !== '' && $nameSet === []) {
        $free = $free !== '' ? ($left . ', ' . $free) : $left;
    }

    return trim($free);
}
