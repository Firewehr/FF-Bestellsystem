<?php
// ESC/POS friendly text formatting helpers (42 columns default)

function pt_line($left, $right = '', $width = 42){
    $l = mb_strimwidth((string)$left, 0, $width, '', 'UTF-8');
    $r = mb_strimwidth((string)$right, 0, $width, '', 'UTF-8');
    $space = $width - mb_strlen($l, 'UTF-8') - mb_strlen($r, 'UTF-8');
    if($space < 1) $space = 1;
    return $l . str_repeat(' ', $space) . $r;
}

function pt_money($v){
    return number_format((float)$v, 2, ',', '.') . ' EUR';
}

function pt_header($sellerName, $sellerAddrLines = [], $sellerUid = ''){
    $out = [];
    $out[] = (string)$sellerName;
    foreach($sellerAddrLines as $ln){
        $ln = trim((string)$ln);
        if($ln !== '') $out[] = $ln;
    }
    if(trim((string)$sellerUid) !== '') $out[] = 'UID/ZVR: ' . $sellerUid;
    $out[] = str_repeat('-', 42);
    return $out;
}
?>
