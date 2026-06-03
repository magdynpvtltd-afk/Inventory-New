<?php
/**
 * MagDyn — Training
 * Created: 20260515_060024_IST
 * Reworked: 20260520_030000_IST — multi-step courses, prereqs, quizzes, expiry
 *
 * Roles control which courses a user can see (training_role_access).
 * Admins ("training.manage" + "training.create") can also create courses
 * and upload screenshots.
 *
 * Actions:
 *   ?action=index                 list courses visible to current user (default)
 *   ?action=view&id=N             read a course (marks progress for single-body
 *                                 courses; steps-based courses navigate via ?action=step)
 *   ?action=step&course=N&step=M  view one step of a multi-step course
 *   ?action=quiz_submit           POST a quiz attempt (training.view)
 *   ?action=complete&id=N         mark course complete (POST) — legacy single-body path
 *   ?action=new                   admin: new course form  (training.create)
 *   ?action=edit&id=N             admin: edit a course    (training.manage)
 *   ?action=save                  POST handler
 *   ?action=delete&id=N           admin: delete course    (training.delete)
 *   ?action=upload&id=N           POST a screenshot upload (training.manage)
 *   ?action=screenshot_delete     admin: remove a screenshot (training.manage)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_training.php';
require_login();
require_permission('training', 'view');

$action = (string)input('action', 'index');
$uid    = current_user_id();

$canManage = permission_check('training', 'manage');
$canCreate = permission_check('training', 'create');
$canDelete = permission_check('training', 'delete');

// ------------------------------------------------------------
// Datatable AJAX short-circuit
// The page below has two tables. When an AJAX request comes in for the
// admin one (training_admin), we must NOT emit any page HTML before
// the JSON response. Handle that case here, before anything is rendered.
// ------------------------------------------------------------
if ((string)input('dt_format', '') === 'json' && (string)input('dt_id', '') === 'training_admin') {
    require_once __DIR__ . '/includes/datatable.php';
    if (!$canManage) { http_response_code(403); exit; }
    $dtCfg = [
        'id'       => 'training_admin',
        'base_sql' => 'SELECT * FROM training_courses',
        'columns'  => [
            ['key'=>'title',       'label'=>'Title',       'sortable'=>true, 'searchable'=>true, 'sql_col'=>'title'],
            ['key'=>'description', 'label'=>'Description', 'sortable'=>false,'searchable'=>true, 'sql_col'=>'description', 'td_class'=>'muted small'],
            ['key'=>'is_active',   'label'=>'Status',      'sortable'=>true, 'searchable'=>false,'sql_col'=>'is_active'],
            ['key'=>'_actions',    'label'=>'Actions',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['title', 'asc'],
    ];
    $rowRenderer = function ($c) {
        $status = $c['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">disabled</span>';
        $actions = '<a class="btn btn-icon" href="' . h(url('/training.php?action=edit&id=' . (int)$c['id'])) . '" title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a>';
        return [
            'title'       => '<strong>' . h($c['title']) . '</strong>',
            'description' => h($c['description'] ?: ''),
            'is_active'   => $status,
            '_actions'    => dt_actions_wrap($actions),
        ];
    };
    data_table_run($dtCfg, $rowRenderer);
    exit; // unreached but explicit
}

// Courses visible to the current user (based on their roles)
function visible_courses_for($userId) {
    return db_all(
        "SELECT DISTINCT c.*
           FROM training_courses c
           JOIN training_role_access tra ON tra.course_id = c.id
           JOIN user_roles ur            ON ur.role_id   = tra.role_id
          WHERE ur.user_id = ? AND c.is_active = 1
          ORDER BY c.title"
    , [$userId]);
}

/**
 * Render a question form (new or edit). $q is null for new, a question
 * row (with 'options' array) for editing. Returns the HTML string.
 * Used both for the inline-edit toggles and the "+ Add new question"
 * details panel on the step edit page.
 */
