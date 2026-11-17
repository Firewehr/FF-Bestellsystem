<?php
/**
 * Druckwarteschlange (printer_jobs): hängende Jobs wiederherstellen, Problem-Jobs
 * auflisten, erneut drucken oder verwerfen.
 *
 * Wiederherstellung (ff_printer_jobs_recover_stuck):
 *  - Ein Job bleibt in `reserved`, wenn der Druck-Client nach dem Abholen abstürzt
 *    oder die Verbindung verliert.
 *  - Ist er länger als `printer_job_stuck_reserved_min` (Standard 10 min) reserviert
 *    UND jünger als `printer_job_requeue_max_age_min` (Standard 120 min), wird er
 *    wieder auf `pending` gesetzt und erneut gedruckt.
 *  - Ältere oder zu oft versuchte Jobs werden NICHT automatisch gedruckt (sonst käme
 *    z. B. ein Bon von gestern plötzlich in der Küche heraus), sondern auf `error`
 *    gesetzt. Der Admin entscheidet dann: erneut drucken oder verwerfen.
 *
 * Verwerfen löscht nur den Druckauftrag. Rechnungen/Bestellungen bleiben unberührt.
 */
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const FF_PRINTER_JOB_TYPES_SQL = "'kellner_bon', 'rechnung_thermo'";

/** @return array{stuck_min:int, max_age_min:int, max_attempts:int} */
function ff_printer_jobs_config(mysqli $conn): array
{
    return [
        'stuck_min' => max(1, (int)setting_get($conn, 'printer_job_stuck_reserved_min', '10')),
        'max_age_min' => max(5, (int)setting_get($conn, 'printer_job_requeue_max_age_min', '120')),
        'max_attempts' => max(1, (int)setting_get($conn, 'printer_job_max_attempts', '3')),
    ];
}

/**
 * Hängende Jobs zurücksetzen bzw. als fehlgeschlagen markieren.
 *
 * @param string|null $printerKey z. B. 'target_11'; null = alle Druckziele
 * @return array{requeued:int, failed:int}
 */
function ff_printer_jobs_recover_stuck(mysqli $conn, ?string $printerKey = null): array
{
    $cfg = ff_printer_jobs_config($conn);
    $printerSql = $printerKey !== null ? ' AND printer = ?' : '';
    $stuckWhere = "status = 'reserved' AND type IN (" . FF_PRINTER_JOB_TYPES_SQL . ")
        AND (reserved_at IS NULL OR reserved_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))" . $printerSql;

    // 1) Zu alt oder zu oft versucht -> fehlgeschlagen (kein automatischer Nachdruck)
    $sqlFail = "UPDATE printer_jobs
        SET status = 'error', reserved_at = NULL, reserved_by = NULL,
            error = CONCAT('Hängengeblieben',
                           IF(reserved_at IS NULL, '', CONCAT(' (reserviert seit ', DATE_FORMAT(reserved_at, '%d.%m. %H:%i'), ')')),
                           ' – nicht automatisch nachgedruckt. Bitte erneut drucken oder verwerfen.')
        WHERE " . $stuckWhere . "
          AND (created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) OR attempts >= ?)";
    $failed = ff_printer_jobs_exec(
        $conn,
        $sqlFail,
        $printerKey !== null ? 'isii' : 'iii',
        $printerKey !== null
            ? [$cfg['stuck_min'], $printerKey, $cfg['max_age_min'], $cfg['max_attempts']]
            : [$cfg['stuck_min'], $cfg['max_age_min'], $cfg['max_attempts']]
    );

    // 2) Jung genug -> wieder einreihen
    $sqlRequeue = "UPDATE printer_jobs
        SET status = 'pending', reserved_at = NULL, reserved_by = NULL, attempts = attempts + 1,
            error = 'Automatisch neu eingereiht (Druck-Client hat nicht bestätigt).'
        WHERE " . $stuckWhere;
    $requeued = ff_printer_jobs_exec(
        $conn,
        $sqlRequeue,
        $printerKey !== null ? 'is' : 'i',
        $printerKey !== null ? [$cfg['stuck_min'], $printerKey] : [$cfg['stuck_min']]
    );

    return ['requeued' => max(0, $requeued), 'failed' => max(0, $failed)];
}

/**
 * Problem-Jobs (fehlgeschlagen oder hängend) für die Admin-Ansicht.
 *
 * @return list<array<string,mixed>>
 */
