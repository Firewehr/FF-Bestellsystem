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
    <a href="#" class="btn btn-outline-secondary btn-sm ms-auto"
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
            $detailActs = str_replace('>Details</a>', '>📄 Bon ansehen</a>', $detailActs);
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

            $grpSum = 0.0;
            foreach ($groupRows as $gr) {
                if ((int) ($gr['delete'] ?? 0) === 0) {
                    $grpSum += (float) $gr['betrag'];
                }
            }
            $liveCnt = 0; $paidCnt = 0; $delivCnt = 0;
            $labelCnt = [];
            foreach ($groupRows as $gr) {
                if ((int) ($gr['delete'] ?? 0) !== 0) {
                    continue;
                }
                $liveCnt++;
                $gu = ff_tisch_hist_row_status_ui($gr);
                $paidCnt += $gu['isPaid'] ? 1 : 0;
                $delivCnt += $gu['isDelivered'] ? 1 : 0;
                foreach ($gu['badges'] as $gb) {
                    $labelCnt[$gb['label']] = ($labelCnt[$gb['label']] ?? 0) + 1;
                }
            }
            // Zahlungs-/Lieferstatus, der für alle Positionen gleich ist, steht nur im Kopf
            $hideLabels = [];
            if ($liveCnt > 0) {
                foreach (['Zahlung offen', 'Bezahlt', 'Lieferung fertig', 'Lieferung offen'] as $hl) {
                    if (($labelCnt[$hl] ?? 0) === $liveCnt) {
                        $hideLabels[] = $hl;
                    }
                }
            }
            $headBadges = '';
            if ($liveCnt > 0) {
                if ($paidCnt === $liveCnt) {
                    $headBadges .= '<span class="badge bg-success">Bezahlt</span>';
                } elseif ($paidCnt === 0) {
                    $headBadges .= '<span class="badge bg-warning text-dark">Zahlung offen</span>';
                } else {
                    $headBadges .= '<span class="badge bg-warning text-dark">' . ($liveCnt - $paidCnt) . ' unbezahlt</span>';
                }
                if ($delivCnt === $liveCnt) {
                    $headBadges .= '<span class="badge bg-success">Geliefert</span>';
                } elseif ($delivCnt === 0) {
                    $headBadges .= '<span class="badge bg-info text-dark">Lieferung offen</span>';
                } else {
                    $headBadges .= '<span class="badge bg-info text-dark">' . ($liveCnt - $delivCnt) . ' nicht geliefert</span>';
                }
            }
            if ($liveCnt === 0) {
                $headBadges = '<span class="badge bg-danger">Storniert</span>';
            }
            $orderState = $liveCnt === 0 ? 'storno' : ($paidCnt < $liveCnt ? 'open' : 'paid');
            if ($liveCnt === 0 && $groupTitle !== '') {
                $groupTitle .= ' (storniert)';
            }
            $headTime = $histFmtTs($first['zeitstempel'] ?? '');
            $grpKey = htmlspecialchars((string) $bk, ENT_QUOTES, 'UTF-8');
            $grpOpenDefault = ($bk === $groupOrder[0]);

            echo '<div class="ff-hist-order ff-hist-order-' . $orderState . ' border rounded-3 bg-white">';
            echo '<div class="ff-hist-order-head d-flex flex-wrap align-items-center gap-2 px-3 py-2">';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary ff-hist-toggle" data-ff-hist-key="o|' . $grpKey . '"'
                . ' data-ff-hist-default-open="' . ($grpOpenDefault ? '1' : '0') . '" aria-expanded="' . ($grpOpenDefault ? 'true' : 'false') . '"'
                . ' onclick="ffHistToggle(this); return false;" title="Ein-/Ausklappen"><span class="ff-hist-caret">' . ($grpOpenDefault ? '▾' : '▸') . '</span></button>';
            if ($detailActs !== '') {
                echo $detailActs;
            } else {
                echo '<span class="fw-semibold">' . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            if ($canStornoWhole || $canMoveWhole) {
                echo '<div class="dropdown ms-auto">';
                echo '<button type="button" class="btn btn-sm btn-outline-secondary ff-hist-menu-btn" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Aktionen für die Bestellung">⋯</button>';
                echo '<ul class="dropdown-menu dropdown-menu-end">';
                if ($canMoveWhole) {
                    $jsBatch = htmlspecialchars($batchTs, ENT_QUOTES, 'UTF-8');
                    $jsLabel = htmlspecialchars(
                        'Ganze Bestellung verschieben (' . count($movableInGroup) . ' Position(en)):',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    echo '<li><button type="button" class="dropdown-item"'
                        . ' onclick="ffTischHistVerschiebenBestellung(' . (int) $Tischnummer . ', ' . $orderNr . ', \''
                        . $jsBatch . '\', \'' . $jsLabel . '\'); return false;">'
                        . '↷ Bestellung verschieben</button></li>';
                }
                if ($canStornoWhole) {
                    $jsBatchS = htmlspecialchars($batchTs, ENT_QUOTES, 'UTF-8');
                    $jsLabelS = htmlspecialchars(
                        'Ganze Bestellung stornieren (' . $stornoInGroup . ' Position(en))?',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $jsBonS = htmlspecialchars($bonIdGrp, ENT_QUOTES, 'UTF-8');
                    if ($canMoveWhole) {
                        echo '<li><hr class="dropdown-divider"></li>';
                    }
                    echo '<li><button type="button" class="dropdown-item text-danger fw-semibold"'
                        . ' onclick="ffTischHistStornierenBestellung(' . (int) $Tischnummer . ', ' . $orderNr . ', \''
                        . $jsBatchS . '\', \'' . $jsBonS . '\', \'' . $jsLabelS . '\'); return false;">'
                        . '🗑 Bestellung stornieren</button></li>';
                }
                echo '</ul></div>';
            }
            echo '<div class="ff-hist-order-meta w-100 d-flex flex-wrap align-items-center gap-2">';
            echo '<span class="badge bg-light text-dark border">' . count($groupRows) . ' Pos. · ' . number_format($grpSum, 2, ',', '.') . ' €</span>';
            echo $headBadges;
            if ($headTime !== '') {
                echo '<span class="small text-muted">' . htmlspecialchars($headTime, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '</div>';
            echo '</div>';
            echo '<div class="ff-hist-collapse"' . ($grpOpenDefault ? '' : ' hidden') . '>';
            echo '<div class="d-flex flex-column gap-2 p-2">';

            if (!function_exists('ff_hist_card_state')) {
                function ff_hist_card_state(array $row, array $ui): string
                {
                    if ((int) ($row['delete'] ?? 0) === 1) {
                        return 'ff-st-storno';
                    }
                    if (empty($ui['isPaid'])) {
                        return 'ff-st-unpaid';
                    }
                    return empty($ui['isDelivered']) ? 'ff-st-pending' : 'ff-st-ok';
                }
            }
            $renderRow = function (array $row) use ($conn, $isAdmin, $currentUser, $paymentMode, $histFmtTs, $Tischnummer, $hideLabels): void {
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
            <div class="card ff-hist-card <?php echo ff_hist_card_state($row, $statusUi); ?>">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h6 class="mb-0 fw-semibold"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h6>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-secondary ff-hist-toggle" data-ff-hist-key="r|<?php echo $rid; ?>"
                                        aria-expanded="false" onclick="ffHistToggle(this); return false;" title="Details ein-/ausklappen"><span class="ff-hist-caret">▸</span></button>
                                <?php foreach ($statusUi['badges'] as $badge): if (in_array($badge['label'], $hideLabels, true)) { continue; } ?>
                                <span class="badge <?php echo htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8'); ?>"
                                      title="<?php echo htmlspecialchars($badge['title'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="ff-hist-collapse" hidden>
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
                        <div class="d-flex flex-wrap gap-2 mt-2 ff-hist-collapse" hidden>
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
            };

            // Identische Positionen (Name, Zusatzinfo, Preis, Status) zusammenfassen
            $rowGroups = [];
            foreach ($groupRows as $gr) {
                $gUi = ff_tisch_hist_row_status_ui($gr);
                $badgeSig = implode('|', array_map(static function ($bd) { return (string) ($bd['label'] ?? ''); }, $gUi['badges']));
                $gk = implode("\x1f", [
                    (int) ($gr['position_rowid'] ?? $gr['position'] ?? 0),
                    (string) ($gr['Positionsname'] ?? ''),
                    trim((string) ($gr['Zusatzinfo'] ?? '')),
                    number_format((float) $gr['betrag'], 2, '.', ''),
                    $badgeSig,
                    (int) ($gr['delete'] ?? 0),
                ]);
                $rowGroups[$gk][] = $gr;
            }

            foreach ($rowGroups as $gk => $gRows) {
                if (count($gRows) === 1) {
                    $renderRow($gRows[0]);
                    continue;
                }
                $g0 = $gRows[0];
                $gUi0 = ff_tisch_hist_row_status_ui($g0);
                $gSum = 0.0;
                foreach ($gRows as $gr) {
                    $gSum += (float) $gr['betrag'];
                }
                $gZi = trim((string) ($g0['Zusatzinfo'] ?? ''));
                $gZiHtml = $gZi !== '' ? ff_zusatzinfo_display_html($conn, (int) ($g0['position_rowid'] ?? $g0['position'] ?? 0), $gZi) : '';
                $gStateKey = htmlspecialchars($bk . '|' . $gk, ENT_QUOTES, 'UTF-8');
                ?>
                <div class="card ff-hist-card <?php echo ff_hist_card_state($g0, $gUi0); ?>">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="flex-grow-1 min-width-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h6 class="mb-0 fw-semibold"><?php echo count($gRows); ?>× <?php echo htmlspecialchars((string) $g0['Positionsname'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                    <?php foreach ($gUi0['badges'] as $badge): if (in_array($badge['label'], $hideLabels, true)) { continue; } ?>
                                    <span class="badge <?php echo htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8'); ?>"
                                          title="<?php echo htmlspecialchars($badge['title'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php echo $gZiHtml; ?>
                            </div>
                            <div class="fw-semibold text-end" style="white-space:nowrap;"><?php echo number_format($gSum, 2, ',', '.'); ?> €</div>
                        </div>
                        <button type="button" class="btn btn-link btn-sm p-0 mt-1 text-secondary text-decoration-none ff-hist-toggle" data-ff-hist-key="<?php echo $gStateKey; ?>"
                                aria-expanded="false" onclick="ffHistToggle(this); return false;">
                            <span class="ff-hist-caret">▸</span> Einzeln anzeigen (<?php echo count($gRows); ?>)
                        </button>
                        <div class="d-flex flex-column gap-2 mt-2 ff-hist-collapse" hidden>
                            <?php foreach ($gRows as $gr) { $renderRow($gr); } ?>
                        </div>
                    </div>
                </div>
                <?php
            }

            echo '</div>'; // Positionsliste
            echo '</div>'; // Collapse-Body
            echo '</div>'; // Bestellkarte
        }

        echo '</div>';

        if ($histPendingRechnung > 0) {
            echo '<div class="card border-info mt-3"><div class="card-body py-3 text-center">';
            echo '<p class="small text-muted mb-2">Bezahlte Posten ohne Rechnung: <strong>' . (int) $histPendingRechnung . '</strong></p>';
            echo ff_rechnung_nachtraeglich_button_html($Tischnummer, $histPendingRechnung, 'btn btn-info');
            echo '</div></div>';
        }
    }

    ?>
<style>
.ff-hist-collapse[hidden]{display:none !important;}
.ff-hist-card{cursor:pointer;position:relative;border:1px solid #dee2e6 !important;border-left-width:6px !important;}
.ff-hist-card .card-body{padding-right:2rem !important;}
.ff-hist-card::after{content:"▾";position:absolute;right:.65rem;bottom:.3rem;color:#6c757d;font-size:.85rem;transition:transform .15s;}
.ff-hist-card.ff-open::after{transform:rotate(180deg);}
.ff-hist-card .ff-hist-collapse{cursor:default;}
.ff-hist-card.ff-st-ok{border-left-color:#dee2e6 !important;}
.ff-hist-card.ff-st-pending{border-left-color:#0dcaf0 !important;}
.ff-hist-card.ff-st-unpaid{border-left-color:#ffc107 !important;}
.ff-hist-card.ff-st-storno{border-left-color:#dc3545 !important;opacity:.75;}
.ff-hist-order{border:2px solid #6c757d !important;border-left-width:8px !important;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.ff-hist-order-head{cursor:pointer;border-radius:.4rem .4rem 0 0;background:#f1f3f5;}
.ff-hist-order-open{border-color:#ffc107 !important;box-shadow:0 2px 8px rgba(255,193,7,.35);}
.ff-hist-order-open .ff-hist-order-head{background:#fff6d6;}
.ff-hist-order-paid{border-color:#a3cfbb !important;}
.ff-hist-order-paid .ff-hist-order-head{background:#eef7f1;}
.ff-hist-order-storno{border-color:#ced4da !important;opacity:.8;}
.ff-hist-order-head .badge.bg-primary,.ff-hist-order-head .badge.bg-dark{background-color:#0b3d91 !important;font-size:1.1rem;font-weight:700;padding:.35em .65em;}
.ff-hist-order-head .btn-outline-primary{color:#495057;border-color:#adb5bd;}
.ff-hist-order-head .badge.bg-light{font-size:.95rem;font-weight:700;}
.ff-hist-menu-btn{font-weight:700;line-height:1;padding:.3rem .7rem;font-size:1.1rem;}
</style>
<script data-ff-hist-collapse="1">
(function () {
    var st = window.ffHistState = window.ffHistState || {};
    function target(btn) {
        var key = btn.getAttribute('data-ff-hist-key') || '';
        if (key.indexOf('r|') === 0) {
            var body = btn.closest('.card-body');
            return body ? body.querySelectorAll('.ff-hist-collapse') : null;
        }
        var host = key.indexOf('o|') === 0 ? btn.closest('.border.rounded-3') : btn.parentNode;
        return host ? host.querySelector(':scope > .ff-hist-collapse') : null;
    }
    function apply(btn, open) {
        var t = target(btn);
        if (!t) return;
        if (t.length === undefined) t = [t];
        for (var j = 0; j < t.length; j++) t[j].hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        var c = btn.querySelector('.ff-hist-caret');
        if (c) c.textContent = open ? '▾' : '▸';
        if ((btn.getAttribute('data-ff-hist-key') || '').indexOf('o|') !== 0) {
            var cardEl = btn.closest('.ff-hist-card');
            if (cardEl) cardEl.classList.toggle('ff-open', open);
        }
    }
    if (!window.ffHistBadgeBound) {
        window.ffHistBadgeBound = true;
        // Capture-Phase: Klick auf das Bestell-Nr.-Badge klappt auf, statt das Inline-onclick auszulösen
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('.ff-hist-order-head a.badge') : null;
            if (!a) return;
            e.preventDefault();
            e.stopPropagation();
            var hb = a.closest('.ff-hist-order-head').querySelector('.ff-hist-toggle');
            if (hb) window.ffHistToggle(hb);
        }, true);
    }
    if (!window.ffHistCardClickBound) {
        window.ffHistCardClickBound = true;
        document.addEventListener('click', function (e) {
            var head = e.target.closest ? e.target.closest('.ff-hist-order-head') : null;
            if (head && !e.target.closest('button, a, .dropdown-menu')) {
                var hb = head.querySelector('.ff-hist-toggle');
                if (hb) window.ffHistToggle(hb);
                return;
            }
            var card = e.target.closest ? e.target.closest('.ff-hist-card') : null;
            if (!card || e.target.closest('button, a, input, select, textarea, label')) return;
            var b = card.querySelector('.ff-hist-toggle');
            if (b) window.ffHistToggle(b);
        });
    }
    window.ffHistToggle = function (btn) {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        st[btn.getAttribute('data-ff-hist-key')] = open;
        apply(btn, open);
    };
    var navHist = document.querySelector('nav.app-navbar button[onclick*="TischAnsichtHistory"]');
    if (navHist) {
        navHist.classList.add('active');
        navHist.setAttribute('aria-current', 'page');
        navHist.style.pointerEvents = 'none';
    }
    var btns = document.querySelectorAll('.ff-hist-toggle[data-ff-hist-key]');
    for (var i = 0; i < btns.length; i++) {
        var k = btns[i].getAttribute('data-ff-hist-key');
        if (Object.prototype.hasOwnProperty.call(st, k)) apply(btns[i], !!st[k]);
    }
})();
</script>
<?php
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
