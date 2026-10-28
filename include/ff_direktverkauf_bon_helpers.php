<?php

declare(strict_types=1);



require_once __DIR__ . '/settings.php';

require_once __DIR__ . '/menu_list_helpers.php';



function ff_direktverkauf_now(): DateTimeImmutable

{

    return new DateTimeImmutable('now');

}



/** Kalendertag für Bon-Präfix TT-XXX (1–31, Europe/Vienna wenn in php.ini gesetzt). */

function ff_direktverkauf_bon_today_day(): int

{

    return (int) ff_direktverkauf_now()->format('d');

}



/** Heutiges Datum für Tageszähler / Session (Y-m-d). */

function ff_direktverkauf_today_ymd(): string

{

    return ff_direktverkauf_now()->format('Y-m-d');

}



/** TT aus normalisierter Bon-ID (28-004 → 28), sonst null. */

function ff_direktverkauf_bon_day_from_id(string $bonId): ?int

{

    $norm = ff_direktverkauf_normalize_bon_id($bonId);

    if ($norm === '' || !preg_match('/^(\d{2})-\d{3}$/', $norm, $m)) {

        return null;

    }



    return (int) $m[1];

}



/** Bon-Präfix passt zum heutigen Kalendertag (Server-Zeit). */

function ff_direktverkauf_bon_is_for_today(string $bonId): bool

{

    $day = ff_direktverkauf_bon_day_from_id($bonId);



    return $day !== null && $day === ff_direktverkauf_bon_today_day();

}



function ff_direktverkauf_set_session_bon(string $bonId): void

{

    $_SESSION['dv_current_bon_id'] = trim($bonId);

    $_SESSION['dv_current_bon_ymd'] = ff_direktverkauf_today_ymd();

}



function ff_direktverkauf_clear_session_bon(): void

{

    unset($_SESSION['dv_current_bon_id'], $_SESSION['dv_current_bon_ymd']);

}



/** Session-Bon ist vom heutigen Kalendertag (Y-m-d), nicht nur gleiche TT-Ziffer. */

function ff_direktverkauf_session_bon_is_current(): bool

{

    $bon = trim((string) ($_SESSION['dv_current_bon_id'] ?? ''));

    $ymd = trim((string) ($_SESSION['dv_current_bon_ymd'] ?? ''));

    if ($bon === '') {

        return false;

    }

    if ($ymd !== '' && $ymd !== ff_direktverkauf_today_ymd()) {

        return false;

    }

    if ($ymd === '') {

        return ff_direktverkauf_bon_is_for_today($bon);

    }



    return ff_direktverkauf_bon_is_for_today($bon);

}



/** Session-Bon verwerfen, wenn vom Vortag (Browser/Tab über Nacht offen). */

function ff_direktverkauf_clear_stale_session_bon(): void

{

    if (!ff_direktverkauf_session_bon_is_current()) {

        ff_direktverkauf_clear_session_bon();

    }

}



/** Nächste Bon-ID für heute (Format TT-XXX). */

function ff_direktverkauf_alloc_next_bon_id(mysqli $conn): string

{

    $today = ff_direktverkauf_bon_today_day();

    $key = 'bon_next_' . ff_direktverkauf_today_ymd();

    $next = (int) setting_get($conn, $key, '1');

    $bonId = sprintf('%02d-%03d', $today, $next);

    setting_set($conn, $key, (string) ($next + 1));



    return $bonId;

}



/** Bon für aktuelle DV-Ansicht (Query, Session oder neu). */

function ff_direktverkauf_resolve_page_bon_id(mysqli $conn): string

{

    $fromQuery = trim((string) ($_GET['bon_id'] ?? ''));

    if ($fromQuery !== '') {

        $norm = ff_direktverkauf_normalize_bon_id($fromQuery);

        $bonId = $norm !== '' ? $norm : $fromQuery;

        if (ff_direktverkauf_bon_is_for_today($bonId)) {

            ff_direktverkauf_set_session_bon($bonId);



            return $bonId;

        }

    }



    ff_direktverkauf_clear_stale_session_bon();



    if (ff_direktverkauf_session_bon_is_current()) {

        return trim((string) $_SESSION['dv_current_bon_id']);

    }



    ff_direktverkauf_clear_session_bon();



    $bonId = ff_direktverkauf_alloc_next_bon_id($conn);

    ff_direktverkauf_set_session_bon($bonId);



    return $bonId;

}



/** HTML-Fragment einer DV-Liste oder Paybar einbinden (gleicher mysqli-Kontext wie Parent). */

function ff_direktverkauf_capture_fragment(mysqli $conn, string $relativeScript, string $bonId): string

{

    $bonId = trim($bonId);

    $prevBon = $_GET['bon_id'] ?? null;

    $prevTisch = $_GET['tischnummer'] ?? null;

    $prevPartial = $_GET['partial'] ?? null;



    $_GET['bon_id'] = $bonId;

    $_GET['tischnummer'] = '999999';

    if ($relativeScript === 'zahlen_direktverkauf.php') {

        unset($_GET['partial']);

    }



    if (!defined('FF_DV_FRAGMENT_CAPTURE')) {

        define('FF_DV_FRAGMENT_CAPTURE', true);

    }



    ob_start();

    include dirname(__DIR__) . '/' . ltrim($relativeScript, '/');

    $html = ob_get_clean();



    if ($prevBon === null) {

        unset($_GET['bon_id']);

    } else {

        $_GET['bon_id'] = $prevBon;

    }

    if ($prevTisch === null) {

        unset($_GET['tischnummer']);

    } else {

        $_GET['tischnummer'] = $prevTisch;

    }

    if ($prevPartial === null) {

        unset($_GET['partial']);

    } else {

        $_GET['partial'] = $prevPartial;

    }



    return $html;

}


