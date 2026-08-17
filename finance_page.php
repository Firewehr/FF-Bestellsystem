<?php
/**
 * Finanzverwaltung für index.php (Benutzer mit Finanz-Haken can_finance).
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_finance_schema.php';
require_once __DIR__ . '/include/user_landing.php';

if (!ff_finance_has_can_finance_flag($conn)) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3 mb-0">Keine Berechtigung. Finanzverwaltung muss beim Benutzer aktiviert sein (Finanz-Haken).</div>';
    exit;
}

ff_finance_ensure_schema($conn);
require_once __DIR__ . '/include/admin_finanzen_body.php';
require_once __DIR__ . '/include/admin_finanzen_extended_body.php';

$finJs = @filemtime(__DIR__ . '/js/admin_finance.js') ?: 1;
$gewJs = @filemtime(__DIR__ . '/js/finance_index.js') ?: 1;
$isSuperAdmin = (int) ($_SESSION['admin'] ?? 0) === 2;
$isFinanceAdmin = (int) ($_SESSION['admin'] ?? 0) >= 1;
?>
<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Menü</a>
        <span class="navbar-brand mb-0 ms-2">Finanzen</span>
    </div>
</nav>
<div class="app-content py-3">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <?php
            ff_admin_render_finanzen_body($conn);
            ff_admin_render_finanzen_extended_body($conn);
            ?>
        </div>
    </div>
</div>
<script>window.ffIsSuperAdmin=<?php echo $isSuperAdmin ? 'true' : 'false'; ?>;window.ffIsFinanceAdmin=<?php echo $isFinanceAdmin ? 'true' : 'false'; ?>;</script>
<script src="js/finance_index.js?v=<?php echo (int) $gewJs; ?>"></script>
<script src="js/admin_finance.js?v=<?php echo (int) $finJs; ?>"></script>
<script>
(function() {
    if (typeof gewinnAktualisieren === 'function') gewinnAktualisieren();
    if (typeof ffFinanceInit === 'function') ffFinanceInit();
})();
</script>
