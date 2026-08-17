<?php
require_once('auth.php');
?>
<?php
$Tischnummer = intval($_GET['tischnummer']);
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <a href="javascript:void(0)" onclick="if(typeof TischHistorieZurueck==='function'){TischHistorieZurueck();}else if(typeof tisch==='function'){tisch();} return false;" class="btn btn-outline-secondary btn-sm">
        <span aria-hidden="true">&larr;</span> <span id="ffHistBackLabel">Zurück</span>
    </a>
    <span class="ms-1 fw-semibold text-muted">Historie</span>
    <?php if ($Tischnummer > 0): ?>
    <?php $ffStoryAdmin = !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1; ?>
    <a href="#" class="btn btn-outline-primary btn-sm ms-auto"
       onclick="if(typeof ffOpenTischGesamteStory==='function'){ffOpenTischGesamteStory(<?php echo $ffStoryAdmin ? 'true' : 'false'; ?>);} return false;">
        Gesamte Historie anzeigen
    </a>
    <?php endif; ?>
</div>

<?php
try {
    include_once('include/db.php');
    require_once __DIR__ . '/include/user_landing.php';
    require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
    require_once __DIR__ . '/include/ff_bestellung_verschieben.php';
    require_once __DIR__ . '/include/ff_bestellung_storno.php';
    require_once __DIR__ . '/include/beilage_helpers.php';
    require_once __DIR__ . '/include/ff_hist_navigation.php';
    ff_users_ensure_landing_columns($conn);

    $isAdmin = !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
    $currentUser = (string) ($_SESSION['user']['username'] ?? '');
    $paymentMode = ff_verschieben_payment_mode($conn);

    $histPendingRechnung = ff_tisch_count_paid_without_rechnung($conn, $Tischnummer);

    $histFmtTs = static function ($ts): string {
        $ts = trim((string) $ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') {
            return '';
        }
        $t = strtotime($ts);
        return $t ? date('d.m.Y H:i', $t) : '';
    };

    $histSelect = "SELECT bestellungen.tischnummer,
                   bestellungen.kellner,
                   bestellungen.kellnerZahlung,
                   bestellungen.timestampBezahlung,
                   bestellungen.timestampBestellung,
                   bestellungen.order_nr,
                   bestellungen.bon_id,
                   bestellungen.Zusatzinfo,
                   COALESCE(bestellungen.betrag, positionen.Betrag) AS betrag,
                   COALESCE(NULLIF(TRIM(positionen.Positionsname), ''), '(Position nicht gefunden)') AS Positionsname,
                   bestellungen.zeitKueche,
                   bestellungen.position,
                   positionen.rowid AS position_rowid,
                   COALESCE(positionen.type, 1) AS pos_type,
                   bestellungen.zeitstempel,
                   bestellungen.rowid,
                   bestellungen.delete,
                   bestellungen.kueche AS kuechef,
                   bestellungen.bestellt,
                   bestellungen.ausgeliefert,
                   bestellungen.timestampAuslieferung
            FROM bestellungen
            LEFT JOIN positionen ON positionen.rowid = bestellungen.position
            WHERE bestellungen.tischnummer = " . (int) $Tischnummer;

    /* 70 aktiv + 30 storniert (neueste jeweils), damit Stornos nicht vom Limit verdrängt werden. */
    $rowsById = [];
    $mergeHist = static function ($res) use (&$rowsById): void {
        if (!$res) {
            return;
        }
        while ($row = mysqli_fetch_assoc($res)) {
            $rowsById[(int) ($row['rowid'] ?? 0)] = $row;
        }
    };
    $mergeHist(mysqli_query($conn, $histSelect . " AND bestellungen.`delete` = 0 ORDER BY bestellungen.zeitstempel DESC LIMIT 70"));
    $mergeHist(mysqli_query($conn, $histSelect . " AND bestellungen.`delete` = 1 ORDER BY bestellungen.zeitstempel DESC LIMIT 30"));

    $rows = array_values($rowsById);
    usort($rows, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['zeitstempel'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['zeitstempel'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });

    if ($rows === []) {
        echo '<div class="alert alert-light border text-center text-muted my-3">'
            . 'Keine Positionen für diesen Tisch.'
            . '</div>';
    } else {

        // Nach Bestellungsrunde gruppieren (neueste Runde zuerst)
        $groups = [];
        $groupOrder = [];
        foreach ($rows as $row) {
            $bk = ff_verschieben_batch_key($row);
            if (!isset($groups[$bk])) {
                $groups[$bk] = [];
                $groupOrder[] = $bk;
            }
            $groups[$bk][] = $row;
        }

        echo '<div class="d-flex flex-column gap-3">';

        foreach ($groupOrder as $bk) {
            $groupRows = $groups[$bk];
            $first = $groupRows[0];
            $orderNr = (int) ($first['order_nr'] ?? 0);
            $batchTs = trim((string) ($first['timestampBestellung'] ?? ''));
            if ($batchTs === '0000-00-00 00:00:00' || $batchTs === '1970-01-01 00:00:00') {
                $batchTs = '';
            }

            $bonIdGrp = trim((string) ($first['bon_id'] ?? ''));
            $detailActs = ff_hist_tisch_detail_actions_html($orderNr, $bonIdGrp, (int) $Tischnummer);
            if ($detailActs === '') {
                $zt = $histFmtTs($first['zeitstempel'] ?? '');
                $groupTitle = $zt !== '' ? ('Bestellungsrunde · ' . $zt) : 'Bestellungsrunde';
            } else {
                $groupTitle = '';
            }

            $movableInGroup = [];
            foreach ($groupRows as $gr) {
                if (ff_verschieben_can_move_row($gr, $isAdmin, $currentUser, $paymentMode)) {
                    $movableInGroup[] = (int) $gr['rowid'];
                }
            }
            $canMoveWhole = count($movableInGroup) > 0;

            $stornoWhole = ff_storno_group_whole_order_allowed($groupRows, $isAdmin);
            $canStornoWhole = $stornoWhole['can'];
            $stornoInGroup = $stornoWhole['count'];

            echo '<div class="border rounded-3 bg-white overflow-hidden">';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 bg-light border-bottom">';
            echo '<div class="d-flex flex-wrap align-items-center gap-2">';
            if ($detailActs !== '') {
                echo $detailActs;
            } else {
                echo '<span class="fw-semibold small">' . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '<span class="badge bg-secondary">' . count($groupRows) . ' Pos.</span>';
            echo '</div>';
            echo '<div class="d-flex flex-wrap gap-2 ms-auto">';
            if ($canStornoWhole) {
                $jsBatchS = htmlspecialchars($batchTs, ENT_QUOTES, 'UTF-8');
                $jsLabelS = htmlspecialchars(
                    'Ganze Bestellung stornieren (' . $stornoInGroup . ' Position(en))?',
                    ENT_QUOTES,
                    'UTF-8'
                );
                $jsBonS = htmlspecialchars($bonIdGrp, ENT_QUOTES, 'UTF-8');
                echo '<button type="button" class="btn btn-sm btn-outline-danger"'
                    . ' onclick="ffTischHistStornierenBestellung(' . (int) $Tischnummer . ', ' . $orderNr . ', \''
                    . $jsBatchS . '\', \'' . $jsBonS . '\', \'' . $jsLabelS . '\'); return false;">'
                    . '🗑 Ganze Bestellung</button>';
            }
            if ($canMoveWhole) {
                $jsBatch = htmlspecialchars($batchTs, ENT_QUOTES, 'UTF-8');
                $jsLabel = htmlspecialchars(
                    'Ganze Bestellung verschieben (' . count($movableInGroup) . ' Position(en)):',
                    ENT_QUOTES,
                    'UTF-8'
                );
                echo '<button type="button" class="btn btn-sm btn-outline-primary"'
                    . ' onclick="ffTischHistVerschiebenBestellung(' . (int) $Tischnummer . ', ' . $orderNr . ', \''
                    . $jsBatch . '\', \'' . $jsLabel . '\'); return false;">'
                    . '↷ Ganze Bestellung</button>';
            }
            echo '</div></div>';
            echo '<div class="d-flex flex-column gap-2 p-2">';

            foreach ($groupRows as $row) {
            $statusUi = ff_tisch_hist_row_status_ui($row);
            $isPaid = $statusUi['isPaid'];
            $cardBorder = $statusUi['cardBorder'];

            $rid     = (int)$row['rowid'];
            $name    = (string)$row['Positionsname'];
            $kellner = ff_user_display_label($conn, (string)$row['kellner']);
            $zi      = trim((string)($row['Zusatzinfo'] ?? ''));
            $betrag  = (float)$row['betrag'];

            $bestellt = $histFmtTs($row['zeitstempel'] ?? '');
            $kueche   = $statusUi['inKitchen'] ? $histFmtTs($row['zeitKueche'] ?? '') : '';
            $bezahlt  = $isPaid ? $histFmtTs($row['timestampBezahlung'] ?? '') : '';
            $geliefert = $statusUi['isDelivered'] ? $histFmtTs($row['timestampAuslieferung'] ?? '') : '';

            $posRowId = (int) ($row['position_rowid'] ?? $row['position'] ?? 0);
            $posType = (int) ($row['pos_type'] ?? 1);
            $zusatzHtml = $zi !== '' ? ff_zusatzinfo_display_html($conn, $posRowId, $zi) : '';
            $canMoveOne = ff_verschieben_can_move_row($row, $isAdmin, $currentUser, $paymentMode);
            $canStornoOne = ff_storno_can_cancel_row($row, $isAdmin);
            $showZusatzinfo = !$isPaid;
            $offenStation = (int) ($row['ausgeliefert'] ?? 0) === 0;
            ?>
            <div class="card <?php echo $cardBorder; ?>" style="border-width:2px;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h6 class="mb-0 fw-semibold"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h6>
                                <?php foreach ($statusUi['badges'] as $badge): ?>
                                <span class="badge <?php echo htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8'); ?>"
                                      title="<?php echo htmlspecialchars($badge['title'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="small text-muted mt-1">
                                <span title="Bestellt um">🧾 <?php echo htmlspecialchars($bestellt, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="mx-1">·</span>
                                <span><?php echo htmlspecialchars($kellner, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($kueche !== ''): ?>
                                    <span class="mx-1">·</span>
                                    <span title="Station fertig um">🍳 <?php echo htmlspecialchars($kueche, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if ($bezahlt !== ''): ?>
                                    <span class="mx-1">·</span>
                                    <span title="Bezahlt um">💰 <?php echo htmlspecialchars($bezahlt, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if ($geliefert !== ''): ?>
                                    <span class="mx-1">·</span>
                                    <span title="Ausgeliefert um">📦 <?php echo htmlspecialchars($geliefert, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($zusatzHtml !== ''): ?>
                                <?php echo $zusatzHtml; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end" style="white-space:nowrap;">
                            <div class="fw-semibold"><?php echo number_format($betrag, 2, ',', '.'); ?> €</div>
                        </div>
                    </div>

                    <?php if ($showZusatzinfo || $canStornoOne || $canMoveOne): ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php if ($showZusatzinfo): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="ffOpenHistZusatzinfoEdit(<?php echo $rid; ?>, <?php echo $posRowId; ?>, <?php echo (int) $Tischnummer; ?>, <?php echo $posType; ?>); return false;">
                                <span class="me-1">✏️</span>Zusatzinfo
                            </button>
                            <?php endif; ?>
                            <?php if ($canStornoOne): ?>
                                <?php
                                $isDelivered = (int) ($row['ausgeliefert'] ?? 0) === 1;
                                if ($isDelivered && $isPaid):
                                ?>
                            <button type="button" class="btn btn-sm btn-outline-warning"
                                    onclick="ffHistStornierenEine(<?php echo $rid; ?>, <?php echo (int)$Tischnummer; ?>, true, false); return false;">
                                <span class="me-1">↩️</span>Zahlung stornieren
                            </button>
                                <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="ffHistStornierenEine(<?php echo $rid; ?>, <?php echo (int)$Tischnummer; ?>, <?php echo $isPaid ? 'true' : 'false'; ?>, <?php echo $offenStation ? 'true' : 'false'; ?>); return false;">
                                <span class="me-1">🗑️</span>Stornieren
                            </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canMoveOne): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="ffTischHistVerschiebenEine(<?php echo $rid; ?>, <?php echo (int)$Tischnummer; ?>); return false;">
                                <span class="me-1">↷</span>Tisch ändern
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            }

            echo '</div></div>';
        }

        echo '</div>';

        if ($histPendingRechnung > 0) {
            echo '<div class="card border-info mt-3"><div class="card-body py-3 text-center">';
            echo '<p class="small text-muted mb-2">Bezahlte Posten ohne Rechnung: <strong>' . (int) $histPendingRechnung . '</strong></p>';
            echo ff_rechnung_nachtraeglich_button_html($Tischnummer, $histPendingRechnung, 'btn btn-info');
            echo '</div></div>';
        }
    }

    require_once __DIR__ . '/include/menu_list_helpers.php';
    require_once __DIR__ . '/include/ff_schreibaus.php';
    $openUnpaidHist = ff_tisch_count_unpaid_kasse($conn, (int) $Tischnummer, $paymentMode);
    echo '<script data-ff-pay-lock-sync="1">';
    if ($openUnpaidHist <= 0) {
        echo 'if(typeof ffClearTischRequirePayment==="function"){ffClearTischRequirePayment();}';
    } else {
        echo 'if(typeof ffSyncTischRequirePaymentFromServer==="function"){ffSyncTischRequirePaymentFromServer(' . (int) $Tischnummer . ');}';
    }
    echo '</script>';
} catch (Exception $e) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}
?>
