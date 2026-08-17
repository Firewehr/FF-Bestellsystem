<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_tile_helpers.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';

ff_menu_ensure_schema($conn);

$Tischnummer = isset($_GET['tischnummer']) ? (int) $_GET['tischnummer'] : 0;
echo '<div id="Dessert">';
if ($Tischnummer <= 0) {
    echo '<span class="text-muted">Ungültiger Tisch.</span></div>';
    exit;
}

$resulttt = mysqli_query($conn, 'SELECT rowid, Positionsname FROM positionen WHERE type=3' . ff_position_sql_kellner_visible() . ' ORDER BY Positionsname');
if (!$resulttt) {
    echo '</div>';
    exit;
}

while ($rowww = mysqli_fetch_assoc($resulttt)) {
    $rid = (int) $rowww['rowid'];
    echo '<button type="button" class="ui-btn ui-corner-all" onclick="saveBestellung(' . $rid . ',2,' . $Tischnummer . ');"';

    $result4 = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS cnt FROM bestellungen WHERE tischnummer=' . $Tischnummer
        . ' AND kueche=0 AND `delete`=0 AND position=' . $rid
    );

    $Colour = 'white';
    $cnt = '';
    if ($result4) {
        while ($row4 = mysqli_fetch_assoc($result4)) {
            if ((int) $row4['cnt'] > 0) {
                $Colour = 'yellow';
                $cnt = (int) $row4['cnt'] . 'x';
            }
        }
    }

    echo ' style="background:' . htmlspecialchars($Colour, ENT_QUOTES, 'UTF-8') . ';">';
    echo htmlspecialchars((string) ($rowww['Positionsname'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo ' ' . htmlspecialchars($cnt, ENT_QUOTES, 'UTF-8');

    echo '</button>';
}
echo '</div>';