function render_question_form($courseId, $stepId, $q)
{
    ob_start();
    $isEdit = $q !== null;
    $qid    = $isEdit ? (int)$q['id'] : 0;
    $body   = $isEdit ? $q['body'] : '';
    $type   = $isEdit ? $q['question_type'] : 'single_choice';
    $expl   = $isEdit ? (string)($q['explanation'] ?? '') : '';
    // Build the options for rendering: edit mode uses existing, new mode
    // shows 4 blank rows
    $opts = $isEdit ? $q['options'] : [
        ['body' => '', 'is_correct' => 0],
        ['body' => '', 'is_correct' => 0],
        ['body' => '', 'is_correct' => 0],
        ['body' => '', 'is_correct' => 0],
    ];
    ?>
    <form method="post" action="<?= h(url('/training.php?action=question_save')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="step_id" value="<?= (int)$stepId ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="question_id" value="<?= $qid ?>">
        <?php endif; ?>
        <div class="form-grid">
            <div class="field">
                <label>Question type</label>
                <select name="question_type">
                    <option value="single_choice" <?= $type === 'single_choice' ? 'selected' : '' ?>>Single correct answer</option>
                    <option value="multi_choice"  <?= $type === 'multi_choice'  ? 'selected' : '' ?>>Multiple correct (select all that apply)</option>
                </select>
            </div>
            <div class="field span-2">
                <label>Question text</label>
                <textarea name="body" rows="2" required><?= h($body) ?></textarea>
            </div>
            <div class="field span-2">
                <label>Options <span class="muted small">(tick each correct answer)</span></label>
                <div class="muted small" style="margin-bottom: 6px;">
                    For single-choice, tick exactly one. For multi-correct, tick at least one. Leave a row blank to skip it.
                </div>
                <?php foreach ($opts as $i => $o): ?>
                    <div style="display: flex; gap: 8px; margin-bottom: 6px; align-items: center;">
                        <input type="checkbox" name="opt_correct[<?= $i ?>]" value="1"
                               <?= !empty($o['is_correct']) ? 'checked' : '' ?>>
                        <input type="text" name="opt_body[<?= $i ?>]" maxlength="500"
                               value="<?= h($o['body'] ?? '') ?>"
                               placeholder="Option <?= $i + 1 ?>"
                               style="flex: 1;">
                    </div>
                <?php endforeach; ?>
                <?php
                // If editing and the question has more than 4 options, render any extras
                if ($isEdit && count($opts) > 4) {
                    for ($i = 4; $i < count($opts); $i++): ?>
                        <!-- extras already rendered above -->
                    <?php endfor;
                }
                // For new questions or edited questions with fewer than 6 options,
                // render two more blank "add another" rows
                if (!$isEdit || count($opts) < 6) {
                    $start = $isEdit ? count($opts) : 4;
                    for ($i = $start; $i < $start + 2; $i++): ?>
                        <div style="display: flex; gap: 8px; margin-bottom: 6px; align-items: center;">
                            <input type="checkbox" name="opt_correct[<?= $i ?>]" value="1">
                            <input type="text" name="opt_body[<?= $i ?>]" maxlength="500"
                                   placeholder="Option <?= $i + 1 ?> (optional)"
                                   style="flex: 1;">
                        </div>
                    <?php endfor;
                }
                ?>
            </div>
            <div class="field span-2">
                <label>Explanation <span class="muted small">(shown after answer)</span></label>
                <input type="text" name="explanation" maxlength="500" value="<?= h($expl) ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-sm">
                <?= $isEdit ? 'Save question' : '+ Add question' ?>
            </button>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

// ------------------------------------------------------------
// POST handlers
// ------------------------------------------------------------
if ($action === 'save') {
    csrf_check();
    $id     = (int)input('id', 0);
    $title  = trim((string)input('title'));
    $desc   = trim((string)input('description'));
    $body   = (string)input('body_html');
    $active = input('is_active') ? 1 : 0;
    $navMode = (string)input('nav_mode', 'free');
    if (!in_array($navMode, ['strict', 'free'], true)) $navMode = 'free';
    $validityStr = (string)input('validity_months', '');
    $validity = $validityStr === '' ? null : max(0, (int)$validityStr);
    if ($validity === 0) $validity = null; // 0 = no expiry; store NULL
    $roles  = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('intval', $_POST['roles']) : [];

    if ($title === '') {
        flash_set('error', 'Title is required.');
        redirect($id ? url('/training.php?action=edit&id=' . $id) : url('/training.php?action=new'));
    }

    if ($id) {
        require_permission('training', 'manage');
        db_exec(
            'UPDATE training_courses
                SET title=?, description=?, body_html=?, is_active=?,
                    nav_mode=?, validity_months=?
              WHERE id=?',
            [$title, $desc, $body, $active, $navMode, $validity, $id]
        );
    } else {
        require_permission('training', 'create');
        db_exec(
            'INSERT INTO training_courses
                (title, description, body_html, is_active, nav_mode, validity_months, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$title, $desc, $body, $active, $navMode, $validity, $uid]
        );
        $id = db()->lastInsertId();
    }
    db_exec('DELETE FROM training_role_access WHERE course_id = ?', [$id]);
    foreach ($roles as $rid) {
        db_exec('INSERT INTO training_role_access (course_id, role_id) VALUES (?, ?)', [$id, $rid]);
    }
    flash_set('success', 'Course saved.');
    redirect(url('/training.php?action=edit&id=' . $id));
}

if ($action === 'delete') {
    require_permission('training', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    db_exec('DELETE FROM training_courses WHERE id = ?', [$id]);
    flash_set('success', 'Course deleted.');
    redirect(url('/training.php'));
}

if ($action === 'upload') {
    require_permission('training', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    if (!$id || !db_one('SELECT id FROM training_courses WHERE id = ?', [$id])) {
        flash_set('error', 'Course not found.');
        redirect(url('/training.php'));
    }
    if (empty($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Upload failed.');
        redirect(url('/training.php?action=edit&id=' . $id));
    }
    $maxBytes = (int)$GLOBALS['APP']['upload_max_mb'] * 1024 * 1024;
    if ($_FILES['screenshot']['size'] > $maxBytes) {
        flash_set('error', 'File too large.');
        redirect(url('/training.php?action=edit&id=' . $id));
    }
    $info = @getimagesize($_FILES['screenshot']['tmp_name']);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        flash_set('error', 'Only PNG, JPG, GIF or WEBP images are accepted.');
        redirect(url('/training.php?action=edit&id=' . $id));
    }
    $ext = $allowed[$info[2]];
    $dir = __DIR__ . '/uploads/training';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = 'course' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['screenshot']['tmp_name'], $dir . '/' . $fname)) {
        flash_set('error', 'Could not save uploaded file.');
        redirect(url('/training.php?action=edit&id=' . $id));
    }
    $caption = trim((string)input('caption'));
    $stepId  = (int)input('step_id', 0) ?: null;
    // If step_id is provided, make sure it belongs to this course
    if ($stepId !== null) {
        $owns = db_val(
            'SELECT id FROM training_steps WHERE id = ? AND course_id = ?',
            [$stepId, $id]
        );
        if (!$owns) {
            flash_set('error', 'Step does not belong to this course.');
            redirect(url('/training.php?action=edit&id=' . $id));
        }
    }
    $nextOrder = (int)db_val(
        $stepId !== null
            ? 'SELECT COALESCE(MAX(sort_order),0)+1 FROM training_screenshots WHERE course_id = ? AND step_id = ?'
            : 'SELECT COALESCE(MAX(sort_order),0)+1 FROM training_screenshots WHERE course_id = ? AND step_id IS NULL',
        $stepId !== null ? [$id, $stepId] : [$id]
    );
    db_exec(
        'INSERT INTO training_screenshots (course_id, step_id, file_path, caption, sort_order)
         VALUES (?, ?, ?, ?, ?)',
        [$id, $stepId, 'uploads/training/' . $fname, $caption, $nextOrder]
    );
    flash_set('success', 'Screenshot uploaded.');
    redirect(url('/training.php?action=edit&id=' . $id));
}

if ($action === 'screenshot_delete') {
    require_permission('training', 'manage');
    csrf_check();
    $sid = (int)input('sid', 0);
    $row = db_one('SELECT * FROM training_screenshots WHERE id = ?', [$sid]);
    if ($row) {
        // Only remove on disk if the path is inside /uploads (do NOT touch the
        // SVG placeholders shipped under /assets/screenshots).
        if (strpos($row['file_path'], 'uploads/') === 0) {
            $full = __DIR__ . '/' . $row['file_path'];
            if (is_file($full)) @unlink($full);
        }
        db_exec('DELETE FROM training_screenshots WHERE id = ?', [$sid]);
        flash_set('success', 'Screenshot removed.');
    }
    redirect(url('/training.php?action=edit&id=' . (int)($row['course_id'] ?? 0)));
}

// ============================================================
// STEPS — admin CRUD
// ============================================================
if ($action === 'step_save') {
    require_permission('training', 'manage');
    csrf_check();
    $courseId = (int)input('course_id', 0);
    $stepId   = (int)input('step_id', 0);
    $title    = trim((string)input('title'));
    $body     = (string)input('body_html');
    $passPct  = max(0, min(100, (int)input('pass_pct', 100)));
    $maxAtt   = max(0, (int)input('max_attempts', 0));
    $active   = input('is_active') ? 1 : 0;

    if (!db_val('SELECT id FROM training_courses WHERE id = ?', [$courseId])) {
        flash_set('error', 'Course not found.');
        redirect(url('/training.php'));
    }
    if ($title === '') {
        flash_set('error', 'Step title is required.');
        redirect(url('/training.php?action=edit&id=' . $courseId));
    }
    if ($stepId) {
        // Verify step belongs to course
        if (!db_val('SELECT id FROM training_steps WHERE id = ? AND course_id = ?', [$stepId, $courseId])) {
            flash_set('error', 'Step not found.');
            redirect(url('/training.php?action=edit&id=' . $courseId));
        }
        db_exec(
            'UPDATE training_steps SET title=?, body_html=?, pass_pct=?, max_attempts=?, is_active=? WHERE id=?',
            [$title, $body, $passPct, $maxAtt, $active, $stepId]
        );
        flash_set('success', 'Step saved.');
    } else {
        $nextOrder = (int)db_val('SELECT COALESCE(MAX(sort_order),0)+1 FROM training_steps WHERE course_id = ?', [$courseId]);
        db_exec(
            'INSERT INTO training_steps (course_id, sort_order, title, body_html, pass_pct, max_attempts, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$courseId, $nextOrder, $title, $body, $passPct, $maxAtt, $active]
        );
        $stepId = (int)db_val('SELECT LAST_INSERT_ID()');
        flash_set('success', 'Step added.');
    }
    redirect(url('/training.php?action=step_edit&course=' . $courseId . '&step=' . $stepId));
}

if ($action === 'step_delete') {
    require_permission('training', 'manage');
    csrf_check();
    $stepId = (int)input('step_id', 0);
    $row = db_one('SELECT course_id FROM training_steps WHERE id = ?', [$stepId]);
    if ($row) {
        db_exec('DELETE FROM training_steps WHERE id = ?', [$stepId]);
        flash_set('success', 'Step deleted.');
        redirect(url('/training.php?action=edit&id=' . (int)$row['course_id']));
    }
    redirect(url('/training.php'));
}

if ($action === 'step_reorder') {
    require_permission('training', 'manage');
    csrf_check();
    $courseId = (int)input('course_id', 0);
    $ids = isset($_POST['step_ids']) ? (array)$_POST['step_ids'] : [];
    $i = 1;
    foreach ($ids as $sid) {
        db_exec(
            'UPDATE training_steps SET sort_order = ? WHERE id = ? AND course_id = ?',
            [$i++, (int)$sid, $courseId]
        );
    }
    flash_set('success', 'Step order updated.');
    redirect(url('/training.php?action=edit&id=' . $courseId));
}

// ============================================================
// QUESTIONS + OPTIONS — admin CRUD
// ============================================================
if ($action === 'question_save') {
    require_permission('training', 'manage');
    csrf_check();
    $stepId   = (int)input('step_id', 0);
    $qid      = (int)input('question_id', 0);
    $type     = (string)input('question_type', 'single_choice');
    if (!in_array($type, ['single_choice', 'multi_choice'], true)) $type = 'single_choice';
    $body     = trim((string)input('body'));
    $expl     = trim((string)input('explanation')) ?: null;
    $optBodies   = isset($_POST['opt_body'])    ? (array)$_POST['opt_body']    : [];
    $optCorrects = isset($_POST['opt_correct']) ? (array)$_POST['opt_correct'] : [];

    $step = db_one('SELECT course_id FROM training_steps WHERE id = ?', [$stepId]);
    if (!$step) {
        flash_set('error', 'Step not found.');
        redirect(url('/training.php'));
    }
    if ($body === '') {
        flash_set('error', 'Question text is required.');
        redirect(url('/training.php?action=step_edit&course=' . (int)$step['course_id'] . '&step=' . $stepId));
    }
    // Validate options: at least 2 non-empty, at least 1 marked correct;
    // for single_choice, exactly one correct.
    $clean = [];
    foreach ($optBodies as $i => $ob) {
        $ob = trim((string)$ob);
        if ($ob === '') continue;
        $correct = isset($optCorrects[$i]) ? 1 : 0;
        $clean[] = ['body' => $ob, 'correct' => $correct];
    }
    if (count($clean) < 2) {
        flash_set('error', 'Need at least 2 options.');
        redirect(url('/training.php?action=step_edit&course=' . (int)$step['course_id'] . '&step=' . $stepId));
    }
    $correctCount = 0;
    foreach ($clean as $c) if ($c['correct']) $correctCount++;
    if ($correctCount < 1) {
        flash_set('error', 'Mark at least one option as correct.');
        redirect(url('/training.php?action=step_edit&course=' . (int)$step['course_id'] . '&step=' . $stepId));
    }
    if ($type === 'single_choice' && $correctCount !== 1) {
        flash_set('error', 'Single-choice questions must have exactly one correct option.');
        redirect(url('/training.php?action=step_edit&course=' . (int)$step['course_id'] . '&step=' . $stepId));
    }

    db_exec('START TRANSACTION');
    try {
        if ($qid) {
            // Verify question belongs to this step
            if (!db_val('SELECT id FROM training_step_questions WHERE id = ? AND step_id = ?', [$qid, $stepId])) {
                throw new RuntimeException('Question not found.');
            }
            db_exec(
                'UPDATE training_step_questions SET question_type=?, body=?, explanation=? WHERE id=?',
                [$type, $body, $expl, $qid]
            );
            // Easiest: drop existing options and rewrite. Attempts use option ids
            // historically but we accept that "editing options after answers exist"
            // makes prior attempts stale — that's the trade-off.
            db_exec('DELETE FROM training_step_question_options WHERE question_id = ?', [$qid]);
        } else {
            $nextOrder = (int)db_val(
                'SELECT COALESCE(MAX(sort_order),0)+1 FROM training_step_questions WHERE step_id = ?',
                [$stepId]
            );
            db_exec(
                'INSERT INTO training_step_questions (step_id, sort_order, question_type, body, explanation)
                 VALUES (?, ?, ?, ?, ?)',
                [$stepId, $nextOrder, $type, $body, $expl]
            );
            $qid = (int)db_val('SELECT LAST_INSERT_ID()');
        }
        foreach ($clean as $i => $c) {
            db_exec(
                'INSERT INTO training_step_question_options (question_id, sort_order, body, is_correct)
                 VALUES (?, ?, ?, ?)',
                [$qid, $i + 1, $c['body'], $c['correct']]
            );
        }
        db_exec('COMMIT');
        flash_set('success', 'Question saved.');
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        flash_set('error', $e->getMessage());
    }
    redirect(url('/training.php?action=step_edit&course=' . (int)$step['course_id'] . '&step=' . $stepId));
}

if ($action === 'question_delete') {
    require_permission('training', 'manage');
    csrf_check();
    $qid = (int)input('question_id', 0);
    $q = db_one(
        'SELECT q.step_id, s.course_id FROM training_step_questions q
           JOIN training_steps s ON s.id = q.step_id
          WHERE q.id = ?',
        [$qid]
    );
    if ($q) {
        db_exec('DELETE FROM training_step_questions WHERE id = ?', [$qid]);
        flash_set('success', 'Question deleted.');
        redirect(url('/training.php?action=step_edit&course=' . (int)$q['course_id'] . '&step=' . (int)$q['step_id']));
    }
    redirect(url('/training.php'));
}

// ============================================================
// PREREQUISITES — admin CRUD
// ============================================================
if ($action === 'prereq_save') {
    require_permission('training', 'manage');
    csrf_check();
    $courseId = (int)input('course_id', 0);
    $prereqId = (int)input('prereq_course_id', 0);
    $gateMode = (string)input('gate_mode', 'soft');
    if (!in_array($gateMode, ['hard', 'soft'], true)) $gateMode = 'soft';
    if ($courseId === $prereqId) {
        flash_set('error', 'A course cannot be a prerequisite of itself.');
        redirect(url('/training.php?action=edit&id=' . $courseId));
    }
    if (!db_val('SELECT id FROM training_courses WHERE id = ?', [$prereqId])) {
        flash_set('error', 'Prereq course not found.');
        redirect(url('/training.php?action=edit&id=' . $courseId));
    }
    // Belt-and-braces against simple cycles (A→B→A). We don't try to detect deep cycles.
    $reverseExists = db_val(
        'SELECT 1 FROM training_prerequisites WHERE course_id = ? AND prereq_course_id = ?',
        [$prereqId, $courseId]
    );
    if ($reverseExists) {
        flash_set('error', 'Cannot add — the other course already lists this one as a prerequisite (cycle).');
        redirect(url('/training.php?action=edit&id=' . $courseId));
    }
    db_exec(
        'INSERT INTO training_prerequisites (course_id, prereq_course_id, gate_mode)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE gate_mode = VALUES(gate_mode)',
        [$courseId, $prereqId, $gateMode]
    );
    flash_set('success', 'Prerequisite saved.');
    redirect(url('/training.php?action=edit&id=' . $courseId));
}

if ($action === 'prereq_delete') {
    require_permission('training', 'manage');
    csrf_check();
    $courseId = (int)input('course_id', 0);
    $prereqId = (int)input('prereq_course_id', 0);
    db_exec(
        'DELETE FROM training_prerequisites WHERE course_id = ? AND prereq_course_id = ?',
        [$courseId, $prereqId]
    );
    flash_set('success', 'Prerequisite removed.');
    redirect(url('/training.php?action=edit&id=' . $courseId));
}

if ($action === 'complete') {
    csrf_check();
    $id = (int)input('id', 0);
    // Make sure the user can actually see the course
    $allowed = db_one(
        'SELECT 1 FROM training_courses c
           JOIN training_role_access tra ON tra.course_id = c.id
           JOIN user_roles ur ON ur.role_id = tra.role_id
          WHERE ur.user_id = ? AND c.id = ? LIMIT 1',
        [$uid, $id]
    );
    if ($allowed) {
        // Phase 1: block completion if a HARD prereq is unmet, or if ANY
        // prereq (hard or soft) is unmet — soft prereqs are visible-but-
        // can't-complete according to the agreed spec.
        $pr = training_check_prerequisites($uid, $id);
        if (!$pr['all_met']) {
            $names = array_map(function ($b) { return $b['course_title']; }, $pr['blockers']);
            flash_set('error', 'Cannot mark complete — prerequisites unmet: ' . implode(', ', $names));
            redirect(url('/training.php?action=view&id=' . $id));
        }
        // Phase 1: if the course has steps, the user can't use the legacy
        // single-button complete path. Each step must be passed.
        $stepsCount = (int)db_val(
            "SELECT COUNT(*) FROM training_steps WHERE course_id = ? AND is_active = 1",
            [$id]
        );
        if ($stepsCount > 0) {
            $doneCount = (int)db_val(
                "SELECT COUNT(*) FROM training_step_progress sp
                  JOIN training_steps s ON s.id = sp.step_id
                  WHERE sp.user_id = ? AND s.course_id = ? AND s.is_active = 1",
                [$uid, $id]
            );
            if ($doneCount < $stepsCount) {
                flash_set('error', 'This course has steps — complete each step (and its quiz, if any) instead of marking the whole course.');
                redirect(url('/training.php?action=view&id=' . $id));
            }
        }
        // Compute expiry from course's validity_months (NULL = never expires)
        $course = training_load_course($id);
        $validity = $course ? ($course['validity_months'] ?? null) : null;
        $expiresAt = training_compute_expiry(date('Y-m-d H:i:s'), $validity);
        db_exec(
            'INSERT INTO training_progress (user_id, course_id, completed_at, expires_at)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE completed_at = NOW(), expires_at = VALUES(expires_at)',
            [$uid, $id, $expiresAt]
        );
        flash_set('success', $expiresAt
            ? 'Marked as complete. Certification expires ' . date('d M Y', strtotime($expiresAt)) . '.'
            : 'Marked as complete.');
    }
    redirect(url('/training.php?action=view&id=' . $id));
}

// ------------------------------------------------------------
// STEP — view one step of a multi-step course
// ------------------------------------------------------------
if ($action === 'step') {
    $courseId = (int)input('course', 0);
    $stepId   = (int)input('step', 0);
    // Permission gate: managers always allowed; everyone else must have
    // role access to this course via training_role_access.
    if ($canManage) {
        $allowed = 1;
    } else {
        $allowed = db_val(
            'SELECT 1 FROM training_courses c
               JOIN training_role_access tra ON tra.course_id = c.id
               JOIN user_roles ur ON ur.role_id = tra.role_id
              WHERE ur.user_id = ? AND c.id = ? LIMIT 1',
            [$uid, $courseId]
        );
    }
    if (!$allowed) {
        flash_set('error', 'You do not have access to this course.');
        redirect(url('/training.php'));
    }
    $course = training_load_course($courseId);
    if (!$course || (int)$course['is_active'] === 0) {
        flash_set('error', 'Course not found.');
        redirect(url('/training.php'));
    }
    // Hard-gate prereqs (managers bypass — they may need to preview
    // any course regardless of personal completion status)
    if (!$canManage) {
        $pr = training_check_prerequisites($uid, $courseId);
        if (!$pr['ok']) {
            $names = array_map(function ($b) { return $b['course_title']; }, $pr['blockers']);
            flash_set('error', 'Locked — complete first: ' . implode(', ', $names));
            redirect(url('/training.php'));
        }
    }
    $steps = training_load_steps($courseId);
    if (empty($steps)) {
        // No steps configured — redirect to legacy view
        redirect(url('/training.php?action=view&id=' . $courseId));
    }
    // Pick the requested step or default to the first
    // Load progress for this course so we can pick the right default step.
    $stepProg = training_user_step_progress($uid, $courseId);

    // Pick the requested step, OR resume from the user's first incomplete
    // step. If every step is done, default to the last step (so the user
    // can re-read the final material). For a brand-new user, this lands
    // on $steps[0] naturally.
    $current = null;
    if ($stepId > 0) {
        foreach ($steps as $s) if ((int)$s['id'] === $stepId) { $current = $s; break; }
    }
    if (!$current) {
        foreach ($steps as $s) {
            if (empty($stepProg[(int)$s['id']]['completed_at'])) {
                $current = $s;
                break;
            }
        }
        if (!$current) $current = $steps[count($steps) - 1];
    }

    // Strict-mode gate: user must have completed every PRIOR step
    if ($course['nav_mode'] === 'strict' && !$canManage) {
        foreach ($steps as $s) {
            if ((int)$s['id'] === (int)$current['id']) break;
            if (empty($stepProg[(int)$s['id']]['completed_at'])) {
                // Bump back to the earliest incomplete step
                $current = $s;
                flash_set('error', 'Complete earlier steps first.');
                break;
            }
        }
    }
    // Render
    $page_title  = 'Training: ' . $course['title'] . ' — ' . $current['title'];
    $page_module = 'training';
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/training_step_view.php';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ------------------------------------------------------------
// ATTEMPTS — view quiz attempt history and course completions
// ------------------------------------------------------------
// Learners: see their own history.
// Managers: optionally view another user's via ?user=N.
//
// Sections:
//   1. Course completions (per training_progress)
//   2. Quiz attempts (per training_step_attempts, paginated by 50)
// ------------------------------------------------------------
if ($action === 'attempts') {
    $targetUid = (int)input('user', $uid);
    if ($targetUid !== $uid && !$canManage) {
        flash_set('error', 'You can only view your own attempt history.');
        redirect(url('/training.php?action=attempts'));
    }
    $targetUser = db_one('SELECT id, username, full_name FROM users WHERE id = ?', [$targetUid]);
    if (!$targetUser) {
        flash_set('error', 'User not found.');
        redirect(url('/training.php?action=attempts'));
    }
    // 1. Course completions
    $completions = db_all(
        "SELECT c.id AS course_id, c.title, c.slug,
                tp.completed_at,
                tp.completed_at AS started_at,
                (CASE WHEN c.validity_months IS NOT NULL AND tp.completed_at IS NOT NULL
                      THEN DATE_ADD(tp.completed_at, INTERVAL c.validity_months MONTH)
                      ELSE NULL END) AS expires_at,
                c.validity_months
           FROM training_courses c
           LEFT JOIN training_progress tp
             ON tp.course_id = c.id AND tp.user_id = ?
          WHERE c.is_active = 1
          ORDER BY (tp.completed_at IS NULL) ASC,
                   tp.completed_at DESC,
                   c.title ASC",
        [$targetUid]
    );
    // 2. Quiz attempts (latest 200, joined with course/step info)
    $attempts = db_all(
        "SELECT a.id, a.attempted_at, a.score_pct, a.passed,
                s.title AS step_title, s.pass_pct,
                c.id AS course_id, c.title AS course_title
           FROM training_step_attempts a
           JOIN training_steps s   ON s.id = a.step_id
           JOIN training_courses c ON c.id = s.course_id
          WHERE a.user_id = ?
          ORDER BY a.attempted_at DESC
          LIMIT 200",
        [$targetUid]
    );

    $page_title  = 'Training history';
    $page_module = 'training';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1 style="margin: 0;">Training history</h1>
            <p class="muted" style="margin: 4px 0 0;">
                <?php if ($targetUid === $uid): ?>
                    Your course completions and quiz attempts.
                <?php else: ?>
                    Completions and attempts for <strong><?= h($targetUser['full_name'] ?: $targetUser['username']) ?></strong>.
                <?php endif; ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php')) ?>">← All courses</a>
        </div>
    </div>

    <h3 style="margin: 18px 0 8px; font-size: 15px;">Course completions</h3>
    <div class="card" style="margin-bottom: 18px;">
        <div class="card-body" style="padding: 0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 160px;">Completed</th>
                        <th style="width: 160px;">Expires</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($completions)): ?>
                        <tr><td colspan="4" class="muted" style="padding: 12px;">No courses yet.</td></tr>
                    <?php else: foreach ($completions as $c): ?>
                        <tr>
                            <td>
                                <a href="<?= h(url('/training.php?action=view&id=' . (int)$c['course_id'])) ?>">
                                    <?= h($c['title']) ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                  $isDone   = !empty($c['completed_at']);
                                  $isExpired = $isDone && !empty($c['expires_at']) && strtotime($c['expires_at']) < time();
                                  if (!$isDone)         { $pillCls = 'pill-neutral'; $pillLabel = 'Not started'; }
                                  else if ($isExpired)  { $pillCls = 'pill-warning'; $pillLabel = 'Expired'; }
                                  else                  { $pillCls = 'pill-success'; $pillLabel = 'Complete'; }
                                ?>
                                <span class="pill <?= $pillCls ?>"><?= h($pillLabel) ?></span>
                            </td>
                            <td class="muted small">
                                <?= $isDone ? h(date('d M Y H:i', strtotime($c['completed_at']))) : '—' ?>
                            </td>
                            <td class="muted small">
                                <?php if (!$isDone): ?>
                                    —
                                <?php elseif (empty($c['validity_months'])): ?>
                                    <span class="muted">No expiry</span>
                                <?php else: ?>
                                    <?= h(date('d M Y', strtotime($c['expires_at']))) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h3 style="margin: 18px 0 8px; font-size: 15px;">
        Quiz attempts
        <span class="muted small" style="font-weight: normal;">(latest <?= count($attempts) ?>)</span>
    </h3>
    <div class="card" style="margin-bottom: 18px;">
        <div class="card-body" style="padding: 0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 160px;">Date</th>
                        <th>Course</th>
                        <th>Step</th>
                        <th class="r" style="width: 80px;">Score</th>
                        <th class="r" style="width: 80px;">Pass</th>
                        <th style="width: 80px;">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attempts)): ?>
                        <tr><td colspan="6" class="muted" style="padding: 12px;">No quiz attempts yet.</td></tr>
                    <?php else: foreach ($attempts as $a): ?>
                        <tr>
                            <td class="muted small"><?= h(date('d M Y H:i', strtotime($a['attempted_at']))) ?></td>
                            <td>
                                <a href="<?= h(url('/training.php?action=view&id=' . (int)$a['course_id'])) ?>">
                                    <?= h($a['course_title']) ?>
                                </a>
                            </td>
                            <td><?= h($a['step_title']) ?></td>
                            <td class="r">
                                <a href="<?= h(url('/training.php?action=review_attempt&attempt=' . (int)$a['id'])) ?>"
                                   title="See which questions you got right or wrong">
                                    <?= h(number_format((float)$a['score_pct'], 1)) ?>%
                                </a>
                            </td>
                            <td class="r muted small"><?= (int)$a['pass_pct'] ?>%</td>
                            <td>
                                <?php if ((int)$a['passed'] === 1): ?>
                                    <span class="pill pill-success">Passed</span>
                                <?php else: ?>
                                    <span class="pill pill-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ------------------------------------------------------------
