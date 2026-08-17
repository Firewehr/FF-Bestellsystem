<?php
/**
 * Benutzerliste im Admin (Karten-Layout). Erwartet $conn, $sessionAdminLevel, $sessionUsername,
 * $admin_landing_print_targets, $has_landing_pt, $dvBonPtList.
 */
declare(strict_types=1);

if (!function_exists('out')) {
    function out($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

echo '<div class="ff-users-toolbar d-flex flex-wrap align-items-center gap-2 mb-3">';
$userCount = 0;
$ucRes = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM users');
if ($ucRes && ($ucRow = mysqli_fetch_assoc($ucRes))) {
    $userCount = (int) ($ucRow['c'] ?? 0);
}
echo '<span class="small text-muted"><strong>' . $userCount . '</strong> Konten</span>';
echo '<div class="dropdown ms-auto">';
echo '<button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">Hilfe</button>';
echo '<ul class="dropdown-menu dropdown-menu-end small p-2" style="max-width:22rem">';
echo '<li class="px-2 py-1"><strong>Status rot:</strong> inaktiv oder Zeitfenster abgelaufen / noch nicht begonnen.</li>';
echo '<li class="px-2 py-1"><strong>Passwort:</strong> Zurücksetzen für andere; eigenes Konto über „Passwort ändern“.</li>';
echo '<li class="px-2 py-1"><strong>Abholbon:</strong> nur Direktverkauf (Kasse). Thermo-Druckziel nach Bezahlen. Bon-Nr unter Admin → <em>Nummern</em> (nächster Bon = Zähler + 1).</li>';
echo '</ul></div></div>';

echo '<div id="myUsers" class="ff-users-grid">';

$sql = 'SELECT * FROM `users` ORDER BY username LIMIT 100';
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $uid = (int) $row['id'];
    $ulevel = (int) $row['admin'];
    $isSelf = ($row['username'] === $sessionUsername);
    $uIsActive = (int) ($row['is_active'] ?? 1);
    $uAcfrom = ff_user_status_dt_local($row['active_from'] ?? null);
    $uAcuntil = ff_user_status_dt_local($row['active_until'] ?? null);
    $winLabel = ff_user_status_window_label($row['active_from'] ?? null, $row['active_until'] ?? null);
    $winHint = ff_user_status_window_hint($row);
    $effActive = ff_user_status_effective_active($row, $conn);
    $canEditStatus = !$isSelf && $ulevel !== 2;
    $canDeleteUser = !$isSelf && $ulevel !== 2;
    if ($canDeleteUser) {
        if ($sessionAdminLevel >= 2) {
            // ok
        } elseif ($sessionAdminLevel === 1 && $ulevel === 0) {
            // ok
        } else {
            $canDeleteUser = false;
        }
    }
    $canResetPw = !$isSelf && !($ulevel === 2 && $sessionAdminLevel < 2);
    $dnVal = (string) ($row['display_name'] ?? '');
    $canEditDn = $isSelf || ($sessionAdminLevel >= 2) || ($sessionAdminLevel === 1 && $ulevel !== 2);

    echo '<article class="ff-user-card card shadow-sm" data-userid="' . $uid . '">';
    echo '<div class="card-body p-3">';

    echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">';
    echo '<span class="fw-semibold text-nowrap">' . out($row['username']);
    if ($isSelf) {
        echo ' <span class="badge text-bg-secondary">Sie</span>';
    }
    echo '</span>';

    echo '<span class="text-muted small">·</span>';
    if ($ulevel === 2) {
        echo '<span class="small">' . out(user_rights_label($ulevel)) . '</span>';
    } elseif ($isSelf) {
        echo '<span class="small text-muted">' . out(user_rights_label($ulevel)) . '</span>';
    } else {
        echo '<select class="form-select form-select-sm user-admin-select" style="width:auto;min-width:9rem" data-userid="' . $uid . '" data-prev="' . $ulevel . '">';
        echo '<option value="0"' . ($ulevel === 0 ? ' selected' : '') . '>Benutzer</option>';
        echo '<option value="1"' . ($ulevel === 1 ? ' selected' : '') . '>Administrator</option>';
        echo '</select>';
    }

    echo '<span class="text-muted small d-none d-md-inline">·</span>';
    echo '<div class="flex-grow-1" style="min-width:10rem;max-width:18rem">';
    if ($canEditDn) {
        echo '<input type="text" class="form-control form-control-sm user-display-name-input" data-userid="' . $uid . '" data-prev="' . htmlspecialchars($dnVal, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($dnVal, ENT_QUOTES, 'UTF-8') . '" placeholder="Anzeigename (optional)" maxlength="120">';
    } elseif ($dnVal !== '') {
        echo '<span class="small text-muted">' . out($dnVal) . '</span>';
    }
    echo '</div>';

    echo '<div class="d-flex flex-wrap gap-1 align-items-center ms-md-auto">';
    $badgeCls = $effActive ? 'text-bg-success' : 'text-bg-danger';
    $badgeLbl = $effActive ? 'aktiv' : 'inaktiv';
    echo '<span class="badge ' . $badgeCls . ' ff-user-status-badge" data-userid="' . $uid . '">' . $badgeLbl . '</span>';
    if ($winHint !== '') {
        echo '<span class="badge text-bg-warning text-dark ff-user-window-hint" data-userid="' . $uid . '">' . out($winHint) . '</span>';
    }
    if ($ulevel === 0 && !$isSelf) {
        $uPerms = ff_user_permissions_decode($row);
        $uSum = ff_user_permissions_summary($uPerms, $conn);
        echo '<button type="button" class="btn btn-outline-secondary btn-sm btn-user-perms" data-userid="' . $uid . '" data-username="' . htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($uSum, ENT_QUOTES, 'UTF-8') . '">Rechte</button>';
    }
    if ($isSelf) {
        echo '<button type="button" class="btn btn-outline-primary btn-sm btn-user-pw-own">Passwort ändern</button>';
    } elseif ($canResetPw) {
        echo '<button type="button" class="btn btn-outline-primary btn-sm btn-user-pw-reset" data-userid="' . $uid . '" data-username="' . htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') . '">Passwort</button>';
    }
    if ($canDeleteUser) {
        echo '<button type="button" class="btn btn-outline-danger btn-sm btn-user-delete" data-userid="' . $uid . '" data-username="' . htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') . '">Löschen</button>';
    }
    echo '</div></div>';

    echo '<div class="small mb-2 ff-user-status-meta" data-userid="' . $uid . '">';
    if ($canEditStatus) {
        echo '<label class="form-check-label me-3"><input type="checkbox" class="form-check-input user-inactive-check" data-userid="' . $uid . '" data-prev="' . ($uIsActive ? '0' : '1') . '"' . ($uIsActive ? '' : ' checked') . '> manuell inaktiv</label>';
        echo '<button type="button" class="btn btn-link btn-sm p-0 align-baseline btn-user-status" data-userid="' . $uid . '" data-username="' . htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8') . '" data-from="' . htmlspecialchars($uAcfrom, ENT_QUOTES, 'UTF-8') . '" data-until="' . htmlspecialchars($uAcuntil, ENT_QUOTES, 'UTF-8') . '">Aktivitäts-Zeitfenster …</button>';
    }
    if ($winLabel !== '') {
        echo '<span class="text-muted user-status-window" data-userid="' . $uid . '"> · Fenster: <strong>' . out($winLabel) . '</strong></span>';
    } else {
        echo '<span class="text-muted user-status-window" data-userid="' . $uid . '"></span>';
    }
    echo '<span class="text-muted ms-2">· angelegt ' . out($row['timestamp']) . '</span>';
    echo '</div>';

    if ($ulevel === 0) {
        $usp = isset($row['start_page']) ? ff_user_normalize_start_page((string) $row['start_page']) : 'menu';
        $uspt = isset($row['start_print_target']) ? (int) $row['start_print_target'] : 0;
        if ($usp === 'print_target' && !$has_landing_pt) {
            $usp = 'menu';
        }
        echo '<details class="ff-user-card-details small">';
        echo '<summary class="text-muted">Weitere Einstellungen (Start, Direktverkauf-Abholbon)</summary>';
        echo '<div class="pt-2 row g-2">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label small mb-0">Start nach Login</label>';
        $ptSelDisplay = ($usp === 'print_target') ? '' : 'none';
        echo '<select class="form-select form-select-sm user-start-page-select" data-userid="' . $uid . '" data-prev-page="' . out($usp) . '"' . ($has_landing_pt ? '' : ' data-no-print-targets="1"') . '>';
        $optKeys = ['menu', 'tische', 'kueche', 'schank', 'direktverkauf', 'print_target'];
        $optLabels = ['menu' => 'Hauptmenü', 'tische' => 'Kellner (Tische)', 'kueche' => 'Küche', 'schank' => 'Schank', 'direktverkauf' => 'Kasse', 'print_target' => 'Druckziel …'];
        foreach ($optKeys as $ok) {
            if ($ok === 'print_target' && !$has_landing_pt) {
                continue;
            }
            echo '<option value="' . out($ok) . '"' . ($usp === $ok ? ' selected' : '') . '>' . out($optLabels[$ok]) . '</option>';
        }
        echo '</select>';
        if ($has_landing_pt) {
            echo '<div class="user-start-pt-wrap mt-1" data-userid="' . $uid . '" style="display:' . ($ptSelDisplay === '' ? 'block' : 'none') . '">';
            echo '<select class="form-select form-select-sm user-start-pt-select" data-userid="' . $uid . '" data-prev-pt="' . (int) $uspt . '">';
            foreach ($admin_landing_print_targets as $ptx) {
                $pid = (int) $ptx['print_target'];
                echo '<option value="' . $pid . '"' . ($uspt === $pid ? ' selected' : '') . '>' . out($ptx['name']) . '</option>';
            }
            echo '</select></div>';
        }
        echo '</div>';
        echo '<div class="col-md-6">';
        echo '<label class="form-label small mb-0">Direktverkauf · Abholbon (Thermo)</label>';
        $dvCurr = (isset($row['dv_abholbon_print_target']) && $row['dv_abholbon_print_target'] !== null && $row['dv_abholbon_print_target'] !== '')
            ? (int) $row['dv_abholbon_print_target'] : null;
        $prevDv = $dvCurr === null ? '' : (string) $dvCurr;
        echo '<select class="form-select form-select-sm user-dv-bon-target-select" data-userid="' . $uid . '" data-prev="' . out($prevDv) . '">';
        echo '<option value=""' . ($dvCurr === null ? ' selected' : '') . '>Automatisch</option>';
        foreach ($dvBonPtList as $dpt) {
            $pid = (int) $dpt['print_target'];
            echo '<option value="' . $pid . '"' . ($dvCurr === $pid ? ' selected' : '') . '>' . out($dpt['name']) . '</option>';
        }
        echo '</select>';
        echo '<div class="form-text">Nur Kasse/Direktverkauf: Thermo-Bon mit Abholnummer nach „Bezahlen“. '
            . 'Automatisch = laut Startseite (Küche/Schank/Druckziel). '
            . 'Die Bon-Nummer vergibt das System global (Admin → Nummern: nächster DV-Abholbon = Zähler + 1).</div>';
        echo '</div></div></details>';
    }

    echo '</div></article>';
}

echo '</div>';
