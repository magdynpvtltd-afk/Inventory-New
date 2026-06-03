<?php
/**
 * MagDyn — Training step view (multi-step courses)
 *
 * Included by training.php when ?action=step. Expects locals:
 *   $course   - course row
 *   $current  - current step row
 *   $steps    - all active steps for this course
 *   $stepProg - map step_id => ['completed_at'=>..., 'passing_attempt_id'=>...]
 *   $uid      - current user id
 *   $pr       - prereq state (always 'ok' here, since we hard-block before this)
 *
 * Compact two-pane layout: left = step navigator, right = body + screenshots + quiz.
 */

if (!isset($course) || !isset($current)) { return; }

$questions = training_step_questions((int)$current['id']);
$hasQuiz   = !empty($questions);
$stepDone  = !empty($stepProg[(int)$current['id']]['completed_at']);
$attemptCount = training_step_attempt_count($uid, (int)$current['id']);
$attemptsLeft = (int)$current['max_attempts'] > 0
              ? max(0, (int)$current['max_attempts'] - $attemptCount)
              : null;
$canAttempt = !$stepDone
           && $hasQuiz
           && ($attemptsLeft === null || $attemptsLeft > 0);

// Step screenshots (per-step gallery)
$stepScreens = db_all(
    'SELECT * FROM training_screenshots WHERE step_id = ? ORDER BY sort_order, id',
    [(int)$current['id']]
);

// Latest attempt for review
$lastAttempt = null;
if ($hasQuiz) {
    $lastAttempt = db_one(
        "SELECT * FROM training_step_attempts
          WHERE user_id = ? AND step_id = ?
          ORDER BY attempted_at DESC LIMIT 1",
        [$uid, (int)$current['id']]
    );
}

// Find indices for prev/next navigation
$currIdx = 0;
foreach ($steps as $i => $s) if ((int)$s['id'] === (int)$current['id']) { $currIdx = $i; break; }
$prevStep = $currIdx > 0 ? $steps[$currIdx - 1] : null;
$nextStep = $currIdx < count($steps) - 1 ? $steps[$currIdx + 1] : null;

// In strict mode, the Next button is gated on the current step being done
$canGoNext = $nextStep && (
    $course['nav_mode'] !== 'strict' || $stepDone
);

// Overall progress %
$totalSteps = count($steps);
$doneSteps  = 0; foreach ($steps as $s) if (!empty($stepProg[(int)$s['id']]['completed_at'])) $doneSteps++;
$pct = $totalSteps > 0 ? round(100 * $doneSteps / $totalSteps) : 0;

// Decide if the body is JUST an iframe — if so we render it without a card
// wrapper so it can use the full available height.
$bodyHtml = $current['body_html'] ?: '<p class="muted">No content for this step yet.</p>';
$isIframeOnly = (bool)preg_match('/^\s*<iframe\b[^>]*>\s*<\/iframe>\s*$/i', $bodyHtml);
?>

<div style="display: flex; align-items: center; gap: 14px; margin-bottom: 10px; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 280px;">
        <div style="display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;">
            <h1 style="margin: 0; font-size: 20px;"><?= h($course['title']) ?></h1>
            <span class="muted small">
                Step <?= $currIdx + 1 ?>/<?= $totalSteps ?>:
                <strong><?= h($current['title']) ?></strong>
                <?php if ($stepDone): ?>
                    · <span class="pill pill-success" style="font-size: 10px;">✓</span>
                <?php endif; ?>
                <?php if ($course['nav_mode'] === 'strict'): ?>
                    · sequential
                <?php endif; ?>
            </span>
        </div>
    </div>
    <!-- Inline progress + actions -->
    <div style="display: flex; align-items: center; gap: 10px; min-width: 220px;">
        <div style="width: 120px; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
            <div style="height: 100%; width: <?= (int)$pct ?>%; background: #16a34a;"></div>
        </div>
        <span class="muted small" style="white-space: nowrap;"><?= $doneSteps ?>/<?= $totalSteps ?> · <?= $pct ?>%</span>
    </div>
    <div style="display: flex; gap: 6px;">
        <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php?action=attempts')) ?>" title="View your attempt history">📊 My attempts</a>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php')) ?>">← All courses</a>
    </div>
