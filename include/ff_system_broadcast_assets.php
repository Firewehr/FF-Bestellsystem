<?php
/** Overlay + Client-Poll (vor </body> auf index.php, index_hauptmenue.php, admin_page.php). */
require __DIR__ . '/ff_system_broadcast_overlay.php';
$ffSysBroadcastJsVer = (int) (@filemtime(__DIR__ . '/../js/system_broadcast.js') ?: 1);
?>
<script src="js/system_broadcast.js?v=<?php echo $ffSysBroadcastJsVer; ?>"></script>
