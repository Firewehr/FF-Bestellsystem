<?php
declare(strict_types=1);

require_once __DIR__ . '/ff_rezepturen_schema.php';

function ff_order_recipe_components(mysqli $conn, int $positionId): array
{
    if ($positionId <= 0) {
        return [];
    }
    ff_rezepturen_ensure_schema($conn);

    $stmt = mysqli_prepare(
        $conn,
        'SELECT pr.bestandteil_id, pr.menge, pr.optional_enabled, pr.default_selected,
                pr.option_group, b.name, b.einheit, b.kapazitaet
         FROM position_rezepturen pr
         INNER JOIN bestandteile b ON b.id = pr.bestandteil_id
         WHERE pr.position_id = ? AND b.aktiv = 1
         ORDER BY pr.reihenfolge, pr.id'
    );
    if (!$stmt) {
        throw new RuntimeException('recipe_components_prepare_failed');
    }
    mysqli_stmt_bind_param($stmt, 'i', $positionId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('recipe_components_query_failed');
    }
    $result = mysqli_stmt_get_result($stmt);
    $components = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $components[] = [
            'bestandteil_id' => (int) $row['bestandteil_id'],
            'name' => (string) $row['name'],
            'einheit' => (string) ($row['einheit'] ?? 'Stk'),
            'menge' => (float) $row['menge'],
            'optional_enabled' => (int) $row['optional_enabled'] === 1,
            'default_selected' => (int) $row['default_selected'] === 1,
            'option_group' => (string) ($row['option_group'] ?? ''),
            'kapazitaet' => (int) ($row['kapazitaet'] ?? -1),
        ];
    }
    mysqli_stmt_close($stmt);

    return $components;
}

function ff_order_recipe_selection(array $components, ?array $selectedIds): array
{
    $requested = [];
    if ($selectedIds !== null) {
        foreach ($selectedIds as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $requested[(int) $id] = true;
            }
        }
    }

    $selected = [];
    foreach ($components as $component) {
        $id = (int) $component['bestandteil_id'];
        $isOptional = (bool) $component['optional_enabled'];
        $isSelected = $selectedIds === null
            ? (!$isOptional || (bool) $component['default_selected'])
            : (!$isOptional || isset($requested[$id]));
        if ($isSelected) {
            $selected[] = $component;
        }
    }

    return $selected;
}

function ff_order_recipe_save_snapshot(mysqli $conn, int $orderRowId, array $allComponents, array $selectedComponents): void
{
    if ($orderRowId <= 0) {
        throw new InvalidArgumentException('bad_order_rowid');
    }
    $header = mysqli_prepare(
        $conn,
        'INSERT INTO bestellung_rezeptur_snapshots (bestellung_rowid) VALUES (?)
         ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP'
    );
    if (!$header) {
        throw new RuntimeException('recipe_snapshot_prepare_failed');
    }
    mysqli_stmt_bind_param($header, 'i', $orderRowId);
    if (!mysqli_stmt_execute($header)) {
        mysqli_stmt_close($header);
        throw new RuntimeException('recipe_snapshot_save_failed');
    }
    mysqli_stmt_close($header);

    $delete = mysqli_prepare($conn, 'DELETE FROM bestellung_rezeptur_komponenten WHERE bestellung_rowid = ?');
    if (!$delete) {
        throw new RuntimeException('recipe_components_delete_prepare_failed');
    }
    mysqli_stmt_bind_param($delete, 'i', $orderRowId);
    if (!mysqli_stmt_execute($delete)) {
        mysqli_stmt_close($delete);
        throw new RuntimeException('recipe_components_delete_failed');
    }
    mysqli_stmt_close($delete);

    if ($allComponents === []) {
        return;
    }
    $insert = mysqli_prepare(
        $conn,
        'INSERT INTO bestellung_rezeptur_komponenten
            (bestellung_rowid, bestandteil_id, name_snapshot, einheit_snapshot, menge, optional_enabled, default_selected, selected)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('recipe_components_insert_prepare_failed');
    }
    $selectedIds = [];
    foreach ($selectedComponents as $component) {
        $selectedIds[(int) ($component['bestandteil_id'] ?? 0)] = true;
    }
    foreach ($allComponents as $component) {
        $componentId = (int) $component['bestandteil_id'];
        $name = (string) $component['name'];
        $unit = (string) ($component['einheit'] ?? 'Stk');
        $quantity = (float) $component['menge'];
        $optional = !empty($component['optional_enabled']) ? 1 : 0;
        $defaultSelected = !empty($component['default_selected']) ? 1 : 0;
        $selected = isset($selectedIds[$componentId]) ? 1 : 0;
        mysqli_stmt_bind_param($insert, 'iissdiii', $orderRowId, $componentId, $name, $unit, $quantity, $optional, $defaultSelected, $selected);
        if (!mysqli_stmt_execute($insert)) {
            mysqli_stmt_close($insert);
            throw new RuntimeException('recipe_components_insert_failed');
        }
    }
    mysqli_stmt_close($insert);
}

function ff_order_recipe_selection_map(mysqli $conn, array $orderRowIds, array $defaultsByOrder = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderRowIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    ff_rezepturen_ensure_schema($conn);
    $idList = implode(',', $ids);
    $snapshots = [];
    $result = mysqli_query(
        $conn,
        'SELECT bestellung_rowid FROM bestellung_rezeptur_snapshots WHERE bestellung_rowid IN (' . $idList . ')'
    );
    if (!$result) {
        throw new RuntimeException('recipe_snapshot_lookup_failed');
    }
    while ($row = mysqli_fetch_assoc($result)) {
        $snapshots[(int) $row['bestellung_rowid']] = true;
    }
    mysqli_free_result($result);

    $selections = [];
    $result = mysqli_query(
        $conn,
        'SELECT bestellung_rowid, bestandteil_id
         FROM bestellung_rezeptur_komponenten
         WHERE bestellung_rowid IN (' . $idList . ') AND selected = 1'
    );
    if (!$result) {
        throw new RuntimeException('recipe_components_lookup_failed');
    }
    while ($row = mysqli_fetch_assoc($result)) {
        $rowId = (int) $row['bestellung_rowid'];
        $selections[$rowId][] = (int) $row['bestandteil_id'];
    }
    mysqli_free_result($result);

    foreach ($ids as $id) {
        if (!isset($snapshots[$id])) {
            $selections[$id] = array_values(array_unique(array_map('intval', $defaultsByOrder[$id] ?? [])));
        } elseif (!isset($selections[$id])) {
            $selections[$id] = [];
        }
    }

    return $selections;
}
