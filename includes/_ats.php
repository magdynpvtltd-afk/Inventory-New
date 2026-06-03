<?php
/**
 * MagDyn — ATS (Authorisation To Ship) helpers
 *
 * Model
 * -----
 * One job card → one ATS → one ats_lines row.
 *
 * The ATS number is system-generated via code_sequences (name 'ats',
 * default format 'ATS-NNNNN'). The operator NEVER types it. On the
 * job-card ATS step, when sub_qty is committed, ats_create_for_job_card
 * is called: if the card already has an ATS, return it; otherwise
 * generate a number, create the header, create the single line.
 *
 * Once an ATS is finalised on the billing side and billing has advanced
 * past 'applied' (we detect this via 409 wrong_status responses on
 * resend/cancel), the local ATS becomes 'locked' — no further edits,
 * no cancels.
 *
 * Phase 2 also lives here: ats_billing_call() is the curl helper,
 * ats_finalize() / ats_resend() do op=upsert, ats_cancel() does
 * op=cancel. Every push captures the full response into ats columns
 * (last_push_op/_http/_response/_error/_at) and writes a history row
 * (ats_push_history is created lazily — see ats_log_push).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/_codes.php';

// ============================================================
// Status registry
// ============================================================
function ats_status_labels()
{
    return [
        'draft'     => ['Draft',     'pill-muted'],
        'pushed'    => ['Pushed',    'pill-info'],
        'cancelled' => ['Cancelled', 'pill-warn'],
        'locked'    => ['Locked',    'pill-danger'],
    ];
}

function ats_status_label($status)
{
    $map = ats_status_labels();
    return isset($map[$status]) ? $map[$status][0] : (string)$status;
}


// ============================================================
// Lookups
// ============================================================
function ats_find($atsId)
{
    $atsId = (int)$atsId;
    if ($atsId <= 0) return null;
    return db_one("SELECT * FROM ats WHERE id = ?", [$atsId]);
}

function ats_find_by_no($atsNo)
{
    $atsNo = trim((string)$atsNo);
    if ($atsNo === '') return null;
    return db_one("SELECT * FROM ats WHERE ats_no = ?", [$atsNo]);
}

/**
 * Find the ATS attached to a given job card (1:1 since the new model).
 */
function ats_find_by_job_card($jobCardId)
{
    $jobCardId = (int)$jobCardId;
    if ($jobCardId <= 0) return null;
    return db_one("SELECT * FROM ats WHERE job_card_id = ?", [$jobCardId]);
}

function ats_lines($atsId)
{
    $atsId = (int)$atsId;
    if ($atsId <= 0) return [];
    return db_all(
        "SELECT al.*, jc.jc_no, jc.status AS jc_status, ii.name AS item_name
           FROM ats_lines al
           LEFT JOIN job_cards jc ON jc.id = al.job_card_id
           LEFT JOIN inv_items ii ON ii.id = al.item_id
          WHERE al.ats_id = ?
          ORDER BY al.id",
        [$atsId]
    );
}


// ============================================================
// Create / update from job_card
// ============================================================

/**
 * Create the ATS for a job card, or return the existing one.
 *
 * The new model is strictly 1:1. We never aggregate across job cards.
 * Re-saves of the same job card update qty/line_no on the existing
 * line in place (the UNIQUE on ats_lines prevents duplicate rows).
 *
 * The ATS number is generated via code_next('ats') the FIRST time
 * we create the row, then never changes.
 *
 * Refuses if the job card already has an ATS in 'locked' status —
 * billing has progressed past Applied and we must not silently
 * tamper with the line.
 */
