<?php
/**
 * MagDyn — Billing product (catalogue) outbound integration
 *
 * Spec: docs/billing_products_integration.md
 *
 * Mirrors finished-good inv_items to the billing app's product catalogue
 * so that downstream ATS pushes don't fail with item_not_found.
 *
 * Public surface:
 *
 *   billing_products_config()
 *     Returns ['url', 'bearer_token', 'timeout'] or null if disabled.
 *
 *   billing_product_push_if_needed($itemId, $actorId = null)
 *     The canonical entry point for write-site hooks. Re-fetches the
 *     item, checks finished-good kind, computes the content hash, and
 *     pushes ONLY if the hash differs from the last successfully-pushed
 *     hash (or no push has succeeded yet). Returns ['result' => …,
 *     'skipped' => bool, 'reason' => string].
 *
 *   billing_product_deactivate_if_needed($itemId, $oldIsActive, $actorId = null)
 *     Called by hooks that change is_active. Pushes op=deactivate only
 *     when is_active transitioned 1 → 0 and the item has been pushed
 *     before (billing_product_id IS NOT NULL).
 *
 *   billing_product_history($itemId, $limit = 20)
 *     Last N push attempts for the view-page panel.
 *
 * Caller philosophy
 * -----------------
 * Every write site calls billing_product_push_if_needed() unconditionally
 * after its INSERT/UPDATE. The helper itself decides whether anything
 * actually happens (is it a finished good? has the hash changed? is the
 * integration configured?). This way hooks are one-liners and the logic
 * for "should we push" lives in one place.
 *
 * Failures NEVER block local writes. A failed push is logged to
 * billing_product_pushes and surfaced on the item view page; the item
 * itself is saved normally.
 */

require_once __DIR__ . '/bootstrap.php';

// =============================================================
// Config
// =============================================================
function billing_products_config()
{
    global $APP;
    $cfg = isset($APP['billing_integration']) && is_array($APP['billing_integration'])
         ? $APP['billing_integration']
         : [];
    $url   = trim((string)($cfg['product_url'] ?? ''));
    $token = trim((string)($cfg['bearer_token'] ?? ''));
    if ($url === '' || $token === '') return null;
    return [
        'url'          => $url,
        'bearer_token' => $token,
        'timeout'      => (int)($cfg['timeout'] ?? 30),
    ];
}

/**
 * Is this category a finished-good? We accept BOTH seed code variants
 * ('finshd' from the legacy migration and 'finished' from the newer one)
 * since either may be the active code on a deployed DB.
 */
function billing_products_is_finished_category($categoryId)
{
    $categoryId = (int)$categoryId;
    if ($categoryId <= 0) return false;
    // Accept type='inventory' OR type='all' — some deployments seed the
    // Finished Goods category with type='all'.
    $row = db_one(
        "SELECT code, name FROM categories
          WHERE id = ? AND type IN ('inventory','all') AND is_active = 1",
        [$categoryId]
    );
    if (!$row) return false;
    $code = strtolower(trim((string)$row['code']));
    $name = strtolower(trim((string)$row['name']));
    // Match known code variants and name prefix so this survives
    // any future category-code drift.
    return in_array($code, ['finshd', 'finished', 'finished_goo'], true)
        || strpos($name, 'finished good') === 0;
}


// =============================================================
// Hash — fingerprint of the fields we mirror
// =============================================================

/**
 * Build the canonical payload (also used for the actual push).
 * Returns ['payload' => array, 'hash' => 'sha256-hex'].
 *
 * The hash is over a deterministic JSON encoding so two equivalent
 * items always produce the same hash regardless of column read order.
 */
