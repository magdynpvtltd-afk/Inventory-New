<?php
/**
 * MagDyn — Manuals route (legacy / compatibility shim)
 * Created: 20260519_054500_IST · Repointed 20260519_064500_IST
 *
 * As of 2026-05-19 the four tool manuals are training courses
 * (slug: manual-bubble, manual-cad, manual-weight, manual-calc).
 * The standalone Manuals umbrella was retired from the sidebar
 * and access flows through Training instead.
 *
 * This file still exists so old bookmarks and any stray references
 * don't 404. It just redirects to the matching training course
 * (or the training landing page if no manual key was specified).
 * The 'manuals' permission check is gone — anyone with training.view
 * lands somewhere useful, anyone without it hits the training
 * permission wall instead.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$MANUAL_KEY_TO_SLUG = [
    'bubble' => 'manual-bubble',
    'cad'    => 'manual-cad',
    'weight' => 'manual-weight',
    'calc'   => 'manual-calc',
];
$manualKey = (string)input('manual', '');

if ($manualKey === '' || !isset($MANUAL_KEY_TO_SLUG[$manualKey])) {
    // No specific manual requested → go to the training landing page.
    redirect(url('/training.php'));
}

$slug = $MANUAL_KEY_TO_SLUG[$manualKey];
$courseId = null;
try {
    $courseId = (int)db_val(
        'SELECT id FROM training_courses WHERE slug = ? AND is_active = 1 LIMIT 1',
        [$slug], 0
    );
} catch (\Throwable $e) {
    $courseId = 0;
}

if ($courseId > 0) {
    redirect(url('/training.php?action=view&id=' . $courseId));
}

// Migration hasn't run or slug column is missing → fall through to
// the training landing rather than emit a broken state.
redirect(url('/training.php'));
