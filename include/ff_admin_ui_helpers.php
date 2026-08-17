<?php
/**
 * Kleine UI-Helfer für Admin (aufklappbare Hinweise).
 */
declare(strict_types=1);

/**
 * Runder „i“-Button zum Aufklappen eines Hinweis-Blocks (Bootstrap collapse).
 */
function ff_admin_info_btn(string $collapseId, string $title = 'Hinweis'): string
{
    $id = htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    return '<button type="button" class="btn ff-admin-info-btn" data-bs-toggle="collapse"'
        . ' data-bs-target="#' . $id . '" aria-expanded="false" aria-controls="' . $id . '"'
        . ' title="' . $t . '" aria-label="' . $t . '"><span aria-hidden="true">i</span></button>';
}

/**
 * @param string $collapseId muss mit ff_admin_info_btn übereinstimmen
 * @param string $html bereits sicheres HTML oder von außen escaped
 */
function ff_admin_info_panel(string $collapseId, string $html, bool $show = false): void
{
    $id = htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8');
    $cls = 'collapse ff-admin-info-panel small text-muted' . ($show ? ' show' : '');
    echo '<div class="' . $cls . '" id="' . $id . '"><div class="ff-admin-info-panel-inner">' . $html . '</div></div>';
}

/**
 * Titelzeile + i-Button für Kachel „Kellner / Direktverkauf“ inkl. Aufschlüsselungs-Panel.
 *
 * @param string $collapseId z. B. dashKelDirektHint
 * @param string $spanIdPrefix z. B. dashKelDirektBreak → …BreakKellner / …BreakDirekt
 */
function ff_finance_kel_direkt_tile_heading(string $collapseId, string $spanIdPrefix): void
{
    $kId = htmlspecialchars($spanIdPrefix . 'Kellner', ENT_QUOTES, 'UTF-8');
    $dId = htmlspecialchars($spanIdPrefix . 'Direkt', ENT_QUOTES, 'UTF-8');
    echo '<div class="d-flex align-items-center gap-1 flex-wrap mb-1">';
    echo '<span class="text-muted small">Kellner / Direktverkauf</span>';
    echo ff_admin_info_btn($collapseId, 'Aufschlüsselung Kellner / Direktverkauf');
    echo '</div>';
    $panelHtml = '<p class="mb-1"><strong>Aufschlüsselung</strong> (unzugeordneter Verkauf):</p>'
        . '<ul class="mb-0 ps-3">'
        . '<li><strong>Kellner</strong> (Kasse, <code>kellnerZahlung</code>, nicht Tisch 999999): <strong id="' . $kId . '">—</strong></li>'
        . '<li><strong>Direktverkauf</strong> (Tisch 999999): <strong id="' . $dId . '">—</strong></li>'
        . '</ul>';
    ff_admin_info_panel($collapseId, $panelHtml);
}
