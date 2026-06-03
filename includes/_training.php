<?php
/**
 * MagDyn — Training helpers (Phase 1)
 *
 * Helpers for the expanded training module:
 *   - Multi-step courses
 *   - Course prerequisites
 *   - Per-step quizzes with scoring
 *   - Certification expiry
 *
 * The existing single-body-html course behaviour is preserved: if a
 * course has no rows in training_steps, the legacy flow runs.
 *
 * Public functions:
 *
 *   training_load_course($courseId)
 *       Returns the course row plus nav_mode / validity_months.
 *
 *   training_load_steps($courseId)
 *       Ordered list of steps for a course.
 *
 *   training_load_step($stepId)
 *       Single step row.
 *
 *   training_step_questions($stepId)
 *       Questions on a step, each with its options array.
 *
 *   training_user_progress($userId, $courseId)
 *       Returns ['completed_at' => ..., 'expires_at' => ..., 'is_expired' => bool]
 *       or null if not yet started.
 *
 *   training_user_step_progress($userId, $courseId)
 *       Map of step_id => completed_at for one user on one course.
 *
 *   training_check_prerequisites($userId, $courseId)
 *       Returns ['ok' => bool, 'blockers' => [['course'=>..., 'gate_mode'=>...], ...]]
 *       'ok' is true when no UNMET prereqs (or all unmet ones are 'soft').
 *       'blockers' lists ALL unmet prereqs with their gate_mode so the
 *       caller can decide whether to hard-block (any 'hard' blocker) or
 *       soft-warn (only 'soft' blockers).
 *
 *   training_grade_attempt($stepId, $responses)
 *       Grades a submission and returns
 *       ['score_pct'=>float, 'passed'=>bool, 'per_question'=>[...]].
 *       Does NOT persist — caller decides whether to record the attempt.
 *
 *   training_record_attempt($userId, $stepId, $score, $passed, $responses)
 *       Persists an attempt row. If passed AND no existing step_progress
 *       row, marks step complete. If all steps now complete, marks the
 *       course complete (with expires_at if validity_months set).
 *
 *   training_compute_expiry($completedAt, $validityMonths)
 *       Returns the DATETIME string when this completion expires, or NULL.
 *
 *   training_progress_pill($progress)
 *       Returns ['class' => 'pill-X', 'label' => '...'] for the run history
 *       column. Statuses: not-started / in-progress / completed / expiring-soon / expired.
 */

require_once __DIR__ . '/db.php';

// =============================================================
// COURSE / STEP LOADERS
// =============================================================

function training_load_course($courseId)
{
    return db_one("SELECT * FROM training_courses WHERE id = ?", [(int)$courseId]);
}

function training_load_steps($courseId)
{
    return db_all(
        "SELECT id, course_id, sort_order, title, body_html, pass_pct, max_attempts, is_active
           FROM training_steps
          WHERE course_id = ? AND is_active = 1
          ORDER BY sort_order ASC, id ASC",
        [(int)$courseId]
    );
}

function training_load_step($stepId)
{
    return db_one("SELECT * FROM training_steps WHERE id = ?", [(int)$stepId]);
}

/**
 * Load all questions for a step with their options inlined.
 * Returns: [ ['id'=>..,'body'=>..,'question_type'=>..,'options'=>[...]], ... ]
 */
function training_step_questions($stepId)
{
    $questions = db_all(
        "SELECT id, sort_order, question_type, body, explanation
           FROM training_step_questions
          WHERE step_id = ? AND is_active = 1
          ORDER BY sort_order, id",
        [(int)$stepId]
    );
    if (empty($questions)) return [];
    $qids = array_map(function ($q) { return (int)$q['id']; }, $questions);
    $in = implode(',', $qids);
    $opts = db_all(
        "SELECT id, question_id, sort_order, body, is_correct
           FROM training_step_question_options
          WHERE question_id IN ($in)
          ORDER BY question_id, sort_order, id"
    );
    $byQ = [];
    foreach ($opts as $o) {
        $byQ[(int)$o['question_id']][] = $o;
    }
    foreach ($questions as &$q) {
        $q['options'] = isset($byQ[(int)$q['id']]) ? $byQ[(int)$q['id']] : [];
    }
    unset($q);
    return $questions;
}

// =============================================================
// PROGRESS
// =============================================================

function training_user_progress($userId, $courseId)
{
    $row = db_one(
        "SELECT completed_at, expires_at
           FROM training_progress
          WHERE user_id = ? AND course_id = ?",
        [(int)$userId, (int)$courseId]
    );
    if (!$row) return null;
    $row['is_expired'] = $row['expires_at'] !== null
        && strtotime($row['expires_at']) < time();
    return $row;
}