</div>

<?php if ($isIframeOnly): ?>
    <!-- IFRAME-ONLY STEP: full-bleed layout, no left step nav (the
         step list is collapsed into a compact horizontal pill row
         above the iframe). The OUTER page is locked to viewport
         height so only the iframe scrolls — eliminates the double
         scrollbar that arises when both the page and the iframe
         expand independently. -->
    <style>
      /* Lock the page to viewport — only one scrollbar (inside the iframe).
         The shell uses <main class="main"> as the content container, with
         a flex layout next to the sidebar <aside>. */
      html, body { overflow: hidden !important; height: 100vh !important; }
      main.main {
          height: 100vh !important;
          max-height: 100vh !important;
          overflow: hidden !important;
          display: flex !important;
          flex-direction: column !important;
          padding: 12px 16px !important;     /* reduced gutter for full-bleed feel */
          box-sizing: border-box;
      }
      .shr-iframe-host {
          margin: 0;
          flex: 1 1 auto;
          min-height: 0;       /* allow flex item to shrink below content */
          display: flex;
      }
      .shr-iframe-host iframe {
          width: 100% !important;
          height: 100% !important;
          flex: 1 1 auto;
          border: 0 !important;
          border-radius: 6px;
          background: white;
          display: block;
          margin: 0 !important;
      }
      .shr-step-strip {
          display: flex; gap: 6px; flex-wrap: wrap;
          margin-bottom: 6px;
          flex: 0 0 auto;       /* don't grow/shrink */
      }
      .shr-step-chip {
          display: inline-flex; align-items: center; gap: 6px;
          padding: 4px 10px; border-radius: 999px;
          font-size: 12px; text-decoration: none; color: inherit;
          background: #f3f4f6; border: 1px solid #e5e7eb;
      }
      .shr-step-chip.current { background: #eff6ff; border-color: #1d4ed8; color: #1d4ed8; font-weight: 600; }
      .shr-step-chip.done    { background: #ecfdf5; border-color: #16a34a; color: #15803d; }
      .shr-step-chip.locked  { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
      .shr-step-chip .badge  { width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; color: #fff; }
      /* Compact nav row at the bottom shouldn't add scroll either */
      .shr-iframe-prevnext { flex: 0 0 auto; margin-top: 8px; }
    </style>

    <div class="shr-step-strip">
        <?php
        $previousDone = true;
        foreach ($steps as $i => $s):
            $sid = (int)$s['id'];
            $isDone = !empty($stepProg[$sid]['completed_at']);
            $isCurrent = $sid === (int)$current['id'];
            $available = $course['nav_mode'] !== 'strict' || $isDone || $isCurrent || $previousDone;
            $cls = 'shr-step-chip' . ($isCurrent ? ' current' : '') . ($isDone && !$isCurrent ? ' done' : '') . (!$available ? ' locked' : '');
            $badgeBg = $isDone ? '#16a34a' : ($isCurrent ? '#1d4ed8' : '#9ca3af');
        ?>
            <?php if ($available): ?>
                <a class="<?= $cls ?>" href="<?= h(url('/training.php?action=step&course=' . (int)$course['id'] . '&step=' . $sid)) ?>">
                    <span class="badge" style="background: <?= $badgeBg ?>;"><?= $isDone ? '✓' : ($i + 1) ?></span>
                    <span><?= h($s['title']) ?></span>
                </a>
            <?php else: ?>
                <span class="<?= $cls ?>" title="Locked — complete prior steps first">
                    <span class="badge" style="background: #e5e7eb; color: #9ca3af;">🔒</span>
                    <span><?= h($s['title']) ?></span>
                </span>
            <?php endif; ?>
            <?php $previousDone = $isDone; ?>
        <?php endforeach; ?>
    </div>

    <div class="shr-iframe-host"><?= $bodyHtml ?></div>

    <?php
      // If we're in strict mode and the next step is currently locked
      // (i.e., it needs THIS step to be completed first), show a short
      // hint inline with the action buttons so the user sees the warning
      // and the unlock-action together in a single row.
      $nextLocked = $course['nav_mode'] === 'strict'
                  && $nextStep
                  && !$stepDone;
    ?>

    <!-- Nav row: prev on left · lock-hint message in middle (when locked) · mark-complete + next on right -->
    <div class="shr-iframe-prevnext" style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 8px;
                <?= $nextLocked ? 'background: #fffbeb; border-left: 3px solid #d97706; padding: 6px 10px; border-radius: 4px;' : '' ?>">
        <!-- LEFT: prev step -->
        <?php if ($prevStep): ?>
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php?action=step&course=' . (int)$course['id'] . '&step=' . (int)$prevStep['id'])) ?>">← <?= h($prevStep['title']) ?></a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>

        <!-- MIDDLE: lock-hint message (only when next step is locked) -->
        <?php if ($nextLocked): ?>
            <span style="font-size: 12px; color: #92400e; flex: 1 1 auto; text-align: center;">
                🔒 The <strong><?= h($nextStep['title']) ?></strong> step is locked. Click <strong>✓ Mark step complete</strong> to continue.
            </span>
        <?php endif; ?>

        <!-- RIGHT: mark-complete (or status pill) + next button, grouped -->
        <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
            <?php if (!$hasQuiz && !$stepDone): ?>
                <form method="post" action="<?= h(url('/training.php?action=quiz_submit')) ?>" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="step" value="<?= (int)$current['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm">✓ Mark step complete</button>
                </form>
            <?php elseif ($stepDone): ?>
                <span class="pill pill-success" style="font-size: 11px;">✓ Step complete</span>
            <?php endif; ?>

            <?php if ($nextStep): ?>
                <?php if ($canGoNext): ?>
                    <a class="btn btn-primary btn-sm" href="<?= h(url('/training.php?action=step&course=' . (int)$course['id'] . '&step=' . (int)$nextStep['id'])) ?>">Next: <?= h($nextStep['title']) ?> →</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm" style="opacity: 0.5; cursor: not-allowed;" title="Mark this step complete first">Next: <?= h($nextStep['title']) ?> 🔒</span>
                <?php endif; ?>
            <?php elseif ($doneSteps === $totalSteps): ?>
                <a class="btn btn-success btn-sm" href="<?= h(url('/training.php')) ?>">🎉 Course complete</a>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

<!-- NON-IFRAME STEP (quiz, regular HTML body, etc.) — same chip-strip
     layout as iframe steps for consistency. No 240px sidebar. Page-level
     scroll is allowed (we don't lock the viewport). -->
<style>
  .shr-step-strip {
      display: flex; gap: 6px; flex-wrap: wrap;
      margin-bottom: 10px;
  }
  .shr-step-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px; border-radius: 999px;
      font-size: 12px; text-decoration: none; color: inherit;
      background: #f3f4f6; border: 1px solid #e5e7eb;
  }
  .shr-step-chip.current { background: #eff6ff; border-color: #1d4ed8; color: #1d4ed8; font-weight: 600; }
  .shr-step-chip.done    { background: #ecfdf5; border-color: #16a34a; color: #15803d; }
  .shr-step-chip.locked  { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
  .shr-step-chip .badge  { width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; color: #fff; }
</style>

<div class="shr-step-strip">
    <?php
    $previousDone = true;
    foreach ($steps as $i => $s):
        $sid = (int)$s['id'];
        $isDone = !empty($stepProg[$sid]['completed_at']);
        $isCurrent = $sid === (int)$current['id'];
        $available = $course['nav_mode'] !== 'strict' || $isDone || $isCurrent || $previousDone;
        $cls = 'shr-step-chip' . ($isCurrent ? ' current' : '') . ($isDone && !$isCurrent ? ' done' : '') . (!$available ? ' locked' : '');
        $badgeBg = $isDone ? '#16a34a' : ($isCurrent ? '#1d4ed8' : '#9ca3af');
    ?>
        <?php if ($available): ?>
            <a class="<?= $cls ?>" href="<?= h(url('/training.php?action=step&course=' . (int)$course['id'] . '&step=' . $sid)) ?>">
                <span class="badge" style="background: <?= $badgeBg ?>;"><?= $isDone ? '✓' : ($i + 1) ?></span>
                <span><?= h($s['title']) ?></span>
            </a>
        <?php else: ?>
            <span class="<?= $cls ?>" title="Locked — complete prior steps first">
                <span class="badge" style="background: #e5e7eb; color: #9ca3af;">🔒</span>
                <span><?= h($s['title']) ?></span>
            </span>
        <?php endif; ?>
        <?php $previousDone = $isDone; ?>
    <?php endforeach; ?>
</div>

<!-- Step body (regular HTML, not iframe — already filtered out by outer if) -->
<?php if ($current['body_html']): ?>
    <div class="card" style="margin-bottom: 14px;">
        <div class="card-body" style="padding: 16px 18px;">
            <?= $bodyHtml ?>
        </div>
    </div>
<?php endif; ?>

<!-- Step screenshots -->
<?php if ($stepScreens): ?>
    <div class="card" style="margin-bottom: 14px;">
        <div class="card-head" style="padding: 8px 14px;"><h3 style="margin: 0; font-size: 14px;">Screenshots</h3></div>
        <div class="card-body" style="padding: 12px 14px;">
            <div class="screenshot-strip">
                <?php foreach ($stepScreens as $s): ?>
                    <figure>
                        <img src="<?= h(url('/' . ltrim($s['file_path'], '/'))) ?>" alt="<?= h($s['caption']) ?>">
                        <figcaption><?= h($s['caption'] ?: 'Screenshot') ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Quiz -->
<?php if ($hasQuiz): ?>
    <div class="card" style="margin-bottom: 14px;">
        <div class="card-head" style="padding: 8px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
            <h3 style="margin: 0; font-size: 14px;">
                Knowledge check
                <span class="muted small" style="font-weight: normal;">
                    · <?= count($questions) ?> Q · pass <?= (int)$current['pass_pct'] ?>%
                    <?php if ((int)$current['max_attempts'] > 0): ?>
                        · attempts <?= $attemptCount ?>/<?= (int)$current['max_attempts'] ?>
                    <?php endif; ?>
                </span>
            </h3>
            <?php if ($stepDone && $lastAttempt): ?>
                <span style="display: inline-flex; gap: 6px; align-items: center;">
                    <span class="pill pill-success" style="font-size: 11px;">
                        ✓ Passed <?= h(number_format((float)$lastAttempt['score_pct'], 0)) ?>% · <?= h(date('d M H:i', strtotime($lastAttempt['attempted_at']))) ?>
                    </span>
                    <a class="btn btn-ghost btn-sm" style="font-size: 11px; padding: 2px 8px;"
                       href="<?= h(url('/training.php?action=review_attempt&attempt=' . (int)$lastAttempt['id'])) ?>"
                       title="See which questions you got right or wrong">View results</a>
                </span>
            <?php elseif ($lastAttempt): ?>
                <span style="display: inline-flex; gap: 6px; align-items: center;">
                    <span class="pill pill-warning" style="font-size: 11px;">
                        Last try <?= h(number_format((float)$lastAttempt['score_pct'], 0)) ?>%
                        <?php if ($attemptsLeft !== null): ?> · <?= $attemptsLeft ?> left<?php endif; ?>
                    </span>
                    <a class="btn btn-ghost btn-sm" style="font-size: 11px; padding: 2px 8px;"
                       href="<?= h(url('/training.php?action=review_attempt&attempt=' . (int)$lastAttempt['id'])) ?>"
                       title="See which questions you got wrong">Review</a>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 12px 14px;">
            <?php if (!$canAttempt && !$stepDone): ?>
                <p class="muted small" style="margin: 0;">No more attempts available. Contact a manager if you need to retry.</p>
            <?php else: ?>
                <form method="post" action="<?= h(url('/training.php?action=quiz_submit')) ?>"
                      <?= $stepDone ? 'data-completed="1"' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="step" value="<?= (int)$current['id'] ?>">

                    <?php foreach ($questions as $qi => $q):
                        $isMulti = $q['question_type'] === 'multi_choice';
                        $inputName = 'q[' . (int)$q['id'] . ']' . ($isMulti ? '[]' : '');
                        $inputType = $isMulti ? 'checkbox' : 'radio';
                    ?>
                        <div style="padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 5px; margin-bottom: 8px;">
                            <div style="font-weight: 600; margin-bottom: 6px; font-size: 14px;">
                                <?= $qi + 1 ?>. <?= h($q['body']) ?>
                                <?php if ($isMulti): ?>
                                    <span class="muted small" style="font-weight: normal;"> (select all that apply)</span>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($q['options'] as $o): ?>
                                <label style="display: block; padding: 3px 0; cursor: pointer; font-size: 13px;">
                                    <input type="<?= $inputType ?>" name="<?= h($inputName) ?>"
                                           value="<?= (int)$o['id'] ?>"
                                           <?= $stepDone ? 'disabled' : '' ?>>
                                    <?= h($o['body']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$stepDone): ?>
                        <div class="form-actions" style="margin-top: 8px;">
                            <button type="submit" class="btn btn-primary btn-sm">Submit answers</button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Prev / Mark-complete / Next nav -->
<div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 14px;">
    <!-- LEFT: prev step -->
    <?php if ($prevStep): ?>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php?action=step&course=' . (int)$course['id'] . '&step=' . (int)$prevStep['id'])) ?>">
            ← <?= h($prevStep['title']) ?>
        </a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>

    <!-- RIGHT: mark-complete (or status pill) + next button, grouped -->
    <div style="display: flex; align-items: center; gap: 8px;">
        <?php if (!$hasQuiz && !$stepDone): ?>
            <form method="post" action="<?= h(url('/training.php?action=quiz_submit')) ?>" style="margin: 0;">
                <?= csrf_field() ?>
                <input type="hidden" name="step" value="<?= (int)$current['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">✓ Mark step complete</button>
            </form>
        <?php elseif ($stepDone): ?>
            <span class="pill pill-success" style="font-size: 11px;">✓ Step complete</span>
        <?php endif; ?>

        <?php if ($nextStep): ?>
            <?php if ($canGoNext): ?>
                <a class="btn btn-primary btn-sm" href="<?= h(url('/training.php?action=step&course=' . (int)$course['id'] . '&step=' . (int)$nextStep['id'])) ?>">
                    Next: <?= h($nextStep['title']) ?> →
                </a>
            <?php else: ?>
                <span class="btn btn-ghost btn-sm" style="opacity: 0.5; cursor: not-allowed;"
                      title="Mark this step complete first">
                    Next: <?= h($nextStep['title']) ?> 🔒
                </span>
            <?php endif; ?>
        <?php elseif ($doneSteps === $totalSteps): ?>
            <a class="btn btn-success btn-sm" href="<?= h(url('/training.php')) ?>">
                🎉 Course complete — back to list
            </a>
        <?php endif; ?>
    </div>
</div>

<?php endif; /* end isIframeOnly branch */ ?>
