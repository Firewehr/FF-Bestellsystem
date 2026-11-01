<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_pilot_linked_articles.php';

$user = api_require_auth();
if ((int)($user['admin'] ?? 0) < 1) {
    api_error('Administrator-Berechtigung erforderlich.', 'forbidden', 403);
}

ff_pilot_linked_articles_ensure_schema($conn);
$data = api_get_request_data();
$action = (string)($data['action'] ?? 'list');
$parentId = (int)($data['parent_position_id'] ?? 0);
$festId = ff_pilot_linked_articles_active_fest_id($conn);

if ($action === 'list') {
    if ($parentId <= 0) {
        api_error('Ungültige Hauptposition.', 'invalid_parent', 400);
    }
    api_response(['ok' => true, 'links' => ff_pilot_linked_articles_for_parents($conn, [$parentId], $festId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Ungültige Anfrage.', 'method_not_allowed', 405);
}
api_require_csrf($data);
$pdo = db();

if ($action === 'save') {
    $childId = (int)($data['child_position_id'] ?? 0);
    $sortOrder = max(0, min(999999, (int)($data['sort_order'] ?? 0)));
    if ($parentId <= 0 || $childId <= 0 || $parentId === $childId) {
        api_error('Haupt- und verknüpfte Position müssen unterschiedlich sein.', 'invalid_link', 400);
    }
    if (!ff_pilot_linked_article_is_visible($conn, $parentId, $festId)
        || !ff_pilot_linked_article_is_visible($conn, $childId, $festId)) {
        api_error('Nur sichtbare Kellner-Artikel des aktiven Fests können verknüpft werden.', 'not_visible', 400);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO pilot_position_links (parent_position_id, child_position_id, sort_order)
         VALUES (:parent, :child, :sort)
         ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
    );
    $stmt->execute(['parent' => $parentId, 'child' => $childId, 'sort' => $sortOrder]);
    api_response(['ok' => true, 'links' => ff_pilot_linked_articles_for_parents($conn, [$parentId], $festId)]);
}

if ($action === 'delete') {
    $linkId = (int)($data['id'] ?? 0);
    if ($parentId <= 0 || $linkId <= 0) {
        api_error('Ungültige Verknüpfung.', 'invalid_link', 400);
    }
    $stmt = $pdo->prepare('DELETE FROM pilot_position_links WHERE id = :id AND parent_position_id = :parent');
    $stmt->execute(['id' => $linkId, 'parent' => $parentId]);
    api_response(['ok' => true, 'links' => ff_pilot_linked_articles_for_parents($conn, [$parentId], $festId)]);
}

api_error('Unbekannte Aktion.', 'unknown_action', 400);
