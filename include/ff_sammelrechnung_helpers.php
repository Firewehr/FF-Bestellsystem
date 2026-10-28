<?php
/**
 * UI-Hilfen für Sammelrechnung (Admin: Tische gemeinsam verrechnen).
 */

function ff_user_is_fest_admin(): bool
{
    return (int) ($_SESSION['admin'] ?? 0) >= 1;
}

function ff_sammelrechnung_pick_url(int $preselectTisch = 0): string
{
    if ($preselectTisch <= 0) {
        return 'sammelrechnung.php';
    }

    return 'sammelrechnung.php?preselect=' . (int) $preselectTisch . '&from_tisch=' . (int) $preselectTisch;
}

function ff_sammelrechnung_pick_button_html(int $preselectTisch, string $btnClass = 'btn btn-warning btn-sm'): string
{
    if (!ff_user_is_fest_admin()) {
        return '';
    }
    $url = ff_sammelrechnung_pick_url($preselectTisch);

    return '<a class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '" href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . '🧾 Sammelrechnung (Tische wählen)</a>';
}
