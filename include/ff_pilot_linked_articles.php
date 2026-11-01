<?php
declare(strict_types=1);

/**
 * Real article links for the Pilot waiter app. These are deliberately separate
 * from legacy "beilagen", which remain text/price annotations.
 */
require_once __DIR__ . '/ff_schema_helpers.php';

function ff_pilot_linked_articles_ensure_schema(mysqli $conn): void
{
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
    $result = mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv = 1 LIMIT 1');
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
    $sql = 'SELECT rowid FROM positionen WHERE rowid = ? AND COALESCE(kassa_only, 0) = 0'
        . ($festId > 0 ? ' AND (fest_id IS NULL OR fest_id = ?)' : '')
        . ' LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    if ($festId > 0) {
        mysqli_stmt_bind_param($stmt, 'ii', $positionId, $festId);
    } else {
        mysqli_stmt_bind_param($stmt, 'i', $positionId);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $visible = $result && mysqli_num_rows($result) === 1;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);

    return $visible;
}

/**
 * @return list<array{id:int,child_position_id:int,name:string,price:float,sort_order:int}>
 */
function ff_pilot_linked_articles_for_parents(mysqli $conn, array $parentIds, int $festId): array
{
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), static fn(int $id): bool => $id > 0)));
    if ($parentIds === []) {
        return [];
    }
    $parentList = implode(',', $parentIds);
    $festFilter = '';
    if ($festId > 0) {
        $festFilter = ' AND (parent.fest_id IS NULL OR parent.fest_id = ?) AND (child.fest_id IS NULL OR child.fest_id = ?)';
    }
    $sql = 'SELECT l.id, l.parent_position_id, l.child_position_id, l.sort_order, child.Positionsname, child.Betrag
            FROM pilot_position_links l
            INNER JOIN positionen parent ON parent.rowid = l.parent_position_id AND COALESCE(parent.kassa_only, 0) = 0
            INNER JOIN positionen child ON child.rowid = l.child_position_id AND COALESCE(child.kassa_only, 0) = 0
            WHERE l.parent_position_id IN (' . $parentList . ')' . $festFilter . '
            ORDER BY l.parent_position_id ASC, l.sort_order ASC, l.id ASC';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    if ($festId > 0) {
        mysqli_stmt_bind_param($stmt, 'ii', $festId, $festId);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = [
            'id' => (int)$row['id'],
            'parent_position_id' => (int)$row['parent_position_id'],
            'child_position_id' => (int)$row['child_position_id'],
            'name' => (string)$row['Positionsname'],
            'price' => (float)$row['Betrag'],
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
 * Validates only explicit linked-child metadata. Ordinary cart lines stay
 * unrestricted and are handled by the normal order validation.
 */
function ff_pilot_validate_linked_order_items(mysqli $conn, array $items, int $festId): ?string
{
    $parentCounts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $positionId = (int)($item['position_id'] ?? $item['id'] ?? 0);
        $qty = max(0, min(50, (int)($item['quantity'] ?? $item['qty'] ?? 1)));
        if ($positionId > 0 && $qty > 0) {
            $parentCounts[$positionId] = ($parentCounts[$positionId] ?? 0) + $qty;
        }
    }

    $linkedCounts = [];
    foreach ($items as $item) {
        if (!is_array($item) || !array_key_exists('linked_parent_id', $item)) {
            continue;
        }
        $parentId = (int)$item['linked_parent_id'];
        $childId = (int)($item['position_id'] ?? $item['id'] ?? 0);
        $qty = (int)($item['quantity'] ?? $item['qty'] ?? 1);
        if ($parentId <= 0 || $childId <= 0 || $qty < 1 || $parentId === $childId) {
            return 'Ungültige Verknüpfung eines Artikels.';
        }
        if (($parentCounts[$parentId] ?? 0) < $qty) {
            return 'Verknüpfte Artikel benötigen die zugehörige Hauptposition.';
        }
        $linkRows = ff_pilot_linked_articles_for_parents($conn, [$parentId], $festId);
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