// REVIEW_ATTEMPT — show one attempt with right/wrong per question
// ------------------------------------------------------------
// Users review their own attempts; managers can view anyone's.
// Renders each question with the user's selected option(s) flagged
// and the correct option(s) flagged, so the learner can see what
// they missed.
// ------------------------------------------------------------
if ($action === 'review_attempt') {
    $attemptId = (int)input('attempt', 0);
    if ($attemptId <= 0) {
        flash_set('error', 'No attempt specified.');
        redirect(url('/training.php?action=attempts'));
    }
    $att = db_one(
        "SELECT a.*, s.title AS step_title, s.pass_pct, s.course_id,
                c.title AS course_title, c.slug AS course_slug
           FROM training_step_attempts a
           JOIN training_steps s   ON s.id = a.step_id
           JOIN training_courses c ON c.id = s.course_id
          WHERE a.id = ?",
        [$attemptId]
    );
    if (!$att) {
        flash_set('error', 'Attempt not found.');
        redirect(url('/training.php?action=attempts'));
    }
    if ((int)$att['user_id'] !== $uid && !$canManage) {
        flash_set('error', 'You can only review your own attempts.');
        redirect(url('/training.php?action=attempts'));
    }
    $responses = json_decode($att['responses_json'], true);
    if (!is_array($responses)) $responses = [];
    // Load the step's questions + options to render alongside
    $questions = training_step_questions((int)$att['step_id']);

    $page_title  = 'Review attempt';
    $page_module = 'training';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1 style="margin: 0;">Quiz review</h1>
            <p class="muted" style="margin: 4px 0 0;">
                <strong><?= h($att['course_title']) ?></strong> · <?= h($att['step_title']) ?>
                · attempted <?= h(date('d M Y H:i', strtotime($att['attempted_at']))) ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php?action=step&course=' . (int)$att['course_id'] . '&step=' . (int)$att['step_id'])) ?>">← Back to step</a>
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php?action=attempts')) ?>">All attempts</a>
        </div>
    </div>

    <!-- Score summary card -->
    <div class="card" style="margin-bottom: 14px;">
        <div class="card-body" style="padding: 12px 16px; display: flex; gap: 18px; align-items: center; flex-wrap: wrap;">
            <div>
                <span class="muted small">Score</span>
                <div style="font-size: 22px; font-weight: 700; line-height: 1.1;
                            color: <?= (int)$att['passed'] === 1 ? '#15803d' : '#b91c1c' ?>;">
                    <?= h(number_format((float)$att['score_pct'], 1)) ?>%
                </div>
            </div>
            <div>
                <span class="muted small">Pass mark</span>
                <div style="font-size: 16px;"><?= (int)$att['pass_pct'] ?>%</div>
            </div>
            <div>
                <span class="muted small">Result</span>
                <div>
                    <?php if ((int)$att['passed'] === 1): ?>
                        <span class="pill pill-success">✓ Passed</span>
                    <?php else: ?>
                        <span class="pill pill-danger">✗ Failed</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php
              // Count correct vs total
              $correctCount = 0; $totalCount = count($questions);
              $qStatus = []; // qid => 'right'|'wrong'|'partial'
              foreach ($questions as $q) {
                  $qid = (int)$q['id'];
                  $correctIds  = []; foreach ($q['options'] as $o) if ((int)$o['is_correct'] === 1) $correctIds[] = (int)$o['id'];
                  $userPicked  = isset($responses[$qid]) ? array_map('intval', (array)$responses[$qid]) : [];
                  sort($correctIds); sort($userPicked);
                  if ($correctIds === $userPicked) { $correctCount++; $qStatus[$qid] = 'right'; }
                  else $qStatus[$qid] = 'wrong';
              }
            ?>
            <div>
                <span class="muted small">Questions correct</span>
                <div style="font-size: 16px;"><?= $correctCount ?> of <?= $totalCount ?></div>
            </div>
        </div>
    </div>

    <!-- Per-question review -->
    <?php foreach ($questions as $qi => $q):
        $qid = (int)$q['id'];
        $status = $qStatus[$qid] ?? 'wrong';
        $userPicked = isset($responses[$qid]) ? array_map('intval', (array)$responses[$qid]) : [];
    ?>
        <div class="card" style="margin-bottom: 10px; border-left: 4px solid <?= $status === 'right' ? '#16a34a' : '#dc2626' ?>;">
            <div class="card-body" style="padding: 12px 14px;">
                <div style="font-weight: 600; margin-bottom: 8px; display: flex; gap: 8px; align-items: flex-start;">
                    <span style="font-size: 18px; line-height: 1; color: <?= $status === 'right' ? '#16a34a' : '#dc2626' ?>;">
                        <?= $status === 'right' ? '✓' : '✗' ?>
                    </span>
                    <span><?= $qi + 1 ?>. <?= h($q['body']) ?></span>
                </div>
                <?php foreach ($q['options'] as $o):
                    $oid = (int)$o['id'];
                    $isCorrect = (int)$o['is_correct'] === 1;
                    $userChose = in_array($oid, $userPicked, true);
                    // 4 visual states:
                    //   correct + chose      → green check + bold (got it right)
                    //   correct + didn't choose → green check, dim (missed)
                    //   wrong + chose       → red x + bold (picked wrong)
                    //   wrong + didn't choose → neutral (irrelevant)
                    if ($isCorrect && $userChose)      { $marker = '✓'; $color = '#16a34a'; $bg = '#dcfce7'; $weight = '600'; }
                    else if ($isCorrect && !$userChose){ $marker = '✓'; $color = '#16a34a'; $bg = '#f0fdf4'; $weight = '500'; }
                    else if (!$isCorrect && $userChose){ $marker = '✗'; $color = '#dc2626'; $bg = '#fee2e2'; $weight = '600'; }
                    else                               { $marker = '·'; $color = '#6b7280'; $bg = 'transparent'; $weight = '400'; }
                ?>
                    <div style="padding: 6px 10px; margin: 3px 0; background: <?= $bg ?>; border-radius: 4px;
                                display: flex; gap: 8px; align-items: center; font-size: 13px; font-weight: <?= $weight ?>; color: #1f2937;">
                        <span style="font-size: 14px; line-height: 1; color: <?= $color ?>; min-width: 14px;"><?= $marker ?></span>
                        <span><?= h($o['body']) ?></span>
                        <?php if ($isCorrect && !$userChose): ?>
                            <span class="muted small" style="margin-left: auto;">(correct, missed)</span>
                        <?php elseif ($isCorrect && $userChose): ?>
                            <span class="muted small" style="margin-left: auto; color: #16a34a;">(your answer · correct)</span>
                        <?php elseif (!$isCorrect && $userChose): ?>
                            <span class="muted small" style="margin-left: auto; color: #dc2626;">(your answer · wrong)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($q['explanation'])): ?>
                    <div style="margin-top: 8px; padding: 8px 10px; background: #f9fafb; border-radius: 4px; font-size: 12px; color: #4b5563;">
                        <strong>Explanation:</strong> <?= h($q['explanation']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($questions)): ?>
        <p class="muted">This attempt had no questions.</p>
    <?php endif; ?>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ------------------------------------------------------------
