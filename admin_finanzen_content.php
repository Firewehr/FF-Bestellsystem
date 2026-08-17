<?php
/**
 * Nur Finanzen-HTML (card-body-Inhalt) für AJAX-Nachladen, wenn #Finanzen im DOM fehlt.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

require_once __DIR__ . '/include/ff_finance_auth.php';
ff_finance_require($conn, false);

require_once __DIR__ . '/include/ff_finance_schema.php';
ff_finance_ensure_schema($conn);
require_once __DIR__ . '/include/admin_finanzen_body.php';
require_once __DIR__ . '/include/admin_finanzen_extended_body.php';
ff_admin_render_finanzen_body($conn);
ff_admin_render_finanzen_extended_body($conn);
