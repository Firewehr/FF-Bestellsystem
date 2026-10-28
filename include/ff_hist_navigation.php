<?php
declare(strict_types=1);

/** Virtueller Tisch Direktverkauf (wie bestell_history.php). */
const FF_HIST_NAV_TISCH_DV = 999999;

/**
 * @return ''|'tisch'|'dv'|'station'|'druckziel'
 */
function ff_hist_nav_from_context(string $from): string
{
    $from = trim($from);

    return in_array($from, ['tisch', 'dv', 'station', 'druckziel'], true) ? $from : '';
}

/**
 * URL zur Bestell-History-Detailansicht (eine Bestellung / ein Bon).
 * Leer, wenn weder order_nr noch (DV-)Bon bekannt.
 *
 * @param array{table?:int,return?:string,require_pay?:bool,pt?:int} $extraOpts from=tisch: table/return; from=druckziel: pt
 */
function ff_hist_detail_url(
    int $tischnummer,
    int $orderNr,
    string $bonId,
    string $from,
    array $extraOpts = []
): string {
    $from = ff_hist_nav_from_context($from);
    $bon = trim($bonId);
    $params = [];
    if ($from !== '') {
        $params['from'] = $from;
    }
    if ($tischnummer === FF_HIST_NAV_TISCH_DV && $bon !== '') {
        $params['bon'] = $bon;
    } elseif ($orderNr > 0) {
        $params['q'] = (string) $orderNr;
    } else {
        return '';
    }
    if ($from === 'tisch') {
        $table = (int) ($extraOpts['table'] ?? 0);
        if ($table > 0) {
            $params['table'] = (string) $table;
        }
        $ret = trim((string) ($extraOpts['return'] ?? ''));
        if ($ret !== '' && in_array($ret, ['zahlen', 'historie', 'rechnung', 'bestellen'], true)) {
            $params['return'] = $ret;
        }
        if (!empty($extraOpts['require_pay'])) {
            $params['require_pay'] = '1';
        }
    }
    if ($from === 'druckziel') {
        $pt = (int) ($extraOpts['pt'] ?? 0);
        if ($pt > 0) {
            $params['pt'] = (string) $pt;
        }
    }

    return 'bestell_history.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Anzeige-Label für Bestell-Nr. oder Bon (Kopfzeile in Historien).
 */
function ff_hist_detail_ref_label(int $tischnummer, int $orderNr, string $bonId): string
{
    $bon = trim($bonId);
    if ($tischnummer === FF_HIST_NAV_TISCH_DV && $bon !== '') {
        return 'Bon #' . $bon;
    }
    if ($orderNr > 0) {
        return 'Bestellung Nr. ' . (string) $orderNr;
    }

    return '';
}

/**
 * Badge + Button „Details“ (gleiche Ziel-URL) für DV / Station / Druckziel.
 */
function ff_hist_detail_actions_html(
    int $tischnummer,
    int $orderNr,
    string $bonId,
    string $from,
    array $extraOpts = []
): string {
    $url = ff_hist_detail_url($tischnummer, $orderNr, $bonId, $from, $extraOpts);
    if ($url === '') {
        return '';
    }
    $label = ff_hist_detail_ref_label($tischnummer, $orderNr, $bonId);
    if ($label === '') {
        return '';
    }
    $urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $isDvBon = $tischnummer === FF_HIST_NAV_TISCH_DV && trim($bonId) !== '';
    $badgeCls = $isDvBon ? 'bg-dark' : 'bg-primary';
    $title = 'Bestell-History öffnen (Detail &amp; Bon nachdrucken)';

    $badge = '<a href="' . $urlEsc . '" class="badge ' . $badgeCls
        . ' fs-6 text-decoration-none" title="' . $title . '">' . $labelEsc . '</a>';
    $btn = '<a href="' . $urlEsc . '" class="btn btn-sm btn-outline-primary" title="'
        . $title . '">Details</a>';

    return $badge . $btn;
}

/**
 * Badge + „Details“ für eingebettete Tisch-Historie (URL via ffOpenTischOrderDetail in app.js).
 */
function ff_hist_tisch_detail_actions_html(int $orderNr, string $bonId, int $tableNo): string
{
    $bon = trim($bonId);
    if ($orderNr <= 0 && $bon === '') {
        return '';
    }
    $label = $orderNr > 0
        ? ('Bestellung Nr. ' . (string) $orderNr)
        : ('Bon #' . $bon);
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $badgeCls = ($bon !== '' && $tableNo === FF_HIST_NAV_TISCH_DV) ? 'bg-dark' : 'bg-primary';
    $jsOnr = (int) $orderNr;
    $jsBon = htmlspecialchars($bon, ENT_QUOTES, 'UTF-8');
    $onclick = 'if(typeof ffOpenTischOrderDetail===\'function\'){ffOpenTischOrderDetail('
        . $jsOnr . ',\'' . $jsBon . '\');}return false;';
    $title = 'Bestell-History öffnen (nur eigene Positionen)';

    $badge = '<a href="#" class="badge ' . $badgeCls
        . ' text-decoration-none" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" onclick="'
        . $onclick . '">' . $labelEsc . '</a>';
    $btn = '<a href="#" class="btn btn-sm btn-outline-primary" title="'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" onclick="' . $onclick . '">Details</a>';

    return $badge . $btn;
}
