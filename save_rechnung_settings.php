<?php
require_once('auth.php');
require_once 'include/db.php';
require_once 'include/settings.php';
if (!isset($_SESSION['admin']) || $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$seller_name = trim($_POST['seller_name'] ?? '');
$seller_address = trim($_POST['seller_address'] ?? '');
$seller_uid = trim($_POST['seller_uid'] ?? '');
$token = trim($_POST['printer_token'] ?? '');
$offlineBackupToken = trim($_POST['offline_backup_token'] ?? '');
$festname = trim($_POST['rechnung_festname'] ?? '');
$thermo_bon_header = isset($_POST['thermo_bon_header']) ? (string)$_POST['thermo_bon_header'] : '';
$thermo_bon_footer = isset($_POST['thermo_bon_footer']) ? (string)$_POST['thermo_bon_footer'] : '';
$rechnung_thermo_footer = isset($_POST['rechnung_thermo_footer']) ? (string)$_POST['rechnung_thermo_footer'] : '';
$thermo_bon_header = mb_substr(trim($thermo_bon_header), 0, 2000, 'UTF-8');
$thermo_bon_footer = mb_substr(trim($thermo_bon_footer), 0, 2000, 'UTF-8');
$rechnung_thermo_footer = mb_substr(trim($rechnung_thermo_footer), 0, 2000, 'UTF-8');

settings_set($conn,'seller_name',$seller_name);
settings_set($conn,'seller_address',$seller_address);
settings_set($conn,'seller_uid',$seller_uid);
settings_set($conn,'printer_token',$token);
settings_set($conn,'offline_backup_token',$offlineBackupToken);
settings_set($conn,'rechnung_festname',$festname);
settings_set($conn,'thermo_bon_header',$thermo_bon_header);
settings_set($conn,'thermo_bon_footer',$thermo_bon_footer);
settings_set($conn,'rechnung_thermo_footer',$rechnung_thermo_footer);

echo 'ok';