function training_user_step_progress($userId, $courseId)
{
    $rows = db_all(
        "SELECT s.id AS step_id, sp.completed_at, sp.passing_attempt_id
           FROM training_steps s
      LEFT JOIN training_step_progress sp
                  ON sp.step_id = s.id AND sp.user_id = ?
          WHERE s.course_id = ? AND s.is_active = 1
          ORDER BY s.sort_order, s.id",
        [(int)$userId, (int)$courseId]
    );
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['step_id']] = [
            'completed_at'       => $r['completed_at'],
            'passing_attempt_id' => $r['passing_attempt_id'],
        ];
    }
    return $map;
}

// =============================================================
// PREREQUISITES
// =============================================================

function training_check_prerequisites($userId, $courseId)
{
    $prereqs = db_all(
        "SELECT p.prereq_course_id, p.gate_mode,
                c.title AS prereq_title,
                c.slug  AS prereq_slug,
                tp.completed_at,
                tp.expires_at
           FROM training_prerequisites p
           JOIN training_courses c ON c.id = p.prereq_course_id
      LEFT JOIN training_progress tp ON tp.course_id = p.prereq_course_id AND tp.user_id = ?
          WHERE p.course_id = ?",
        [(int)$userId, (int)$courseId]
    );
    $blockers = [];
    $anyHard = false;
    foreach ($prereqs as $p) {
        $isMet = $p['completed_at'] !== null
              && ($p['expires_at'] === null || strtotime($p['expires_at']) >= time());
        if (!$isMet) {
            $blockers[] = [
                'course_id'   => (int)$p['prereq_course_id'],
                'course_title'=> $p['prereq_title'],
                'gate_mode'   => $p['gate_mode'],
                'reason'      => $p['completed_at'] === null ? 'not_started' : 'expired',
            ];
            if ($p['gate_mode'] === 'hard') $anyHard = true;
        }
    }
    return [
        'ok'       => !$anyHard,         // hard blockers = stop; soft only = ok
        'all_met'  => empty($blockers),
        'blockers' => $blockers,
    ];
}

// =============================================================
// QUIZ GRADING
// =============================================================

/**
 * Grade a quiz submission.
 *   $responses = [ <question_id> => [option_id, option_id, ...], ... ]
 * Returns:
 *   ['score_pct' => float, 'passed' => bool,
 *    'per_question' => [ qid => ['correct'=>bool, 'correct_options'=>[ids], 'selected'=>[ids]] ]]
 *
 * Multi-choice rule: must select ALL correct AND no incorrect.
 * Single-choice rule: selected option must be the one correct option.
 * Unanswered questions count as incorrect.
 */
function training_grade_attempt($stepId, $responses)
{
    $step = training_load_step($stepId);
    if (!$step) throw new RuntimeException("Step not found");
    $questions = training_step_questions($stepId);
    if (empty($questions)) {
        // Quiz-less step — auto-pass
        return ['score_pct' => 100.0, 'passed' => true, 'per_question' => []];
    }

    $perQ = [];
    $correctCount = 0;
    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $correctIds = [];
        foreach ($q['options'] as $o) if ((int)$o['is_correct'] === 1) $correctIds[] = (int)$o['id'];
        $selectedRaw = isset($responses[$qid]) && is_array($responses[$qid]) ? $responses[$qid] : [];
        $selectedIds = array_values(array_unique(array_map('intval', $selectedRaw)));

        // For single_choice, only the first selected option counts
        if ($q['question_type'] === 'single_choice') {
            $selectedIds = count($selectedIds) > 0 ? [$selectedIds[0]] : [];
        }

        sort($correctIds); sort($selectedIds);
        $isCorrect = $correctIds === $selectedIds && !empty($correctIds);

        if ($isCorrect) $correctCount++;
        $perQ[$qid] = [
            'correct'         => $isCorrect,
            'correct_options' => $correctIds,
            'selected'        => $selectedIds,
        ];
    }

    $total = count($questions);
    $scorePct = $total > 0 ? round(100.0 * $correctCount / $total, 2) : 100.0;
    $passed = $scorePct >= (float)$step['pass_pct'];

    return [
        'score_pct'    => $scorePct,
        'passed'       => $passed,
        'correct'      => $correctCount,
        'total'        => $total,
        'per_question' => $perQ,
    ];
}

/**
 * Persist an attempt row. If passed AND not already complete, marks the
 * step complete. Returns ['attempt_id' => ..., 'step_marked' => bool,
 *                         'course_marked' => bool, 'expires_at' => ...].
 */
