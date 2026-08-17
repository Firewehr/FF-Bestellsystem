<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_finance_schema.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/admin_finanzen_body.php';

ff_finance_require($conn, false);
ff_admin_render_buchungen_tbody_rows($conn);