function billing_products_build_payload($item)
{
    if (!is_array($item)) $item = (array)$item;

    // Category + division resolved to their codes so the billing side
    // gets a stable string, not an internal id that means nothing there.
    $catCode = $divCode = null;
    if (!empty($item['category_id'])) {
        $catCode = db_val(
            "SELECT code FROM categories WHERE id = ?",
            [(int)$item['category_id']], null
        );
    }
    if (!empty($item['division_id'])) {
        $divCode = db_val(
            "SELECT code FROM categories WHERE id = ?",
            [(int)$item['division_id']], null
        );
    }
    // UOM label too — the billing side may not know about uom_id.
    $uomLabel = null;
    if (!empty($item['uom_id'])) {
        $uomLabel = db_val(
            "SELECT label FROM inv_uom WHERE id = ?",
            [(int)$item['uom_id']], null
        );
    }
    // Fall back to the legacy uom string if the FK isn't populated.
    if (!$uomLabel) $uomLabel = (string)($item['uom'] ?? '');

    $payload = [
        'magdyn_item_id'    => (int)$item['id'],
        'inv_code'          => (string)$item['code'],
        'name'              => (string)($item['name'] ?? ''),
        'short_description' => (string)($item['short_description'] ?? ''),
        'long_description'  => (string)($item['long_description'] ?? ''),
        'uom'               => (string)$uomLabel,
        'uom_id'            => isset($item['uom_id']) ? (int)$item['uom_id'] : null,
        'category'          => $catCode,
        'division'          => $divCode,
        'is_active'         => !empty($item['is_active']),
        'part_no'           => (string)($item['part_no'] ?? ''),
        'part_rev_no'       => (string)($item['part_rev_no'] ?? ''),
        'dwg_no'            => (string)($item['dwg_no'] ?? ''),
        'dwg_rev_no'        => (string)($item['dwg_rev_no'] ?? ''),
        'unit_cost'         => isset($item['unit_cost']) && $item['unit_cost'] !== null
                                 ? (float)$item['unit_cost'] : null,
    ];

    // Sort keys for stable JSON. We do NOT include the magdyn id in the
    // hash because the id alone never changes meaningfully — only the
    // FIELDS do. Skipping the id keeps the hash field-content-focused.
    $forHash = $payload;
    unset($forHash['magdyn_item_id']);
    ksort($forHash);
    $hash = hash('sha256', json_encode($forHash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return ['payload' => $payload, 'hash' => $hash];
}


// =============================================================
// HTTP caller (same shape as ats_billing_call)
// =============================================================
function billing_products_call($op, array $payload)
{
    $cfg = billing_products_config();
    if (!$cfg) {
        throw new \RuntimeException('Billing product integration is not configured. Set billing_integration.product_url + bearer_token in config/app.config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new \RuntimeException('PHP curl extension is required for the billing-product integration.');
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
            'request'    => $body,
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
            'request'    => $body,
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
        'request'    => $body,
    ];
}


// =============================================================
// Audit log
// =============================================================
function billing_products_log($itemId, $op, array $result, $actorId = null)
{
    try {
        db_exec(
            "INSERT INTO billing_product_pushes
                (item_id, op, http, ok, request, response, error, error_code, actor_id)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int)$itemId, (string)$op, (int)$result['http'],
                $result['ok'] ? 1 : 0,
                isset($result['request']) ? (string)$result['request'] : null,
                (string)$result['raw'],
                $result['ok'] ? null : substr((string)$result['error'], 0, 255),
                $result['ok'] ? null : (string)$result['error_code'],
                $actorId ? (int)$actorId : null,
            ]
        );
    } catch (\Throwable $e) {
        // Never let an audit failure break the integration.
        error_log('[billing_products] history insert failed: ' . $e->getMessage());
    }
}


// =============================================================
// Public entry points
// =============================================================

/**
 * Auto-push the item to billing if it's a finished good AND the mirrored
 * fields have changed since the last successful push.
 *
 * Returns:
 *   ['result' => <call result or null>, 'skipped' => bool, 'reason' => string]
 *
 * "Skipped" is the common case (not a finished good, hash unchanged, or
 * integration disabled) and is NOT an error.
 *
 * Important: never throws. If anything goes wrong, returns ['skipped' =>
 * true, 'reason' => '<message>']. This is because hooks are called
 * after the local INSERT/UPDATE, and we MUST NOT roll back the local
 * write because the push failed.
 */
function billing_product_push_if_needed($itemId, $actorId = null)
{
    try {
        $itemId = (int)$itemId;
        if ($itemId <= 0) {
            return ['result' => null, 'skipped' => true, 'reason' => 'invalid item id'];
        }

        if (billing_products_config() === null) {
            return ['result' => null, 'skipped' => true, 'reason' => 'integration not configured'];
        }

        $item = db_one("SELECT * FROM inv_items WHERE id = ?", [$itemId]);
        if (!$item) {
            return ['result' => null, 'skipped' => true, 'reason' => 'item not found'];
        }

        // Not a finished good — silently skip. This covers raw materials,
        // sub-assemblies, consumables, and items whose category_id is
        // NULL or points to a non-finished category.
        if (!billing_products_is_finished_category($item['category_id'])) {
            return ['result' => null, 'skipped' => true, 'reason' => 'not a finished-good category'];
        }

        // Hash-vs-last-push check: skip if nothing relevant changed.
        $built = billing_products_build_payload($item);
        if (!empty($item['billing_last_push_hash'])
            && $item['billing_last_push_hash'] === $built['hash']
            && empty($item['billing_last_push_error'])) {
            return ['result' => null, 'skipped' => true, 'reason' => 'no mirrored field changed since last successful push'];
        }

        // Push.
        $result = billing_products_call('upsert', $built['payload']);
        billing_products_log($itemId, 'upsert', $result, $actorId);

        // Write state back on the item row. We update billing_last_push_hash
        // ONLY on success — a failed push doesn't validate the current
        // state, so the next call should retry the same payload.
        if ($result['ok']) {
            $bpid = isset($result['json']['billing_product_id'])
                  ? (int)$result['json']['billing_product_id'] : null;
            db_exec(
                "UPDATE inv_items
                    SET billing_product_id     = COALESCE(?, billing_product_id),
                        billing_last_push_at   = ?,
                        billing_last_push_op   = 'upsert',
                        billing_last_push_http = ?,
                        billing_last_push_error = NULL,
                        billing_last_push_hash  = ?
                  WHERE id = ?",
                [$bpid, date('Y-m-d H:i:s'), (int)$result['http'], $built['hash'], $itemId]
            );
        } else {
            db_exec(
                "UPDATE inv_items
                    SET billing_last_push_at   = ?,
                        billing_last_push_op   = 'upsert',
                        billing_last_push_http = ?,
                        billing_last_push_error = ?
                  WHERE id = ?",
                [date('Y-m-d H:i:s'), (int)$result['http'],
                 substr((string)$result['error'], 0, 255),
                 $itemId]
            );
        }

        return ['result' => $result, 'skipped' => false, 'reason' => $result['ok'] ? 'pushed' : 'pushed-with-error'];
    } catch (\Throwable $e) {
        error_log('[billing_products] push_if_needed crashed for item ' . $itemId . ': ' . $e->getMessage());
        return ['result' => null, 'skipped' => true, 'reason' => 'exception: ' . $e->getMessage()];
    }
}


/**
 * Called by hooks that just changed is_active. If the item is finished,
 * has been pushed before (has a billing_product_id), and is_active
 * transitioned 1 → 0, we send op=deactivate.
 *
 * Reactivation (0 → 1) is handled by the normal push_if_needed path
 * because the hash will have changed.
 */
function billing_product_deactivate_if_needed($itemId, $oldIsActive, $actorId = null)
{
    try {
        $itemId = (int)$itemId;
        if ($itemId <= 0) return ['skipped' => true, 'reason' => 'invalid id'];
        if (billing_products_config() === null) {
            return ['skipped' => true, 'reason' => 'integration not configured'];
        }
        $item = db_one("SELECT * FROM inv_items WHERE id = ?", [$itemId]);
        if (!$item) return ['skipped' => true, 'reason' => 'item not found'];
        if (!billing_products_is_finished_category($item['category_id'])) {
            return ['skipped' => true, 'reason' => 'not a finished-good category'];
        }
        if (empty($item['billing_product_id'])) {
            return ['skipped' => true, 'reason' => 'item was never pushed; nothing to deactivate on billing'];
        }
        // Was it actually a 1 → 0 transition?
        if ((int)$oldIsActive !== 1 || (int)$item['is_active'] !== 0) {
            return ['skipped' => true, 'reason' => 'is_active did not transition 1 → 0'];
        }

        $result = billing_products_call('deactivate', ['magdyn_item_id' => $itemId]);
        billing_products_log($itemId, 'deactivate', $result, $actorId);

        db_exec(
            "UPDATE inv_items
                SET billing_last_push_at   = ?,
                    billing_last_push_op   = 'deactivate',
                    billing_last_push_http = ?,
                    billing_last_push_error = ?
              WHERE id = ?",
            [date('Y-m-d H:i:s'), (int)$result['http'],
             $result['ok'] ? null : substr((string)$result['error'], 0, 255),
             $itemId]
        );

        return ['result' => $result, 'skipped' => false, 'reason' => $result['ok'] ? 'deactivated' : 'deactivate-failed'];
    } catch (\Throwable $e) {
        error_log('[billing_products] deactivate crashed for item ' . $itemId . ': ' . $e->getMessage());
        return ['skipped' => true, 'reason' => 'exception: ' . $e->getMessage()];
    }
}


/**
 * Force a manual push regardless of hash state. Used by the "Push to
 * billing" button on the item view page (operator wants to retry after
 * a failure, or refresh billing's copy).
 */
function billing_product_push_force($itemId, $actorId = null)
{
    $item = db_one("SELECT * FROM inv_items WHERE id = ?", [(int)$itemId]);
    if (!$item) throw new \RuntimeException('Item not found.');
    if (!billing_products_is_finished_category($item['category_id'])) {
        throw new \RuntimeException('This item is not a finished good — only finished goods are mirrored to billing.');
    }
    if (billing_products_config() === null) {
        throw new \RuntimeException('Billing product integration is not configured.');
    }
    // Clear the hash so push_if_needed always pushes.
    db_exec("UPDATE inv_items SET billing_last_push_hash = NULL WHERE id = ?", [(int)$itemId]);
    return billing_product_push_if_needed($itemId, $actorId);
}


/**
 * View-page push history (last N rows).
 */
function billing_product_history($itemId, $limit = 20)
{
    $itemId = (int)$itemId;
    $limit  = max(1, min(100, (int)$limit));
    return db_all(
        "SELECT h.*, u.full_name AS actor_name
           FROM billing_product_pushes h
           LEFT JOIN users u ON u.id = h.actor_id
          WHERE h.item_id = ?
          ORDER BY h.id DESC
          LIMIT $limit",
        [$itemId]
    );
}