function training_record_attempt($userId, $stepId, $grade, $responses)
{
    db_exec('START TRANSACTION');
    try {
        $responsesJson = json_encode($responses, JSON_UNESCAPED_SLASHES);
        db_exec(
            "INSERT INTO training_step_attempts
                (user_id, step_id, score_pct, passed, responses_json)
             VALUES (?, ?, ?, ?, ?)",
            [(int)$userId, (int)$stepId, (float)$grade['score_pct'],
             $grade['passed'] ? 1 : 0, $responsesJson]
        );
        $attemptId = (int)db_val('SELECT LAST_INSERT_ID()');
        $stepMarked = false;
        $courseMarked = false;
        $expiresAt = null;

        if ($grade['passed']) {
            // Mark step complete if not already
            $existing = db_val(
                "SELECT 1 FROM training_step_progress WHERE user_id = ? AND step_id = ?",
                [(int)$userId, (int)$stepId]
            );
            if (!$existing) {
                db_exec(
                    "INSERT INTO training_step_progress (user_id, step_id, passing_attempt_id)
                     VALUES (?, ?, ?)",
                    [(int)$userId, (int)$stepId, $attemptId]
                );
                $stepMarked = true;
            }
            // Check if the WHOLE course is now complete
            $step = training_load_step($stepId);
            if ($step) {
                $allStepIds = db_all(
                    "SELECT id FROM training_steps WHERE course_id = ? AND is_active = 1",
                    [(int)$step['course_id']]
                );
                $doneCount = (int)db_val(
                    "SELECT COUNT(*) FROM training_step_progress sp
                      JOIN training_steps s ON s.id = sp.step_id
                      WHERE sp.user_id = ? AND s.course_id = ? AND s.is_active = 1",
                    [(int)$userId, (int)$step['course_id']]
                );
                if (count($allStepIds) > 0 && $doneCount >= count($allStepIds)) {
                    // Course complete — mark progress with expiry
                    $course = training_load_course((int)$step['course_id']);
                    $expiresAt = training_compute_expiry(date('Y-m-d H:i:s'),
                        $course['validity_months'] ?? null);
                    db_exec(
                        "INSERT INTO training_progress (user_id, course_id, completed_at, expires_at)
                         VALUES (?, ?, NOW(), ?)
                         ON DUPLICATE KEY UPDATE completed_at = VALUES(completed_at),
                                                 expires_at = VALUES(expires_at)",
                        [(int)$userId, (int)$step['course_id'], $expiresAt]
                    );
                    $courseMarked = true;
                }
            }
        }
        db_exec('COMMIT');
        return [
            'attempt_id'    => $attemptId,
            'step_marked'   => $stepMarked,
            'course_marked' => $courseMarked,
            'expires_at'    => $expiresAt,
        ];
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Compute the expiry timestamp from a completion timestamp.
 * Returns DATETIME string or NULL if no validity period.
 */
function training_compute_expiry($completedAt, $validityMonths)
{
    if (!$validityMonths || (int)$validityMonths <= 0) return null;
    $base = is_numeric($completedAt) ? (int)$completedAt : strtotime((string)$completedAt);
    if (!$base) return null;
    return date('Y-m-d H:i:s', strtotime('+' . (int)$validityMonths . ' months', $base));
}

// =============================================================
// MISC
// =============================================================

/**
 * Returns the pill class + label for a progress state, used in the
 * course list and the user dashboard.
 *
 * @param array|null $progress  output of training_user_progress(), or null
 * @return array  ['class' => 'pill-...', 'label' => '...']
 */
function training_progress_pill($progress)
{
    if (!$progress || !$progress['completed_at']) {
        return ['class' => 'pill-pending', 'label' => 'Not started'];
    }
    if (!empty($progress['is_expired'])) {
        return ['class' => 'pill-danger', 'label' => 'Expired — re-take required'];
    }
    if (!empty($progress['expires_at'])) {
        $days = ceil((strtotime($progress['expires_at']) - time()) / 86400);
        if ($days <= 30) {
            return ['class' => 'pill-warning', 'label' => "Expires in $days day" . ($days === 1 ? '' : 's')];
        }
        return ['class' => 'pill-success', 'label' => 'Completed (valid)'];
    }
    return ['class' => 'pill-success', 'label' => 'Completed'];
}

/**
 * Returns the latest step-attempt counts for a user, used by gating logic
 * to enforce max_attempts.
 */
function training_step_attempt_count($userId, $stepId)
{
    return (int)db_val(
        "SELECT COUNT(*) FROM training_step_attempts WHERE user_id = ? AND step_id = ?",
        [(int)$userId, (int)$stepId]
    );
}