function ats_create_for_job_card(array $jc, $actorId = null)
{
    $jcId = (int)($jc['id'] ?? 0);
    if ($jcId <= 0) {
        throw new \RuntimeException('ats_create_for_job_card: job card id required.');
    }
    $jcPo = trim((string)($jc['po_no'] ?? ''));
    if ($jcPo === '') {
        throw new \RuntimeException(sprintf(
            'Job card %s has no PO number — cannot create an ATS.',
            ($jc['jc_no'] ?? '#' . $jcId)
        ));
    }
    $itemId = (int)($jc['item_id'] ?? 0);
    if ($itemId <= 0) {
        throw new \RuntimeException(sprintf(
            'Job card %s has no item — cannot create an ATS.',
            ($jc['jc_no'] ?? '#' . $jcId)
        ));
    }
    $qty = (float)($jc['sub_qty'] ?? 0);
    if ($qty <= 0) {
        throw new \RuntimeException(sprintf(
            'Job card %s has no submitted qty — cannot create an ATS.',
            ($jc['jc_no'] ?? '#' . $jcId)
        ));
    }

    $existing = ats_find_by_job_card($jcId);
    if ($existing) {
        if ($existing['status'] === 'locked') {
            throw new \RuntimeException(sprintf(
                'ATS %s is locked — billing has advanced past Applied. The job card cannot be edited further.',
                $existing['ats_no']
            ));
        }
        ats_upsert_line($existing['id'], $jc);
        if ($existing['status'] === 'pushed') {
            db_exec("UPDATE ats SET status = 'draft' WHERE id = ?", [(int)$existing['id']]);
        }
        return (int)$existing['id'];
    }

    // Fresh ATS — generate number + create header + single line.
    $atsNo = code_next('ats');
    db_exec(
        "INSERT INTO ats (ats_no, job_card_id, po_no, ats_date, status, created_by)
              VALUES (?, ?, ?, CURDATE(), 'draft', ?)",
        [$atsNo, $jcId, $jcPo, $actorId ? (int)$actorId : null]
    );
    $atsId = (int)db()->lastInsertId();
    ats_upsert_line($atsId, $jc);
    return $atsId;
}


/**
 * Upsert the single ats_lines row for a job card under its ATS.
 * Re-fetches inv_code from inv_items so the value mirrored to billing
 * is always the current code.
 */
function ats_upsert_line($atsId, array $jc)
{
    $atsId = (int)$atsId;
    $jcId  = (int)($jc['id'] ?? 0);
    $itemId = (int)($jc['item_id'] ?? 0);
    $code = db_val("SELECT code FROM inv_items WHERE id = ?", [$itemId], '');
    if ($code === '' || $code === null) {
        throw new \RuntimeException('Item ' . $itemId . ' has no code.');
    }
    $qty = (float)($jc['sub_qty'] ?? 0);
    $lineNo = isset($jc['line_no']) && $jc['line_no'] !== '' ? (string)$jc['line_no'] : null;

    db_exec(
        "INSERT INTO ats_lines (ats_id, job_card_id, item_id, inv_code, line_no, qty)
              VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
              item_id  = VALUES(item_id),
              inv_code = VALUES(inv_code),
              line_no  = VALUES(line_no),
              qty      = VALUES(qty)",
        [$atsId, $jcId, $itemId, $code, $lineNo, $qty]
    );
    return (int)db_val(
        "SELECT id FROM ats_lines WHERE ats_id = ? AND job_card_id = ?",
        [$atsId, $jcId], 0
    );
}


/**
 * Used when a job card is cancelled. Refuses if the ATS is locked
 * or already pushed.
 */
function ats_remove_for_job_card($jobCardId)
{
    $row = ats_find_by_job_card($jobCardId);
    if (!$row) return null;
    if ($row['status'] === 'locked') {
        throw new \RuntimeException(sprintf(
            'ATS %s is locked — cannot delete the underlying job card record without coordinating with billing.',
            $row['ats_no']
        ));
    }
    if ($row['status'] === 'pushed') {
        throw new \RuntimeException(sprintf(
            'ATS %s is already pushed to billing. Cancel it first (ats.php → Cancel on billing) before removing.',
            $row['ats_no']
        ));
    }
    $atsId = (int)$row['id'];
    db_exec("DELETE FROM ats WHERE id = ?", [$atsId]);
    return $atsId;
}


