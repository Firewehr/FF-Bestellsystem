<?php
/**
 * CSV-/Berichts-Helfer für Fest-Steuerpaket und Festabschluss.
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_csv_export.php';

/** Excel-freundlich: UTF-8 BOM + Semikolon */
function ff_fest_csv_open_stream()
{
    $fh = fopen('php://temp', 'r+');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));

    return $fh;
}

function ff_fest_csv_write_row($fh, array $cols): void
{
    ff_csv_fputcsv($fh, $cols, ';');
}

function ff_fest_stream_to_string($fh): string
{
    rewind($fh);
    $s = stream_get_contents($fh);
    fclose($fh);

    return $s === false ? '' : $s;
}

/** Bezahlte Verkaufszeilen für Fest (ohne Gratis/Schreibaus), analog Dashboard */
/**
 * Abgeschlossene Kassen-Sessions (optional ab Fest-Datum), inkl. Bewegungssummen.
 *
 * @return list<array{session:array<string,mixed>,entnahmen:float,zuzahlungen:float}>
 */
function ff_fest_kassen_closed_sessions(mysqli $conn, ?string $festDatumYmd = null): array
{
    require_once __DIR__ . '/ff_finance_bereich_helpers.php';
    ff_finance_ensure_schema($conn);

    $where = "s.status = 'closed'";
    if ($festDatumYmd !== null && $festDatumYmd !== '' && $festDatumYmd !== '0000-00-00') {
        $dEsc = mysqli_real_escape_string($conn, $festDatumYmd);
        $where .= " AND DATE(s.closed_at) >= '{$dEsc}'";
    }

    $rows = [];
    $sql = "SELECT s.*, b.name AS bereich_name FROM kassen_sessions s
        JOIN kassen_bereiche b ON b.id = s.bereich_id
        WHERE {$where}
        ORDER BY s.closed_at ASC";
    $res = mysqli_query($conn, $sql);
    while ($res && ($s = mysqli_fetch_assoc($res))) {
        $sid = (int) ($s['id'] ?? 0);
        $mov = ff_kassen_session_movements_sum($conn, $sid);
        $rows[] = ['session' => $s, 'entnahmen' => $mov['entnahmen'], 'zuzahlungen' => $mov['zuzahlungen']];
    }

    return $rows;
}

function ff_fest_paid_lines_where_sql(int $festId): string
{
    $fid = (int) $festId;

    return "b.`delete`=0
        AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>'' AND b.timestampBezahlung<>'0000-00-00 00:00:00'
        AND COALESCE(b.is_gratis,0)=0 AND COALESCE(b.schreibaus,0)=0
        AND b.fest_id = {$fid}";
}
