<?php
declare(strict_types=1);

require_once __DIR__ . '/ff_schema_gate.php';

/**
 * Real article links for the Pilot waiter app. These are deliberately separate
 * from legacy "beilagen", which remain text/price annotations.
 */
require_once __DIR__ . '/ff_schema_helpers.php';
require_once __DIR__ . '/menu_lock_helpers.php';

function ff_pilot_linked_articles_ensure_schema(mysqli $conn): void
{
    if (ff_schema_is_current($conn)) {
        return; // Schema laut Migrationslauf aktuell (include/ff_schema_gate.php)
    }
    ff_ensure_table(
        $conn,
        'pilot_position_links',
        'CREATE TABLE IF NOT EXISTS `pilot_position_links` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `parent_position_id` INT NOT NULL,
            `child_position_id` INT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pilot_position_link` (`parent_position_id`, `child_position_id`),
            KEY `idx_pilot_position_link_parent` (`parent_position_id`, `sort_order`),
            KEY `idx_pilot_position_link_child` (`child_position_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ff_pilot_linked_articles_active_fest_id(mysqli $conn): int
{
    $result = ff_mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv = 1 LIMIT 1');
    $row = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }

    return (int)($row['id'] ?? 0);
}

/**
 * A linked article must be available in the active Pilot waiter menu.
 */
function ff_pilot_linked_article_is_visible(mysqli $conn, int $positionId, int $festId): bool
{
    if ($positionId <= 0) {
        return false;
    }
    $sql = 'SELECT p.rowid FROM positionen p
            LEFT JOIN position_subcategories s ON s.id = p.subcategory_id
            WHERE p.rowid = ?
              AND p.type IN (1, 2)
              AND COALESCE(p.kassa_only, 0) = 0
              AND COALESCE(s.kassa_only, 0) = 0'
        . ($festId > 0 ? ' AND (p.fest_id IS NULL OR p.fest_id = ?)' : '')
        . ' LIMIT 1';
    $stmt = ff_mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    if ($festId > 0) {
        mysqli_stmt_bind_param($stmt, 'ii', $positionId, $festId);
    } else {
        mysqli_stmt_bind_param($stmt, 'i', $positionId);
    }
    ff_mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $visible = $result && mysqli_num_rows($result) === 1;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);

    return $visible;
}

/**
 * @return list<array{id:int,parent_position_id:int,child_position_id:int,name:string,price:float,type:int,sort_order:int}>
 */
function ff_pilot_linked_articles_for_parents(mysqli $conn, array $parentIds, int $festId): array
{
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), static fn(int $id): bool => $id > 0)));
    if ($parentIds === []) {
        return [];
    }
    [$parentList, $parentT, $parentV] = ff_db_in_ints($parentIds);
    $festFilter = '';
    if ($festId > 0) {
        $festFilter = ' AND (parent.fest_id IS NULL OR parent.fest_id = ?) AND (child.fest_id IS NULL OR child.fest_id = ?)';
    }
    $sql = 'SELECT l.id, l.parent_position_id, l.child_position_id, l.sort_order, child.Positionsname, child.Betrag, child.type
            FROM pilot_position_links l
            INNER JOIN positionen parent ON parent.rowid = l.parent_position_id
                AND parent.type IN (1, 2) AND COALESCE(parent.kassa_only, 0) = 0
            LEFT JOIN position_subcategories parent_sub ON parent_sub.id = parent.subcategory_id
            INNER JOIN positionen child ON child.rowid = l.child_position_id
                AND child.type IN (1, 2) AND COALESCE(child.kassa_only, 0) = 0
            LEFT JOIN position_subcategories child_sub ON child_sub.id = child.subcategory_id
            WHERE COALESCE(parent_sub.kassa_only, 0) = 0
              AND COALESCE(child_sub.kassa_only, 0) = 0
              AND l.parent_position_id IN (' . $parentList . ')' . $festFilter . '
            ORDER BY l.parent_position_id ASC, l.sort_order ASC, l.id ASC';
    $stmt = ff_mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    // Reihenfolge wie im SQL: Eltern-IDs (IN), dann Fest-Filter
    ff_mysqli_stmt_bind(
        $stmt,
        $parentT . ($festId > 0 ? 'ii' : ''),
        array_merge($parentV, $festId > 0 ? [$festId, $festId] : [])
    );
    ff_mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = [
            'id' => (int)$row['id'],
            'parent_position_id' => (int)$row['parent_position_id'],
            'child_position_id' => (int)$row['child_position_id'],
            'name' => (string)$row['Positionsname'],
            'price' => (float)$row['Betrag'],
            'type' => (int)$row['type'],
            'sort_order' => (int)$row['sort_order'],
        ];
    }
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

/**
 * Customer QR menus additionally hide temporarily locked linked articles.
 *
 * @return list<array{id:int,parent_position_id:int,child_position_id:int,name:string,price:float,type:int,sort_order:int}>
 */
function ff_customer_linked_articles_for_parents(mysqli $conn, array $parentIds, int $festId): array
{
    $links = ff_pilot_linked_articles_for_parents($conn, $parentIds, $festId);

    return array_values(array_filter($links, static function (array $link) use ($conn): bool {
        return !menu_lock_is_blocked(
            $conn,
            (int)$link['child_position_id'],
            (int)$link['type']
        );
    }));
}

/**
 * Validates only explicit linked-child metadata. Ordinary cart lines stay
 * unrestricted and are handled by the normal order validation.
 */
function ff_pilot_validate_linked_order_items(
    mysqli $conn,
    array $items,
    int $festId,
    bool $customerMenuOnly = false
): ?string
{
    $parentCounts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $positionId = (int)($item['position_id'] ?? $item['id'] ?? 0);
        $qty = max(0, min(50, (int)($item['quantity'] ?? $item['qty'] ?? $item['menge'] ?? 1)));
        if ($positionId > 0 && $qty > 0) {
            $parentCounts[$positionId] = ($parentCounts[$positionId] ?? 0) + $qty;
        }
    }

    $linkedCounts = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['linked_parent_id'])) {
            continue;
        }
        $parentId = (int)$item['linked_parent_id'];
        $childId = (int)($item['position_id'] ?? $item['id'] ?? 0);
        $qty = (int)($item['quantity'] ?? $item['qty'] ?? $item['menge'] ?? 1);
        if ($parentId <= 0 || $childId <= 0 || $qty < 1 || $parentId === $childId) {
            return 'Ungültige Verknüpfung eines Artikels.';
        }
        if (($parentCounts[$parentId] ?? 0) < $qty) {
            return 'Verknüpfte Artikel benötigen die zugehörige Hauptposition.';
        }
        $linkRows = $customerMenuOnly
            ? ff_customer_linked_articles_for_parents($conn, [$parentId], $festId)
            : ff_pilot_linked_articles_for_parents($conn, [$parentId], $festId);
        $allowed = false;
        foreach ($linkRows as $link) {
            if ((int)$link['child_position_id'] === $childId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return 'Die gewählte Verknüpfung ist nicht mehr verfügbar.';
        }
        $key = $parentId . ':' . $childId;
        $linkedCounts[$key] = ($linkedCounts[$key] ?? 0) + $qty;
        if ($linkedCounts[$key] > ($parentCounts[$parentId] ?? 0)) {
            return 'Ein verknüpfter Artikel darf nur einmal je Hauptposition gewählt werden.';
        }
    }

    return null;
}