// QUIZ_SUBMIT — record a quiz attempt for one step
// ------------------------------------------------------------
if ($action === 'quiz_submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $stepId = (int)input('step', 0);
    $step = training_load_step($stepId);
    if (!$step) {
        flash_set('error', 'Step not found.');
        redirect(url('/training.php'));
    }
    $courseId = (int)$step['course_id'];
    // Permission gate
    $allowed = db_val(
        'SELECT 1 FROM training_courses c
           JOIN training_role_access tra ON tra.course_id = c.id
           JOIN user_roles ur ON ur.role_id = tra.role_id
          WHERE ur.user_id = ? AND c.id = ? LIMIT 1',
        [$uid, $courseId]
    );
    if (!$allowed) {
        flash_set('error', 'You do not have access to this course.');
        redirect(url('/training.php'));
    }
    // max_attempts gate
    if ((int)$step['max_attempts'] > 0) {
        $attempts = training_step_attempt_count($uid, $stepId);
        if ($attempts >= (int)$step['max_attempts']) {
            flash_set('error', 'You have reached the maximum number of attempts on this step.');
            redirect(url('/training.php?action=step&course=' . $courseId . '&step=' . $stepId));
        }
    }
    // Decode responses from form: q[<qid>][] = <option_id>
    $responses = [];
    if (isset($_POST['q']) && is_array($_POST['q'])) {
        foreach ($_POST['q'] as $qid => $vals) {
            // single-choice radio sends a scalar string, multi-choice
            // checkboxes send an array
            if (!is_array($vals)) $vals = [$vals];
            $responses[(int)$qid] = array_map('intval', $vals);
        }
    }
    try {
        $grade = training_grade_attempt($stepId, $responses);
        $res = training_record_attempt($uid, $stepId, $grade, $responses);
        if ($grade['passed']) {
            $msg = "Passed with " . $grade['score_pct'] . '%.';
            if ($res['course_marked']) {
                $msg .= ' Course completed!';
                if ($res['expires_at']) {
                    $msg .= ' Certification expires ' . date('d M Y', strtotime($res['expires_at'])) . '.';
                }
            }
            flash_set('success', $msg);
        } else {
            flash_set('error', "Score " . $grade['score_pct'] . '% — below required ' . $step['pass_pct'] . '%. Review and try again.');
        }
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(url('/training.php?action=step&course=' . $courseId . '&step=' . $stepId));
}

// ------------------------------------------------------------
// NEW / EDIT
// ------------------------------------------------------------
if ($action === 'step_edit') {
    require_permission('training', 'manage');
    $courseId = (int)input('course', 0);
    $stepId   = (int)input('step', 0);
    $course = db_one('SELECT * FROM training_courses WHERE id = ?', [$courseId]);
    if (!$course) { flash_set('error', 'Course not found.'); redirect(url('/training.php')); }
    $step = null;
    if ($stepId > 0) {
        $step = db_one('SELECT * FROM training_steps WHERE id = ? AND course_id = ?', [$stepId, $courseId]);
        if (!$step) { flash_set('error', 'Step not found.'); redirect(url('/training.php?action=edit&id=' . $courseId)); }
    }
    // Load questions (with options) for this step
    $questions = $step ? training_step_questions((int)$step['id']) : [];
    // Step screenshots
    $stepScreens = $step ? db_all(
        'SELECT * FROM training_screenshots WHERE step_id = ? ORDER BY sort_order, id',
        [(int)$step['id']]
    ) : [];

    $page_title  = $step ? 'Edit step: ' . $step['title'] : 'New step';
    $page_module = 'training';
    $focus_id    = 'f_step_title';

    $deleteHtml = '';
    if ($step) {
        $deleteHtml = ' <form method="post" style="display:inline;"'
                    . ' action="' . h(url('/training.php?action=step_delete')) . '"'
                    . ' onsubmit="return confirm(\'Delete this step and all its questions / screenshots?\');">'
                    . csrf_field()
                    . '<input type="hidden" name="step_id" value="' . (int)$step['id'] . '">'
                    . '<button class="btn btn-danger btn-sm" type="submit">Delete step</button>'
                    . '</form>';
    }

    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'      => $step ? 'Edit step' : 'New step',
            'subtitle'   => ($course['title']) . ($step ? ' · ' . $step['title'] : ''),
            'back_href'  => url('/training.php?action=edit&id=' . $courseId),
            'back_label' => 'Back to course',
            'actions_html' =>
                '<button type="submit" form="step-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save step', 'S') . '</button>'
              . $deleteHtml,
        ]) ?>
        <div class="form-page-body">
            <form id="step-form" method="post" action="<?= h(url('/training.php?action=step_save')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="step_id" value="<?= $step ? (int)$step['id'] : '' ?>">
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="f_step_title">Title *</label>
                        <input id="f_step_title" name="title" type="text" required
                               value="<?= h($step['title'] ?? '') ?>">
                    </div>
                    <div class="field span-2">
                        <label for="f_step_body">Body (HTML allowed)</label>
                        <textarea id="f_step_body" name="body_html" rows="14"
                                  style="font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;"
                        ><?= h($step['body_html'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label for="f_step_pass">Pass percentage</label>
                        <input id="f_step_pass" name="pass_pct" type="number" min="0" max="100"
                               value="<?= (int)($step['pass_pct'] ?? 100) ?>">
                        <span class="muted small">Quiz score required to mark step complete. Default 100.</span>
                    </div>
                    <div class="field">
                        <label for="f_step_maxatt">Max attempts</label>
                        <input id="f_step_maxatt" name="max_attempts" type="number" min="0"
                               value="<?= (int)($step['max_attempts'] ?? 0) ?>">
                        <span class="muted small">0 = unlimited.</span>
                    </div>
                    <div class="field">
                        <label class="nowrap" style="font-weight:normal;">
                            <input type="checkbox" name="is_active" value="1"
                                   <?= (!$step || $step['is_active']) ? 'checked' : '' ?>>
                            Active
                        </label>
                    </div>
                </div>
            </form>

            <?php if ($step): ?>
                <!-- ============================================
                     QUESTIONS PANEL
                     ============================================ -->
                <div class="form-section">
                    <h2>Quiz questions <span class="muted small" style="font-weight: normal;">(<?= count($questions) ?>)</span></h2>
                    <p class="muted small">Each question shows during the step view. Users must pass the threshold (<?= (int)$step['pass_pct'] ?>%) to mark the step complete.</p>

                    <?php if (!empty($questions)): ?>
                        <?php foreach ($questions as $qi => $q): ?>
                            <div class="card" style="margin-bottom: 12px;">
                                <div class="card-head" style="display: flex; align-items: center; justify-content: space-between;">
                                    <h3 style="margin: 0; font-size: 14px;">
                                        Q<?= $qi + 1 ?>:
                                        <?= h(mb_substr($q['body'], 0, 80)) ?><?= mb_strlen($q['body']) > 80 ? '…' : '' ?>
                                        <span class="muted small" style="font-weight: normal; margin-left: 8px;">
                                            (<?= $q['question_type'] === 'multi_choice' ? 'multi-correct' : 'single-correct' ?>)
                                        </span>
                                    </h3>
                                    <div>
                                        <button type="button" class="btn btn-ghost btn-xs"
                                                onclick="document.getElementById('q-edit-<?= (int)$q['id'] ?>').toggleAttribute('hidden')">Edit</button>
                                        <form method="post" action="<?= h(url('/training.php?action=question_delete')) ?>"
                                              style="display: inline;"
                                              onsubmit="return confirm('Delete this question?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                            <button type="submit" class="btn btn-ghost btn-xs">×</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <ol style="margin: 0 0 0 22px; padding: 0;">
                                        <?php foreach ($q['options'] as $o): ?>
                                            <li style="margin-bottom: 4px;">
                                                <?= h($o['body']) ?>
                                                <?php if ($o['is_correct']): ?>
                                                    <span class="pill pill-success" style="font-size: 10px;">correct</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                    <div id="q-edit-<?= (int)$q['id'] ?>" hidden style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                                        <?= render_question_form($courseId, (int)$step['id'], $q) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <details style="margin-top: 14px;">
                        <summary style="cursor: pointer; font-weight: 600;">+ Add new question</summary>
                        <div style="margin-top: 12px;">
                            <?= render_question_form($courseId, (int)$step['id'], null) ?>
                        </div>
                    </details>
                </div>

                <!-- ============================================
                     STEP SCREENSHOTS PANEL
                     ============================================ -->
                <div class="form-section">
                    <h2>Step screenshots <span class="muted small" style="font-weight: normal;">(<?= count($stepScreens) ?>)</span></h2>
                    <p class="muted small">Attached to this step only. (Course-level screenshots are managed on the parent course page.)</p>

                    <form method="post" enctype="multipart/form-data"
                          action="<?= h(url('/training.php?action=upload&id=' . $courseId)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>">
                        <div class="form-grid">
                            <div class="field span-2">
                                <label>Image file</label>
                                <input name="screenshot" type="file"
                                       accept="image/png,image/jpeg,image/gif,image/webp" required>
                            </div>
                            <div class="field span-2">
                                <label>Caption (optional)</label>
                                <input name="caption" type="text">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-primary btn-sm" type="submit">Upload to step</button>
                        </div>
                    </form>

                    <?php if (!empty($stepScreens)): ?>
                        <div class="screenshot-strip" style="margin-top: 16px;">
                            <?php foreach ($stepScreens as $s): ?>
                                <figure>
                                    <img src="<?= h(url('/' . ltrim($s['file_path'], '/'))) ?>" alt="<?= h($s['caption']) ?>">
                                    <figcaption>
                                        <?= h($s['caption'] ?: 'Screenshot') ?>
                                        <form method="post" style="float:right; margin-top:-2px;"
                                              action="<?= h(url('/training.php?action=screenshot_delete')) ?>"
                                              onsubmit="return confirm('Remove this screenshot?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-xs btn-ghost" type="submit">Remove</button>
                                        </form>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'new' || $action === 'edit') {
    $editing = null;
    $screens = [];
    $courseRoles = [];
    if ($action === 'edit') {
        require_permission('training', 'manage');
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM training_courses WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Course not found.'); redirect(url('/training.php')); }
        $screens = db_all('SELECT * FROM training_screenshots WHERE course_id = ? ORDER BY sort_order, id', [$id]);
        $courseRoles = array_column(
            db_all('SELECT role_id FROM training_role_access WHERE course_id = ?', [$id]),
            'role_id'
        );
    } else {
        require_permission('training', 'create');
    }
    $allRoles = db_all('SELECT * FROM roles ORDER BY name');

    $page_title  = $editing ? 'Edit course' : 'New course';
    $page_module = 'training';
    $focus_id    = 'f_title';

    $deleteHtml = '';
    if ($editing && $canDelete) {
        $deleteHtml =
            ' <form method="post" style="display:inline;"'
          . ' action="' . h(url('/training.php?action=delete')) . '"'
          . ' onsubmit="return confirm(\'Delete this course and all its screenshots?\');">'
          . csrf_field()
          . '<input type="hidden" name="id" value="' . (int)$editing['id'] . '">'
          . '<button class="btn btn-danger btn-sm" type="submit">Delete</button>'
          . '</form>';
    }
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit course' : 'New course',
            'subtitle'    => $editing ? $editing['title'] : 'Add training content',
            'back_href'   => url('/training.php'),
            'back_label'  => 'Training',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/training.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>'
              . $deleteHtml,
        ]) ?>
        <div class="form-page-body">
            <form id="main-form" method="post" action="<?= h(url('/training.php?action=save')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

                <div class="form-grid">
                    <div class="field span-2">
                        <label for="f_title"><?= shortcut_label('Title', 'T') ?> *</label>
                        <input id="f_title" name="title" type="text" required tabindex="1"
                               value="<?= h($editing['title'] ?? '') ?>">
                    </div>
                    <div class="field span-2">
                        <label for="f_desc"><?= shortcut_label('Description', 'D') ?></label>
                        <input id="f_desc" name="description" type="text" tabindex="2"
                               value="<?= h($editing['description'] ?? '') ?>">
                    </div>
                    <div class="field span-2">
                        <label for="f_body">Body (HTML allowed)</label>
                        <textarea id="f_body" name="body_html" rows="10" tabindex="3"
                                  style="font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;"
                        ><?= h($editing['body_html'] ?? '') ?></textarea>
                    </div>
                    <div class="field span-2">
                        <label><?= shortcut_label('Role access', 'R') ?></label>
                        <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:4px;">
                            <?php foreach ($allRoles as $r): ?>
                                <label class="nowrap" style="font-weight:normal;">
                                    <input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>" tabindex="4"
                                           <?= in_array($r['id'], $courseRoles) ? 'checked' : '' ?>>
                                    <?= h($r['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="muted small">Members of the ticked roles will see this course in their training list.</span>
                    </div>
                    <div class="field">
                        <label class="nowrap" style="font-weight:normal;">
                            <input type="checkbox" name="is_active" value="1" tabindex="5"
                                   <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                            <?= shortcut_label('Active', 'A') ?>
                        </label>
                    </div>
                    <div class="field">
                        <label for="f_nav_mode">Navigation mode</label>
                        <select id="f_nav_mode" name="nav_mode">
                            <option value="free"   <?= (($editing['nav_mode'] ?? 'free') === 'free')   ? 'selected' : '' ?>>Free — user can jump between steps</option>
                            <option value="strict" <?= (($editing['nav_mode'] ?? 'free') === 'strict') ? 'selected' : '' ?>>Strict — must complete steps in order</option>
                        </select>
                        <span class="muted small">Only applies to courses with steps.</span>
                    </div>
                    <div class="field">
                        <label for="f_validity">Certification validity (months)</label>
                        <input id="f_validity" name="validity_months" type="number" min="0" step="1"
                               placeholder="leave blank for no expiry"
                               value="<?= h($editing['validity_months'] ?? '') ?>">
                        <span class="muted small">e.g. 12 for annual re-certification. Blank or 0 = never expires.</span>
                    </div>
                </div>
            </form>

            <?php if ($editing): ?>
                <!-- ============================================
                     STEPS PANEL
                     ============================================ -->
                <?php
                    $stepsForAdmin = training_load_steps((int)$editing['id']);
                    // Also count quiz questions per step
                    $qCounts = [];
                    if (!empty($stepsForAdmin)) {
                        $sIds = implode(',', array_map(function ($s) { return (int)$s['id']; }, $stepsForAdmin));
                        foreach (db_all(
                            "SELECT step_id, COUNT(*) AS n FROM training_step_questions
                              WHERE step_id IN ($sIds) AND is_active = 1 GROUP BY step_id"
                        ) as $row) {
                            $qCounts[(int)$row['step_id']] = (int)$row['n'];
                        }
                    }
                ?>
                <div class="form-section">
                    <h2>Steps <span class="muted small" style="font-weight: normal;">(<?= count($stepsForAdmin) ?>)</span></h2>
                    <p class="muted small">Multi-step courses navigate step-by-step. Each step can have its own body, screenshots, and quiz. Drag rows to reorder.</p>

                    <?php if (!empty($stepsForAdmin)): ?>
                        <form method="post" action="<?= h(url('/training.php?action=step_reorder')) ?>" id="step-reorder-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="course_id" value="<?= (int)$editing['id'] ?>">
                            <table class="data-table" id="steps-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th>Title</th>
                                        <th>Pass %</th>
                                        <th>Max attempts</th>
                                        <th>Questions</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stepsForAdmin as $i => $s): ?>
                                        <tr draggable="true" data-step-id="<?= (int)$s['id'] ?>" style="cursor: move;">
                                            <td><strong><?= $i + 1 ?></strong>
                                                <input type="hidden" name="step_ids[]" value="<?= (int)$s['id'] ?>">
                                            </td>
                                            <td><?= h($s['title']) ?></td>
                                            <td><?= (int)$s['pass_pct'] ?>%</td>
                                            <td><?= (int)$s['max_attempts'] === 0 ? '∞' : (int)$s['max_attempts'] ?></td>
                                            <td><?= isset($qCounts[(int)$s['id']]) ? $qCounts[(int)$s['id']] : 0 ?></td>
                                            <td>
                                                <?php if ($s['is_active']): ?>
                                                    <span class="pill pill-active">active</span>
                                                <?php else: ?>
                                                    <span class="pill pill-neutral">disabled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="r nowrap">
                                                <a class="btn btn-xs btn-ghost"
                                                   href="<?= h(url('/training.php?action=step_edit&course=' . (int)$editing['id'] . '&step=' . (int)$s['id'])) ?>">Edit</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="form-actions" style="margin-top: 8px;">
                                <button type="submit" class="btn btn-sm btn-ghost" id="step-reorder-save" style="display:none;">Save new order</button>
                                <a class="btn btn-sm btn-primary"
                                   href="<?= h(url('/training.php?action=step_edit&course=' . (int)$editing['id'])) ?>">+ Add step</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="muted">No steps yet. <a href="<?= h(url('/training.php?action=step_edit&course=' . (int)$editing['id'])) ?>">Add the first step</a> to convert this to a multi-step course (users will see step-by-step navigation; the course body above becomes a cover page only for admins).</p>
                    <?php endif; ?>
                </div>

                <!-- ============================================
                     PREREQUISITES PANEL
                     ============================================ -->
                <?php
                    $prereqs = db_all(
                        'SELECT p.prereq_course_id, p.gate_mode, c.title
                           FROM training_prerequisites p
                           JOIN training_courses c ON c.id = p.prereq_course_id
                          WHERE p.course_id = ?
                          ORDER BY c.title',
                        [(int)$editing['id']]
                    );
                    $otherCourses = db_all(
                        'SELECT id, title FROM training_courses
                          WHERE id <> ? AND is_active = 1
                          ORDER BY title',
                        [(int)$editing['id']]
                    );
                ?>
                <div class="form-section">
                    <h2>Prerequisites <span class="muted small" style="font-weight: normal;">(<?= count($prereqs) ?>)</span></h2>
                    <p class="muted small">Block this course on completion of others. <strong>Hard</strong> = user can't open the course; <strong>soft</strong> = visible but Complete is disabled.</p>

                    <?php if (!empty($prereqs)): ?>
                        <table class="data-table" style="margin-bottom: 12px;">
                            <thead><tr><th>Prerequisite course</th><th>Gate</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($prereqs as $p): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= h(url('/training.php?action=edit&id=' . (int)$p['prereq_course_id'])) ?>">
                                                <?= h($p['title']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($p['gate_mode'] === 'hard'): ?>
                                                <span class="pill pill-danger">hard</span>
                                            <?php else: ?>
                                                <span class="pill pill-warning">soft</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="r">
                                            <form method="post" action="<?= h(url('/training.php?action=prereq_delete')) ?>"
                                                  style="display:inline;"
                                                  onsubmit="return confirm('Remove this prerequisite?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="course_id" value="<?= (int)$editing['id'] ?>">
                                                <input type="hidden" name="prereq_course_id" value="<?= (int)$p['prereq_course_id'] ?>">
                                                <button class="btn btn-xs btn-ghost" type="submit">×</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($otherCourses)): ?>
                        <form method="post" action="<?= h(url('/training.php?action=prereq_save')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="course_id" value="<?= (int)$editing['id'] ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label>Add prerequisite course</label>
                                    <select name="prereq_course_id">
                                        <option value="">— pick course —</option>
                                        <?php foreach ($otherCourses as $oc): ?>
                                            <option value="<?= (int)$oc['id'] ?>"><?= h($oc['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Gate mode</label>
                                    <select name="gate_mode">
                                        <option value="soft">Soft — block completion only</option>
                                        <option value="hard">Hard — block course entirely</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-sm btn-primary">+ Add prerequisite</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h2>Screenshots</h2>
                    <p class="muted small">PNG / JPG / GIF / WEBP, up to <?= (int)$GLOBALS['APP']['upload_max_mb'] ?> MB each.</p>

                    <form method="post" enctype="multipart/form-data"
                          action="<?= h(url('/training.php?action=upload&id=' . (int)$editing['id'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                        <div class="form-grid">
                            <div class="field span-2" data-drop-zone="training-shot">
                                <label for="f_screenshot">Choose image (or drag onto this area)</label>
                                <input id="f_screenshot" name="screenshot" type="file"
                                       accept="image/png,image/jpeg,image/gif,image/webp" required>
                            </div>
                            <div class="field span-2">
                                <label for="f_caption">Caption (optional)</label>
                                <input id="f_caption" name="caption" type="text">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit" data-shortcut="U" accesskey="u">
                                <?= shortcut_label('Upload', 'U') ?>
                            </button>
                        </div>
                    </form>

                    <?php if ($screens): ?>
                        <div class="screenshot-strip" style="margin-top: 16px;">
                            <?php foreach ($screens as $s): ?>
                                <figure>
                                    <img src="<?= h(url('/' . ltrim($s['file_path'], '/'))) ?>" alt="<?= h($s['caption']) ?>">
                                    <figcaption>
                                        <?= h($s['caption'] ?: 'Screenshot') ?>
                                        <form method="post" style="float:right; margin-top:-2px;"
                                              action="<?= h(url('/training.php?action=screenshot_delete')) ?>"
                                              onsubmit="return confirm('Remove this screenshot?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-ghost" type="submit">Remove</button>
                                        </form>
                                    </figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted small" style="margin-top: 12px;">No screenshots yet.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div><!-- /.form-page-body -->
    </div><!-- /.form-page -->
    <?php if ($editing): ?>
    <script>
    // Drag-to-reorder for the steps table. Saves via the "Save new order"
    // button (it un-hides as soon as the first drag happens).
    (function () {
        var tbody = document.querySelector('#steps-table tbody');
        if (!tbody) return;
        var saveBtn = document.getElementById('step-reorder-save');
        var dragRow = null;
        tbody.addEventListener('dragstart', function (e) {
            var row = e.target.closest('tr');
            if (!row) return;
            dragRow = row;
            e.dataTransfer.effectAllowed = 'move';
            row.style.opacity = '0.4';
        });
        tbody.addEventListener('dragend', function (e) {
            if (dragRow) dragRow.style.opacity = '';
            dragRow = null;
        });
        tbody.addEventListener('dragover', function (e) {
            e.preventDefault();
            var row = e.target.closest('tr');
            if (!row || row === dragRow) return;
            var rect = row.getBoundingClientRect();
            var midpoint = rect.top + rect.height / 2;
            if (e.clientY < midpoint) {
                tbody.insertBefore(dragRow, row);
            } else {
                tbody.insertBefore(dragRow, row.nextSibling);
            }
        });
        tbody.addEventListener('drop', function (e) {
            e.preventDefault();
            // Renumber the visible position column + reveal save button
            var rows = tbody.querySelectorAll('tr');
            rows.forEach(function (r, i) {
                var cell = r.querySelector('td:first-child strong');
                if (cell) cell.textContent = (i + 1);
            });
            if (saveBtn) saveBtn.style.display = '';
        });
    })();
    </script>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ------------------------------------------------------------
// VIEW
// ------------------------------------------------------------
if ($action === 'view') {
    $id = (int)input('id', 0);
    // Permission: must be in role allow-list OR be a manager
    $course = db_one(
        $canManage
            ? 'SELECT * FROM training_courses WHERE id = ? AND is_active = 1'
            : 'SELECT DISTINCT c.* FROM training_courses c
                 JOIN training_role_access tra ON tra.course_id = c.id
                 JOIN user_roles ur ON ur.role_id = tra.role_id
                WHERE ur.user_id = ? AND c.id = ? AND c.is_active = 1 LIMIT 1',
        $canManage ? [$id] : [$uid, $id]
    );
    if (!$course) { flash_set('error', 'Course not found or not accessible.'); redirect(url('/training.php')); }

    // Phase 1: if the course has steps, route to step view.
    // (This now applies to managers too, so they see the same step-by-step
    // flow learners do — including any course-quiz step. Previously
    // managers were kept on the legacy single-body view as a 'cover' to
    // see body_html + screenshot manager, but that made quizzes
    // invisible to them. Managers can still use the Edit page for the
    // legacy-body view.)
    $stepsCount = (int)db_val(
        "SELECT COUNT(*) FROM training_steps WHERE course_id = ? AND is_active = 1",
        [$id]
    );
    if ($stepsCount > 0) {
        redirect(url('/training.php?action=step&course=' . $id));
    }

    // Hard-prereq gate for the legacy single-body view too
    if (!$canManage) {
        $pr = training_check_prerequisites($uid, $id);
        if (!$pr['ok']) {
            $names = array_map(function ($b) { return $b['course_title']; }, $pr['blockers']);
            flash_set('error', 'Locked — complete first: ' . implode(', ', $names));
            redirect(url('/training.php'));
        }
    } else {
        $pr = ['ok' => true, 'all_met' => true, 'blockers' => []];
    }

    $screens = db_all('SELECT * FROM training_screenshots WHERE course_id = ? AND (step_id IS NULL) ORDER BY sort_order, id', [$id]);
    // Load the full progress row (includes expires_at) instead of just completed_at
    $progress = training_user_progress($uid, $id);
    $done = $progress ? $progress['completed_at'] : null;

    $page_title  = $course['title'];
    $page_module = 'training';
    $focus_id    = '';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($course['title']) ?></h1>
            <p class="muted">
                <?= h($course['description'] ?: '') ?>
                <?php if ($done): ?>
                    <span class="pill pill-success" style="margin-left: 8px;">completed <?= h(dt_display($done)) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/training.php')) ?>" data-shortcut="B" accesskey="b">
                <?= shortcut_label('← Back', 'B') ?>
            </a>
            <?php if ($canManage): ?>
                <a class="btn btn-ghost" href="<?= h(url('/training.php?action=edit&id=' . $id)) ?>"
                   data-shortcut="E" accesskey="e">
                    <?= shortcut_label('Edit', 'E') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?= $course['body_html'] /* trusted: admin-authored HTML */ ?>

            <?php if ($screens): ?>
                <div class="screenshot-strip">
                    <?php foreach ($screens as $s): ?>
                        <figure>
                            <img src="<?= h(url('/' . ltrim($s['file_path'], '/'))) ?>" alt="<?= h($s['caption']) ?>">
                            <figcaption><?= h($s['caption'] ?: 'Screenshot') ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
                // Status pill — completion + expiry
                $pill = training_progress_pill($progress);
            ?>
            <div style="margin-top: 24px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <span class="pill <?= h($pill['class']) ?>" style="font-size: 13px;">
                    <?= h($pill['label']) ?>
                </span>
                <?php if ($progress && $progress['completed_at']): ?>
                    <span class="muted small">
                        Completed <?= h(date('d M Y', strtotime($progress['completed_at']))) ?>
                        <?php if ($progress['expires_at']): ?>
                            · Valid until <?= h(date('d M Y', strtotime($progress['expires_at']))) ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$pr['all_met']): ?>
                <div class="card" style="margin-top: 16px; background: #fffce8; border-left: 3px solid #d97706;">
                    <div class="card-body">
                        <strong>Soft prerequisites unmet</strong> — you can read this course, but you can't mark it complete until you finish:
                        <ul style="margin: 6px 0 0 22px;">
                            <?php foreach ($pr['blockers'] as $b): ?>
                                <li>
                                    <a href="<?= h(url('/training.php?action=view&id=' . (int)$b['course_id'])) ?>">
                                        <?= h($b['course_title']) ?>
                                    </a>
                                    <?php if ($b['reason'] === 'expired'): ?>
                                        <span class="muted small">(your previous certification expired — re-take)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= h(url('/training.php?action=complete')) ?>"
                  style="margin-top: 16px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button class="btn btn-success" type="submit"
                        <?= !$pr['all_met'] ? 'disabled' : '' ?>
                        data-shortcut="M" accesskey="m"
                        title="<?= !$pr['all_met'] ? 'Prerequisites unmet' : '' ?>">
                    <?= shortcut_label($progress && $progress['completed_at'] ? 'Re-mark complete' : 'Mark complete', 'M') ?>
                </button>
            </form>
        </div>
    </div>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ------------------------------------------------------------
// LIST
// ------------------------------------------------------------
$myCourses = visible_courses_for($uid);
// Per-course progress for the current user (includes expiry)
$progByCourse = [];
foreach (db_all(
    'SELECT course_id, completed_at, expires_at FROM training_progress WHERE user_id = ?',
    [$uid]
) as $r) {
    $r['is_expired'] = $r['expires_at'] !== null && strtotime($r['expires_at']) < time();
    $progByCourse[(int)$r['course_id']] = $r;
}
// Step count per course (so the list can show "5 steps" or hide it for single-body)
$stepCounts = [];
if (!empty($myCourses)) {
    $cids = implode(',', array_map(function ($c) { return (int)$c['id']; }, $myCourses));
    foreach (db_all(
        "SELECT course_id, COUNT(*) AS n FROM training_steps
          WHERE course_id IN ($cids) AND is_active = 1 GROUP BY course_id"
    ) as $r) {
        $stepCounts[(int)$r['course_id']] = (int)$r['n'];
    }
}

$page_title  = 'Training';
$page_module = 'training';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Training</h1>
        <p class="muted"><?= count($myCourses) ?> course<?= count($myCourses) === 1 ? '' : 's' ?> available to you</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost btn-sm" href="<?= h(url('/training.php?action=attempts')) ?>"
           title="See your course completions and quiz attempts">📊 My history</a>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= h(url('/training.php?action=new')) ?>"
               data-shortcut="N" accesskey="n">
                <?= shortcut_label('+ New course', 'N') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <table class="data-table">
        <thead>
        <tr>
            <th>Title</th>
            <th>Description</th>
            <th>Format</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$myCourses): ?>
            <tr><td colspan="5" class="empty">No training is currently assigned to your role(s).</td></tr>
        <?php else: foreach ($myCourses as $c):
            $progress = isset($progByCourse[(int)$c['id']]) ? $progByCourse[(int)$c['id']] : null;
            $pill = training_progress_pill($progress);
            $nSteps = isset($stepCounts[(int)$c['id']]) ? $stepCounts[(int)$c['id']] : 0;
        ?>
            <tr>
                <td>
                    <strong>
                        <a href="<?= h(url('/training.php?action=view&id=' . (int)$c['id'])) ?>">
                            <?= h($c['title']) ?>
                        </a>
                    </strong>
                </td>
                <td class="muted small"><?= h($c['description'] ?: '') ?></td>
                <td class="muted small">
                    <?php if ($nSteps > 0): ?>
                        <?= $nSteps ?>-step course
                        <?php if (($c['nav_mode'] ?? 'free') === 'strict'): ?>
                            · <span title="Sequential mode">strict</span>
                        <?php endif; ?>
                    <?php else: ?>
                        Single page
                    <?php endif; ?>
                    <?php if (!empty($c['validity_months'])): ?>
                        <br>Valid <?= (int)$c['validity_months'] ?> months
                    <?php endif; ?>
                </td>
                <td>
                    <span class="pill <?= h($pill['class']) ?>"><?= h($pill['label']) ?></span>
                    <?php if ($progress && $progress['expires_at']): ?>
                        <br><span class="muted small">expires <?= h(date('d M Y', strtotime($progress['expires_at']))) ?></span>
                    <?php endif; ?>
                </td>
                <td class="r">
                    <a class="btn btn-sm btn-ghost"
                       href="<?= h(url('/training.php?action=view&id=' . (int)$c['id'])) ?>">Open</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php
// Show "all courses" management list for managers
if ($canManage) {
    require_once __DIR__ . '/includes/datatable.php';
    $dtCfg = [
        'id'       => 'training_admin',
        'base_sql' => 'SELECT * FROM training_courses',
        'columns'  => [
            ['key'=>'title',       'label'=>'Title',       'sortable'=>true, 'searchable'=>true, 'sql_col'=>'title'],
            ['key'=>'description', 'label'=>'Description', 'sortable'=>false,'searchable'=>true, 'sql_col'=>'description', 'td_class'=>'muted small'],
            ['key'=>'is_active',   'label'=>'Status',      'sortable'=>true, 'searchable'=>false,'sql_col'=>'is_active'],
            ['key'=>'_actions',    'label'=>'Actions',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['title', 'asc'],
    ];
    $rowRenderer = function ($c) {
        $status = $c['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">disabled</span>';
        $actions = '<a class="btn btn-icon" href="' . h(url('/training.php?action=edit&id=' . (int)$c['id'])) . '" title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a>';
        return [
            'title'       => '<strong>' . h($c['title']) . '</strong>',
            'description' => h($c['description'] ?: ''),
            'is_active'   => $status,
            '_actions'    => dt_actions_wrap($actions),
        ];
    };
    $dt = data_table_run($dtCfg, $rowRenderer);
    ?>
    <div class="card" style="margin-top: 24px;">
        <div class="card-head">
            <h2>All courses (admin view)</h2>
            <span class="muted small">Every course, including ones you don't have role access to.</span>
        </div>
        <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    </div>
    <?php
}
require __DIR__ . '/includes/footer.php';
?>