// ============================================================
// PHASE 2 — outbound HTTP caller
// ============================================================

/**
 * Read billing-integration config. Returns null if URL or token is
 * absent — the Finalize handler then shows a "not configured" error.
 */
function ats_billing_config()
{
    global $APP;
    $cfg = isset($APP['billing_integration']) && is_array($APP['billing_integration'])
         ? $APP['billing_integration']
         : [];
    $url   = trim((string)($cfg['url'] ?? ''));
    $token = trim((string)($cfg['bearer_token'] ?? ''));
    if ($url === '' || $token === '') return null;
    return [
        'url'          => $url,
        'bearer_token' => $token,
        'timeout'      => (int)($cfg['timeout'] ?? 30),
    ];
}


/**
 * Low-level HTTP caller. POSTs JSON with Authorization: Bearer.
 * Returns a normalised result; never throws on HTTP/network errors.
 */
function ats_billing_call($op, array $payload)
{
    $cfg = ats_billing_config();
    if (!$cfg) {
        throw new \RuntimeException('Billing integration is not configured. Set billing_integration.url and bearer_token in config/app.config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new \RuntimeException('PHP curl extension is required for the billing integration.');
    }

    $url  = rtrim($cfg['url'], '/') . '?op=' . rawurlencode($op);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $cfg['bearer_token'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw   = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return [
            'ok'         => false,
            'http'       => $http,
            'json'       => null,
            'raw'        => (string)$err,
            'error'      => 'Network / transport error: ' . $err,
            'error_code' => 'transport',
        ];
    }
    $rawForStore = strlen($raw) > 8192 ? (substr($raw, 0, 8192) . '… (truncated)') : $raw;
    $json = json_decode($raw, true);

    if ($http >= 200 && $http < 300 && is_array($json) && !empty($json['ok'])) {
        return [
            'ok'         => true,
            'http'       => $http,
            'json'       => $json,
            'raw'        => $rawForStore,
            'error'      => null,
            'error_code' => null,
        ];
    }
    $errCode = is_array($json) && !empty($json['error']) ? (string)$json['error'] : ('http_' . $http);
    $errMsg  = is_array($json) && !empty($json['message']) ? (string)$json['message'] : ('HTTP ' . $http);
    return [
        'ok'         => false,
        'http'       => $http,
        'json'       => $json,
        'raw'        => $rawForStore,
        'error'      => $errMsg,
        'error_code' => $errCode,
    ];
}


/**
 * Build the upsert payload from an ATS + its lines. Mirrors the
 * billing app's ats_inbound.php contract field-for-field.
 */
function ats_build_upsert_payload(array $row, array $lines)
{
    $payload = [
        'magdyn_ats_id' => (int)$row['id'],
        'magdyn_ats_no' => (string)$row['ats_no'],
        'po_no'         => (string)$row['po_no'],
        'ats_date'      => (string)$row['ats_date'],
    ];
    if (!empty($row['ats_ref_no'])) $payload['ats_ref_no'] = (string)$row['ats_ref_no'];
    if (!empty($row['notes']))      $payload['notes']      = (string)$row['notes'];

    $payload['lines'] = [];
    foreach ($lines as $ln) {
        $line = [
            'inv_code' => (string)$ln['inv_code'],
            'qty'      => 0 + (float)$ln['qty'],
        ];
        if (!empty($ln['line_no'])) $line['line_no'] = (string)$ln['line_no'];
        $payload['lines'][] = $line;
    }
    return $payload;
}


/**
 * Finalise / resend an ATS via op=upsert. Captures the response.
 * The billing app is idempotent on magdyn_ats_id, so Finalize and
 * Resend both call this — the difference is only the button label
 * the operator clicked.
 */
function ats_finalize($atsId, $actorId = null)
{
    $row = ats_find($atsId);
    if (!$row) throw new \RuntimeException('ATS not found.');

    if ($row['status'] === 'locked') {
        throw new \RuntimeException('ATS ' . $row['ats_no'] . ' is locked — billing has advanced past Applied. Cannot push.');
    }
    if ($row['status'] === 'cancelled') {
        throw new \RuntimeException('ATS ' . $row['ats_no'] . ' was cancelled. Cannot push a cancelled ATS.');
    }
    $lines = ats_lines($atsId);
    if (!$lines) throw new \RuntimeException('ATS ' . $row['ats_no'] . ' has no lines to push.');

    $payload = ats_build_upsert_payload($row, $lines);
    $result  = ats_billing_call('upsert', $payload);

    $now = date('Y-m-d H:i:s');
    $bAtsId = null; $bAtsNo = null; $newStatus = $row['status'];

    if ($result['ok']) {
        $bAtsId = isset($result['json']['ats_id']) ? (int)$result['json']['ats_id'] : null;
        $bAtsNo = isset($result['json']['ats_no']) ? (string)$result['json']['ats_no'] : null;
        $newStatus = 'pushed';
    } else {
        if ((int)$result['http'] === 409 && $result['error_code'] === 'wrong_status') {
            $newStatus = 'locked';
        }
    }

    db_exec(
        "UPDATE ats
            SET status              = ?,
                billing_ats_id      = COALESCE(?, billing_ats_id),
                billing_ats_no      = COALESCE(?, billing_ats_no),
                last_push_at        = ?,
                last_push_op        = 'upsert',
                last_push_http      = ?,
                last_push_response  = ?,
                last_push_error     = ?
          WHERE id = ?",
        [
            $newStatus, $bAtsId, $bAtsNo, $now,
            (int)$result['http'], (string)$result['raw'],
            $result['ok'] ? null : substr((string)$result['error'], 0, 255),
            $atsId
        ]
    );
    ats_log_push($atsId, 'upsert', $result, $actorId);
    return $result;
}


/**
 * Cancel via op=cancel. Refuses if locked.
 */
function ats_cancel($atsId, $actorId = null)
{
    $row = ats_find($atsId);
    if (!$row) throw new \RuntimeException('ATS not found.');
    if ($row['status'] === 'locked') {
        throw new \RuntimeException('ATS ' . $row['ats_no'] . ' is locked — billing has invoiced/shipped. Cancel must be reconciled manually.');
    }
    if ($row['status'] === 'cancelled') {
        throw new \RuntimeException('ATS ' . $row['ats_no'] . ' is already cancelled.');
    }
    if (empty($row['billing_ats_id']) && $row['status'] === 'draft') {
        // Never pushed — local-only cancel.
        db_exec(
            "UPDATE ats SET status = 'cancelled', last_push_at = ?, last_push_op = 'cancel',
                            last_push_http = 0, last_push_error = 'local-only (never pushed)'
              WHERE id = ?",
            [date('Y-m-d H:i:s'), $atsId]
        );
        $result = ['ok' => true, 'http' => 0, 'json' => null, 'raw' => '',
                   'error' => null, 'error_code' => null, 'local_only' => true];
        ats_log_push($atsId, 'cancel', $result, $actorId);
        return $result;
    }

    $result = ats_billing_call('cancel', ['magdyn_ats_id' => (int)$row['id']]);
    $now = date('Y-m-d H:i:s');
    $newStatus = $row['status'];

    if ($result['ok']) {
        $newStatus = 'cancelled';
    } elseif ((int)$result['http'] === 409 && $result['error_code'] === 'wrong_status') {
        $newStatus = 'locked';
    }

    db_exec(
        "UPDATE ats
            SET status              = ?,
                last_push_at        = ?,
                last_push_op        = 'cancel',
                last_push_http      = ?,
                last_push_response  = ?,
                last_push_error     = ?
          WHERE id = ?",
        [
            $newStatus, $now, (int)$result['http'], (string)$result['raw'],
            $result['ok'] ? null : substr((string)$result['error'], 0, 255),
            $atsId
        ]
    );
    ats_log_push($atsId, 'cancel', $result, $actorId);
    return $result;
}


/**
 * Reopen a cancelled ATS — flip status back to Draft. Refuses if
 * locked (billing past Applied — terminal). Used by the "Reopen"
 * button on the view page.
 *
 * We don't auto-resend on reopen — that's the operator's choice.
 * A reopened ATS that was previously Pushed will show as Draft and
 * out-of-sync with billing until the operator clicks Resend.
 */
function ats_reopen($atsId, $actorId = null)
{
    $row = ats_find($atsId);
    if (!$row) throw new \RuntimeException('ATS not found.');
    if ($row['status'] === 'locked') {
        throw new \RuntimeException('ATS ' . $row['ats_no'] . ' is locked — billing has advanced past Applied. Cannot reopen.');
    }
    if ($row['status'] !== 'cancelled') {
        throw new \RuntimeException('ATS ' . $row['ats_no'] . ' is not cancelled (current status: ' . $row['status'] . '). Reopen only applies to cancelled ATSes.');
    }
    db_exec(
        "UPDATE ats SET status = 'draft', last_push_error = NULL WHERE id = ?",
        [(int)$atsId]
    );
    // Log to push history so the audit trail records the reopen action.
    ats_log_push($atsId, 'reopen',
        ['ok' => true, 'http' => 0, 'json' => null, 'raw' => 'local reopen',
         'error' => null, 'error_code' => null], $actorId);
    return true;
}


/**
 * Lazy-create ats_push_history on first use, then append.
 */
function ats_log_push($atsId, $op, array $result, $actorId = null)
{
    static $tableReady = false;
    if (!$tableReady) {
        try {
            db_exec(
                "CREATE TABLE IF NOT EXISTS ats_push_history (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ats_id      INT UNSIGNED NOT NULL,
                    op          VARCHAR(16)  NOT NULL,
                    http        INT          NOT NULL DEFAULT 0,
                    ok          TINYINT(1)   NOT NULL DEFAULT 0,
                    response    TEXT         NULL,
                    error       VARCHAR(255) NULL,
                    error_code  VARCHAR(64)  NULL,
                    actor_id    INT UNSIGNED NULL,
                    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY ix_aph_ats (ats_id, id),
                    CONSTRAINT fk_aph_ats FOREIGN KEY (ats_id)
                        REFERENCES ats(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('[ats] history table create failed: ' . $e->getMessage());
        }
        $tableReady = true;
    }
    try {
        db_exec(
            "INSERT INTO ats_push_history
                (ats_id, op, http, ok, response, error, error_code, actor_id)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int)$atsId, (string)$op, (int)$result['http'],
                $result['ok'] ? 1 : 0,
                (string)$result['raw'],
                $result['ok'] ? null : substr((string)$result['error'], 0, 255),
                $result['ok'] ? null : (string)$result['error_code'],
                $actorId ? (int)$actorId : null,
            ]
        );
    } catch (\Throwable $e) {
        error_log('[ats] history insert failed: ' . $e->getMessage());
    }
}


/**
 * Fetch the last N push attempts for the view-page history panel.
 */
function ats_push_history($atsId, $limit = 20)
{
    $atsId = (int)$atsId;
    $limit = max(1, min(100, (int)$limit));
    try {
        return db_all(
            "SELECT h.*, u.full_name AS actor_name
               FROM ats_push_history h
               LEFT JOIN users u ON u.id = h.actor_id
              WHERE h.ats_id = ?
              ORDER BY h.id DESC
              LIMIT $limit",
            [$atsId]
        );
    } catch (\Throwable $e) {
        return [];
    }
}
