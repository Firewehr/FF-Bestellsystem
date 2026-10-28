<?php
if (!isset($_GET['legacy']) || $_GET['legacy'] !== '1') {
    header('Location: pilot/admin.html');
    exit;
}
require_once __DIR__ . '/include/ff_manage_admin.php';
require __DIR__ . '/admin_page.php';
