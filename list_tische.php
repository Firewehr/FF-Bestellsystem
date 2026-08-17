<?php
require_once('auth.php');
require_once __DIR__ . '/include/user_landing.php';
?>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Menü</a>
        <span class="navbar-brand mb-0">Tischübersicht</span>
        <a href="#myOrdersPage" class="btn btn-outline-primary btn-sm" onclick="myOrdersAnsicht(); return false;">Meine offenen Bestellungen</a>
    </div>
</nav>

<div class="app-content py-4">

    <?php
    /*
      try {
        include ("include/db.php");
        include ("include/settings.php");
        $setting_kellner_nur_eigene = setting_get($conn, 'kellner_nur_eigene', '1');
        $kellnerCountFilter = (
            ($setting_kellner_nur_eigene === '1') && (!isset($_SESSION['admin']) || $_SESSION['admin'] < 1)
        ) ? (" AND kellner='" . htmlspecialchars($_SESSION['user']['username']) . "'") : '';


      //Liste alle Tische und ordne sie nach tischnummer
      $sql = "SELECT * FROM tische ORDER BY tischnummer";
      $result = mysqli_query($conn, $sql);
      ?>

      <div class="ui-grid-d">
      <?php
      if (mysqli_num_rows($result) > 0) {

      while ($row = mysqli_fetch_assoc($result)) {

      $x = substr($row['tischnummer'], -1);
      $x1 = substr($row['tischnummer'], -2);

      if ($x == 1 && $x1 != 1) {
      $char = 'a';
      }
      if ($x == 2) {
      $char = 'b';
      }
      if ($x == 3) {
      $char = 'c';
      }
      if ($x == 4) {
      $char = 'd';
      }
      if ($x == 5) {
      $char = 'e';
      }

      $tischnummerabfrage = $row['tischnummer'];

      $result2 = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM bestellungen WHERE `delete`=0 AND `timestampBezahlung`=\"0000-00-00 00:00:00\"  AND tischnummer=" . $tischnummerabfrage . $kellnerCountFilter);
      while ($roww = mysqli_fetch_assoc($result2)) {
      //echo $roww['cnt'];

      if ($roww['cnt'] > 0) {
      $Colour = "yellow";
      }

      if ($roww['cnt'] == 0) {
      $Colour = "LightGreen";
      }
      }
      echo '<div class="col">';



      echo '<button class="ui-btn ui-corner-all big" onclick="Tischnummer=' . $row['tischnummer'] . ';tisch();" style="background:' . $Colour . ';">'; //font-size:13px;
      echo "&nbsp;&nbsp;" . $row['tischname'] . "&nbsp;&nbsp;";
      echo '</button>';
      echo '</div>';
      }
      } else {
      echo "0 results";
      }

      mysqli_close($conn);
      echo '</div>';
      } catch (Exception $e) {
      echo $e->getMessage();
      }

     */


    try {
        include_once __DIR__ . '/include/db.php';
        include_once __DIR__ . '/include/settings.php';
        require_once __DIR__ . '/include/menu_list_helpers.php';
        require_once __DIR__ . '/include/ff_schreibaus.php';
        $menuTileHelpers = __DIR__ . '/include/menu_tile_helpers.php';
        if (is_readable($menuTileHelpers)) {
            require_once $menuTileHelpers;
            $maxX = ff_tisch_grid_max_x($conn);
            $maxXMobile = ff_tisch_grid_max_x_mobile($conn);
        } else {
            $n = (int)setting_get($conn, 'tisch_raster_spalten', '5');
            if ($n < 3) {
                $n = 3;
            }
            if ($n > 8) {
                $n = 8;
            }
            $maxX = $n;
            $nm = (int)setting_get($conn, 'tisch_raster_spalten_mobil', '5');
            if ($nm < 3) {
                $nm = 3;
            }
            if ($nm > 5) {
                $nm = 5;
            }
            $maxXMobile = $nm;
        }
        $chkEh = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_ehrengast'");
        if ($chkEh && mysqli_num_rows($chkEh) === 0) {
            @mysqli_query($conn, 'ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0');
        }
        $chkSm = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_sammelrechnung'");
        if ($chkSm && mysqli_num_rows($chkSm) === 0) {
            @mysqli_query($conn, 'ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0');
        }

        $paymentMode = ff_aktiver_payment_mode($conn);
        $openCond = ff_schreibaus_open_sql_condition($paymentMode);
        $kellnerCountFilter = ff_tisch_kellner_scope_filter_sql($conn, 'b');

        $sql = 'SELECT * FROM tische WHERE x>0 AND y>0 ORDER BY y,x';
        $result = mysqli_query($conn, $sql);
        $tischRows = [];
        if ($result) {
            while ($t = mysqli_fetch_assoc($result)) {
                $tischRows[] = $t;
            }
        }

        $dataMaxX = 0;
        $dataMaxY = 0;
        $byCell = [];
        foreach ($tischRows as $t) {
            $cx = (int)$t['x'];
            $cy = (int)$t['y'];
            $dataMaxX = max($dataMaxX, $cx);
            $dataMaxY = max($dataMaxY, $cy);
            $byCell[$cx . '_' . $cy] = $t;
        }

        $gridW = max((int)$maxX, $dataMaxX, 1);
        $gridH = max($dataMaxY, 1);
        $tcMobile = max((int)$maxXMobile, $dataMaxX, 1);
        if ($tcMobile > 12) {
            $tcMobile = 12;
        }

        $openMap = [];
        $unsentMap = [];
        if (count($tischRows) > 0) {
            $ids = [];
            foreach ($tischRows as $t) {
                $ids[] = (int)$t['tischnummer'];
            }
            $in = implode(',', $ids);
            $qOpen = 'SELECT b.tischnummer, COUNT(*) AS cnt FROM bestellungen b
                INNER JOIN positionen p ON p.rowid = b.position
                WHERE b.tischnummer IN (' . $in . ') AND ' . $openCond . $kellnerCountFilter . ' GROUP BY b.tischnummer';
            $rOpen = mysqli_query($conn, $qOpen);
            if ($rOpen) {
                while ($or = mysqli_fetch_assoc($rOpen)) {
                    $openMap[(int)$or['tischnummer']] = ((int)$or['cnt'] > 0);
                }
            }
            $qUnsent = 'SELECT b.tischnummer, COUNT(*) AS cnt FROM bestellungen b
                WHERE b.tischnummer IN (' . $in . ') AND ' . ff_tisch_unsent_sql_condition('b') . $kellnerCountFilter . ' GROUP BY b.tischnummer';
            $rUnsent = mysqli_query($conn, $qUnsent);
            if ($rUnsent) {
                while ($ur = mysqli_fetch_assoc($rUnsent)) {
                    $unsentMap[(int) $ur['tischnummer']] = (int) ($ur['cnt'] ?? 0);
                }
            }
        }

        $defaultFont = '#000000';
        ?>

        <div class="tisch-overview-grid" style="--tc: <?php echo (int)$gridW; ?>; --tc-mobile: <?php echo (int)$tcMobile; ?>;">
            <?php
            if (count($tischRows) === 0) {
                echo '<p class="text-muted">Keine Tische mit Koordinaten.</p>';
            } else {
                for ($gy = 1; $gy <= $gridH; $gy++) {
                    for ($gx = 1; $gx <= $gridW; $gx++) {
                        $key = $gx . '_' . $gy;
                        $row = $byCell[$key] ?? null;
                        if ($row === null) {
                            echo '<div class="col"><button type="button" class="btn w-100 py-3" disabled '
                                . 'style="color:' . $defaultFont . ';background:#FFFFFF;opacity:0.35;pointer-events:none;">&nbsp;</button></div>';
                            continue;
                        }

                        $tischnummerabfrage = (int)$row['tischnummer'];
                        $isEhrengast = (int)($row['is_ehrengast'] ?? 0);
                        $isSammel = (int)($row['is_sammelrechnung'] ?? 0);
                        $hasOpen = !empty($openMap[$tischnummerabfrage]);
                        $unsentCnt = (int) ($unsentMap[$tischnummerabfrage] ?? 0);
                        $hasUnsent = $unsentCnt > 0;

                        if ($isEhrengast === 1) {
                            $Colour = $hasOpen ? '#fae8ff' : '#ede9fe';
                            $style = 'tisch-ehrengast';
                        } elseif ($isSammel === 1) {
                            $Colour = $hasOpen ? '#fef3c7' : '#dbeafe';
                            $style = 'tisch-sammel';
                        } elseif ($hasOpen) {
                            $Colour = '#F5F599';
                            $style = 'tischy';
                        } else {
                            $Colour = 'LightGreen';
                            $style = 'tischgr';
                        }

                        if ($row['color'] !== '' && $row['color'] !== null) {
                            $fontColour = $row['color'];
                        } else {
                            $fontColour = '000000';
                        }

                        $unsentTitle = $hasUnsent
                            ? (' title="' . (int) $unsentCnt . ' Position(en) noch nicht abgeschickt"')
                            : '';
                        $colClass = 'col' . ($hasUnsent ? ' tisch-unsent-wrap' : '');
                        echo '<div class="' . htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8') . '">';
                        echo '<button type="button" class="btn w-100 py-3 ' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '" onclick="Tischnummer=' . (int)$row['tischnummer'] . ';tisch();"'
                            . $unsentTitle
                            . ' style="color:' . (strpos((string)$fontColour, '#') === 0 ? $fontColour : '#' . $fontColour) . ';background:' . $Colour . ';">';
                        echo htmlspecialchars($row['tischname'], ENT_QUOTES, 'UTF-8');
                        echo '</button></div>';
                    }
                }
            }

            mysqli_close($conn);
            echo '</div>';
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        ?>
    </div>
