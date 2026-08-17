<?php
declare(strict_types=1);

/**
 * CSV-Zeile schreiben (PHP 8.4+: $escape explizit, sonst Deprecation in der Ausgabe).
 */
function ff_csv_fputcsv($handle, array $fields, string $delimiter = ';'): bool
{
    return fputcsv($handle, $fields, $delimiter, '"', '\\') !== false;
}