function ff_printer_jobs_problems(mysqli $conn, int $limit = 100): array
{
    $cfg = ff_printer_jobs_config($conn);
    $limit = max(1, min(500, $limit));
    $sql = "SELECT j.id, j.printer, j.type, j.status, j.attempts, j.error, j.created_at, j.reserved_at, j.meta, LEFT(j.payload, 4000) AS payload_head,
                   pt.name AS target_name
            FROM printer_jobs j
            LEFT JOIN print_targets pt ON CONCAT('target_', pt.print_target) = j.printer
            WHERE j.type IN (" . FF_PRINTER_JOB_TYPES_SQL . ")
              AND (j.status = 'error'
                   OR (j.status = 'reserved' AND (j.reserved_at IS NULL OR j.reserved_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))))
            ORDER BY j.id DESC
            LIMIT ?";
    $stmt = ff_mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $cfg['stuck_min'], $limit);
    ff_mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $meta = json_decode((string)($r['meta'] ?? ''), true);
        $rows[] = [
            'id' => (int)$r['id'],
            'printer' => (string)$r['printer'],
            'target_name' => (string)($r['target_name'] ?? ''),
            'type' => (string)$r['type'],
            'type_label' => $r['type'] === 'rechnung_thermo' ? 'Rechnung' : 'Bon',
            'status' => (string)$r['status'],
            'attempts' => (int)$r['attempts'],
            'error' => (string)($r['error'] ?? ''),
            'created_at' => (string)$r['created_at'],
            'reserved_at' => $r['reserved_at'] !== null ? (string)$r['reserved_at'] : null,
            'ref' => ff_printer_jobs_ref_label(is_array($meta) ? $meta : [], (string)($r['payload_head'] ?? '')),
        ];
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

/**
 * Kurzbeschreibung für die Admin-Liste: Rechnungsnummer, Tisch bzw. Abholbon.
 * meta enthält bei Bons z. B. bon_id/kind; bei Rechnungen ist meta leer, die Nummer
 * steht im Drucktext („Rechnung   R2026-0042“).
 */
function ff_printer_jobs_ref_label(array $meta, string $payloadHead = ''): string
{
    $parts = [];
    $payload = json_decode($payloadHead, true);
    if (is_array($payload)) {
        $text = (string)($payload['text'] ?? '');
        if ($text !== '' && preg_match('/Rechnung[ \t]{2,}([^\s\x00-\x1f]+)/u', $text, $m)) {
            $parts[] = 'Rechnung ' . $m[1];
        }
        if ($text !== '' && preg_match('/Tisch[ \t:]+([^\x00-\x1f]{1,30})/u', $text, $m)) {
            $parts[] = 'Tisch ' . trim($m[1]);
        }
        $t = $payload['tische'][0] ?? null;
        if (is_array($t)) {
            $name = trim((string)($t['tischname'] ?? ''));
            if ($name !== '') {
                $parts[] = $name;
            } elseif (!empty($t['tischnummer'])) {
                $parts[] = 'Tisch ' . (int)$t['tischnummer'];
            }
        }
    }
    if (!empty($meta['bon_id']) && is_scalar($meta['bon_id'])) {
        $parts[] = 'Abholbon ' . (string)$meta['bon_id'];
    }

    return mb_substr(implode(' · ', array_values(array_unique($parts))), 0, 120);
}

/** Fehlgeschlagenen oder hängenden Job erneut drucken. */
function ff_printer_job_retry(mysqli $conn, int $jobId): bool
{
    return ff_printer_jobs_exec(
        $conn,
        "UPDATE printer_jobs SET status = 'pending', reserved_at = NULL, reserved_by = NULL, error = NULL
         WHERE id = ? AND status IN ('error', 'reserved') AND type IN (" . FF_PRINTER_JOB_TYPES_SQL . ")",
        'i',
        [$jobId]
    ) === 1;
}

/** Fehlgeschlagenen oder hängenden Job aus der Warteschlange entfernen. */
function ff_printer_job_discard(mysqli $conn, int $jobId): bool
{
    return ff_printer_jobs_exec(
        $conn,
        "DELETE FROM printer_jobs WHERE id = ? AND status IN ('error', 'reserved') AND type IN (" . FF_PRINTER_JOB_TYPES_SQL . ")",
        'i',
        [$jobId]
    ) === 1;
}

/** @return int betroffene Zeilen, -1 bei Fehler */
function ff_printer_jobs_exec(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = ff_mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return -1;
    }
    $bind = [$types];
    foreach ($params as $i => $v) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!ff_mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return -1;
    }
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return (int)$n;
}
