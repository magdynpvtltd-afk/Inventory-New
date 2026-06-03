<?php
/**
 * MagDyn — Reusable email composer (Phase D2.5)
 *
 * Pages that want a Send-Mail form pass a $ctx array describing
 * recipients, subject defaults, attachments, etc. This file provides:
 *
 *   render_email_compose_page($ctx)   — emits the full composer page
 *   handle_email_send_post($ctx, $uid) — processes the POST, returns
 *      ['ok' => bool, 'error' => string|null, 'recipients' => N]
 *
 * The $ctx shape (all keys required unless noted):
 *
 *   related_type      string   — 'po' | 'transmittal' | ... (logged on sent_emails)
 *   related_id        int      — id of the related entity
 *   page_title        string
 *   back_url          string   — Cancel/Back link target
 *   permission        ['module' => ..., 'action' => 'email']
 *   subject_default   string
 *   body_default      string   — HTML; goes into Quill on first load
 *   contacts          array    — [{id, name, salutation?, designation?, email, is_primary?}]
 *   extra_to_default  string   — optional pre-filled extra To addresses (csv)
 *   reply_to_default  string   — optional pre-filled Reply-To
 *   attach_auto       array    — [
 *                                  {label, description?, default_on=true, hidden_path,
 *                                   filename, mime?, toggle_name}, ...
 *                                ] each toggleable in the form; if the toggle is on
 *                                at send time, the file at hidden_path attaches as filename
 *   send_url          string   — the POST target (page action=email_send)
 *   redirect_url      string   — post-send redirect (typically back to view)
 *
 * Auto-attachments use a server-side trusted path stored in the form
 * via a tamper-evident token (the related_type+related_id+name+random
 * are hashed against a session secret) so an attacker can't redirect
 * the attach to an arbitrary server path.
 */

require_once __DIR__ . '/_email.php';
require_once __DIR__ . '/_purchase_orders.php';   // magdyn_setting

/**
 * Sign an auto-attachment's path so we can verify it at POST time.
 * Uses the session-bound CSRF secret indirectly — we just hash with
 * the user's id + a fixed app salt so it's tied to this user's
 * session at this moment.
 */
function _ec_attach_sign($relType, $relId, $name, $path)
{
    $uid    = (int)current_user_id();
    $salt   = (string)magdyn_setting('attach.signing_salt', '');
    if ($salt === '') {
        // Lazy-init a per-app salt on first call. Stored in
        // magdyn_settings so it survives restarts.
        $salt = bin2hex(random_bytes(16));
        db_exec(
            "INSERT INTO magdyn_settings (setting_key, setting_value) VALUES ('attach.signing_salt', ?)
             ON DUPLICATE KEY UPDATE setting_value = setting_value",
            [$salt]
        );
    }
    return hash_hmac('sha256', "$relType|$relId|$name|$path|$uid", $salt);
}

/**
 * Render the composer page. Caller has already done permission checks
 * and bootstrap; this prints the header + form + footer.
 */
function render_email_compose_page(array $ctx)
{
    global $page_title, $page_module, $focus_id;
    $page_title  = $ctx['page_title'];
    $page_module = $ctx['permission']['module'] ?? '';
    $focus_id    = '';

    $smtpReady = (string)magdyn_setting('smtp.enabled', '0') === '1'
              && magdyn_setting('smtp.host', '') !== ''
              && magdyn_setting('smtp.user', '') !== ''
              && magdyn_setting('smtp.from_email', '') !== '';

    $contacts    = (array)($ctx['contacts'] ?? []);
    $attachAuto  = (array)($ctx['attach_auto'] ?? []);
    $extraTo     = (string)($ctx['extra_to_default'] ?? '');
    $replyTo     = (string)($ctx['reply_to_default'] ?? '');
    $subject     = (string)($ctx['subject_default'] ?? '');
    $bodyDefault = (string)($ctx['body_default'] ?? '');

    require __DIR__ . '/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($ctx['page_title']) ?></h1>
            <p class="muted small">
                <a href="<?= h($ctx['back_url']) ?>">← Back</a>
            </p>
        </div>
    </div>

    <?php if (!$smtpReady): ?>
        <div class="alert alert-warn" style="margin-bottom: 14px;">
            <strong>SMTP not configured.</strong>
            Open <a href="<?= h(url('/settings.php?tab=smtp')) ?>">Settings → SMTP</a>
            and fill host / port / username / password / from-email, then enable.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h($ctx['send_url']) ?>"
          enctype="multipart/form-data" class="card" id="email-compose-form"
          style="padding: 16px; max-width: 980px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$ctx['related_id'] ?>">
        <input type="hidden" name="related_type" value="<?= h($ctx['related_type']) ?>">

        <div class="field">
            <label>To <span class="muted small">(pick contacts with emails)</span></label>
            <?php if (!$contacts): ?>
                <p class="muted small">No contacts with email addresses available — use the override field below.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 4px; padding: 8px;
                            border: 1px solid var(--border); border-radius: 4px;">
                    <?php foreach ($contacts as $c): ?>
                        <label class="inline" style="gap: 8px;">
                            <input type="checkbox" name="to_contact_ids[]" value="<?= (int)$c['id'] ?>"
                                   <?= !empty($c['is_primary']) ? 'checked' : '' ?>>
                            <span>
                                <?= h(trim(($c['salutation'] ?? '') . ' ' . $c['name'])) ?>
                                <?php if (!empty($c['designation'])): ?>
                                    <span class="muted small">· <?= h($c['designation']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($c['is_primary'])): ?>
                                    <span class="pill pill-info" style="margin-left:4px;">primary</span>
                                <?php endif; ?>
                                <code style="margin-left:6px;"><?= h($c['email']) ?></code>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="f_to_extra">Additional To addresses <span class="muted small">(comma or semicolon separated)</span></label>
            <input type="text" id="f_to_extra" name="to_extra" value="<?= h($extraTo) ?>"
                   placeholder="extra@example.com, another@example.com">
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="f_cc">CC</label>
                <input type="text" id="f_cc" name="cc" placeholder="cc1@example.com, cc2@example.com">
            </div>
            <div class="field">
                <label for="f_bcc">BCC</label>
                <input type="text" id="f_bcc" name="bcc" placeholder="bcc@example.com">
            </div>
        </div>

        <div class="field">
            <label for="f_subject">Subject</label>
            <input type="text" id="f_subject" name="subject" required maxlength="255"
                   value="<?= h($subject) ?>">
        </div>

        <div class="field">
            <label for="f_reply_to">Reply-To <span class="muted small">(optional)</span></label>
            <input type="text" id="f_reply_to" name="reply_to" value="<?= h($replyTo) ?>"
                   placeholder="<?= h(magdyn_setting('smtp.reply_to', '')) ?>">
        </div>

        <div class="field">
            <label>Body</label>
            <div id="quill-editor" style="min-height: 260px; border: 1px solid var(--border); border-radius: 4px;"><?= $bodyDefault ?></div>
            <input type="hidden" name="body_html" id="body_html" value="">
        </div>

        <?php if ($attachAuto): ?>
            <div class="field">
                <label>Auto-attachments</label>
                <div style="display: flex; flex-direction: column; gap: 6px;
                            padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
                    <?php foreach ($attachAuto as $a):
                        // Two kinds of auto-attachment:
                        //   - 'static'   : file already exists on disk at hidden_path.
                        //                  Toggle posts the path + HMAC tok; send-side
                        //                  verifies the tok and attaches the file.
                        //   - 'generated': no file exists yet; the toggle posts only its
                        //                  state. Send-side calls the generator callable
                        //                  registered in $ctx to materialise the file.
                        //                  No signing needed since the server controls
                        //                  the path entirely.
                        $isGenerated = !empty($a['kind']) && $a['kind'] === 'generated';
                        $tok = !$isGenerated
                             ? _ec_attach_sign($ctx['related_type'], $ctx['related_id'], $a['filename'], $a['hidden_path'])
                             : '';
                    ?>
                        <label class="inline" style="gap: 8px;">
                            <input type="checkbox" name="<?= h($a['toggle_name']) ?>" value="1"
                                   <?= !empty($a['default_on']) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= h($a['label']) ?></strong>
                                <?php if (!empty($a['description'])): ?>
                                    <span class="muted small">— <?= h($a['description']) ?></span>
                                <?php endif; ?>
                                <code class="muted small" style="margin-left:6px;"><?= h($a['filename']) ?></code>
                                <?php if ($isGenerated): ?>
                                    <span class="muted small" style="margin-left:6px;">(generated on send)</span>
                                <?php endif; ?>
                            </span>
                            <?php if (!$isGenerated): ?>
                                <input type="hidden" name="<?= h($a['toggle_name']) ?>__name" value="<?= h($a['filename']) ?>">
                                <input type="hidden" name="<?= h($a['toggle_name']) ?>__path" value="<?= h($a['hidden_path']) ?>">
                                <input type="hidden" name="<?= h($a['toggle_name']) ?>__mime" value="<?= h($a['mime'] ?? '') ?>">
                                <input type="hidden" name="<?= h($a['toggle_name']) ?>__tok"  value="<?= h($tok) ?>">
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="f_attachments">Manual attachments <span class="muted small">(10 MB per file)</span></label>
            <!-- Drag-and-drop zone. The native input is below and tied
                 to it: dropped files are written into the input's
                 FileList via DataTransfer so the regular form submit
                 picks them up. Falls back to the file input alone on
                 browsers that don't support DataTransfer (rare). -->
            <div id="att-dropzone" tabindex="0"
                 style="border: 2px dashed var(--border); border-radius: 6px;
                        padding: 18px; text-align: center; color: var(--text-muted);
                        cursor: pointer; transition: background 0.15s, border-color 0.15s;">
                <div style="font-size: 22px;">📎</div>
                <div><strong>Drop files here</strong> or click to browse</div>
                <div class="muted small" style="margin-top: 4px;">
                    Blocked: .exe, .bat, .cmd, .sh, .js, .vbs, .msi, .scr, .php
                </div>
            </div>
            <input type="file" id="f_attachments" name="attachments[]" multiple
                   style="display: none;">
            <ul id="att-chips" style="list-style: none; padding: 0; margin: 8px 0 0;
                                       display: flex; flex-wrap: wrap; gap: 6px;"></ul>
        </div>

        <div class="form-actions" style="margin-top: 16px; display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" <?= $smtpReady ? '' : 'disabled' ?>>📤 Send email</button>
            <a class="btn btn-ghost" href="<?= h($ctx['back_url']) ?>">Cancel</a>
        </div>
    </form>

    <link rel="stylesheet" href="<?= h(url('/assets/css/quill.snow.css')) ?>"
          onerror="this.outerHTML='<style>#quill-editor{padding:8px;}</style>';">
    <script src="<?= h(url('/assets/js/vendor/quill.min.js')) ?>"></script>
    <script>
    (function () {
        // ---- Quill rich-text editor ----
        var hidden = document.getElementById('body_html');
        if (typeof Quill !== 'undefined') {
            var quill = new Quill('#quill-editor', {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ header: [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered'}, { list: 'bullet' }],
                        ['link'],
                        ['clean']
                    ]
                }
            });
            var sync = function () { hidden.value = quill.root.innerHTML; };
            quill.on('text-change', sync);
            sync();
            document.getElementById('email-compose-form').addEventListener('submit', sync);
        } else {
            // Fallback to plain textarea if Quill failed to load.
            var ed = document.getElementById('quill-editor');
            if (ed) {
                var ta = document.createElement('textarea');
                ta.name = 'body_html';
                ta.rows = 14;
                ta.style.width = '100%';
                ta.value = ed.innerHTML;
                ed.parentNode.replaceChild(ta, ed);
                if (hidden) hidden.remove();
            }
        }

        // ---- Drag-drop attachments ----
        var zone   = document.getElementById('att-dropzone');
        var input  = document.getElementById('f_attachments');
        var chips  = document.getElementById('att-chips');
        if (!zone || !input || !chips) return;

        function fmtBytes(n) {
            if (n < 1024) return n + ' B';
            if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
            return (n / 1024 / 1024).toFixed(2) + ' MB';
        }
        function renderChips() {
            chips.innerHTML = '';
            var files = input.files || [];
            for (var i = 0; i < files.length; i++) {
                (function (idx, f) {
                    var li = document.createElement('li');
                    li.style.cssText = 'display:flex; align-items:center; gap:6px; padding:4px 10px;'
                                     + 'background:#eef1f6; border-radius:14px; font-size:12.5px;';
                    li.innerHTML = '<span>📄</span>'
                                 + '<span style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="'
                                 + (f.name || '').replace(/"/g, '&quot;') + '">'
                                 + (f.name || '(unnamed)') + '</span>'
                                 + '<span class="muted small">(' + fmtBytes(f.size || 0) + ')</span>';
                    var x = document.createElement('button');
                    x.type = 'button';
                    x.textContent = '×';
                    x.title = 'Remove';
                    x.style.cssText = 'background:none; border:none; cursor:pointer; font-size:16px; color:#666;';
                    x.addEventListener('click', function () { removeFile(idx); });
                    li.appendChild(x);
                    chips.appendChild(li);
                })(i, files[i]);
            }
        }
        function removeFile(idx) {
            // Build a DataTransfer with everything except idx, swap onto input.
            if (typeof DataTransfer === 'undefined') return;
            var dt = new DataTransfer();
            var files = input.files || [];
            for (var i = 0; i < files.length; i++) {
                if (i === idx) continue;
                dt.items.add(files[i]);
            }
            input.files = dt.files;
            renderChips();
        }
        function addFiles(newFiles) {
            if (typeof DataTransfer === 'undefined') {
                // Fallback: assign the dropped list directly. Loses
                // previously-selected files, but works.
                input.files = newFiles;
                renderChips();
                return;
            }
            var dt = new DataTransfer();
            var existing = input.files || [];
            for (var i = 0; i < existing.length; i++) dt.items.add(existing[i]);
            for (var j = 0; j < newFiles.length; j++) dt.items.add(newFiles[j]);
            input.files = dt.files;
            renderChips();
        }
        zone.addEventListener('click', function () { input.click(); });
        zone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        input.addEventListener('change', function () { renderChips(); });
        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                zone.style.background = '#eef1f6';
                zone.style.borderColor = 'var(--primary, #2d3a8c)';
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                zone.style.background = '';
                zone.style.borderColor = '';
            });
        });
        zone.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files;
            if (f && f.length) addFiles(f);
        });
        // Prevent the browser from navigating away when files are
        // dropped outside the zone.
        ['dragenter', 'dragover', 'drop'].forEach(function (ev) {
            window.addEventListener(ev, function (e) { e.preventDefault(); }, false);
        });
    })();
    </script>

    <?php require __DIR__ . '/footer.php';
}


/**
 * Handle the email_send POST. Caller has already done permission
 * checks and CSRF. Returns ['ok' => bool, 'error' => string|null].
 */
function handle_email_send_post(array $ctx, $uid)
{
    $relType = $ctx['related_type'];
    $relId   = (int)$ctx['related_id'];

    $contactIds = (array)input('to_contact_ids', []);
    $toExtra    = trim((string)input('to_extra', ''));
    $cc         = trim((string)input('cc', ''));
    $bcc        = trim((string)input('bcc', ''));
    $subject    = trim((string)input('subject', ''));
    $bodyHtml   = (string)input('body_html', '');
    $replyToOpt = trim((string)input('reply_to', ''));

    // Resolve contact ids to emails. The set of valid ids is
    // restricted to $ctx['contacts'] so an attacker can't smuggle
    // arbitrary vendor_contact ids in.
    $allowed = [];
    foreach ((array)($ctx['contacts'] ?? []) as $c) {
        $allowed[(int)$c['id']] = $c['email'];
    }
    $toAddrs = [];
    foreach ($contactIds as $cid) {
        $cid = (int)$cid;
        if (isset($allowed[$cid]) && $allowed[$cid]) $toAddrs[] = $allowed[$cid];
    }
    if ($toExtra !== '') {
        foreach (preg_split('/[\s,;]+/', $toExtra) as $a) {
            $a = trim($a);
            if ($a !== '') $toAddrs[] = $a;
        }
    }
    $toAddrs = array_values(array_unique($toAddrs));

    if (!$toAddrs) {
        return ['ok' => false, 'error' => 'Pick at least one contact or enter a To: address.'];
    }
    if ($subject === '' || $bodyHtml === '') {
        return ['ok' => false, 'error' => 'Subject and body are required.'];
    }

    // Auto-attachments — only included when the toggle is on. Branch
    // by kind:
    //   - static    : hidden_path + HMAC tok verified, file read from disk
    //   - generated : generator() called to materialise the file; cleaned
    //                 up after send. No signing needed since the server
    //                 controls both sides.
    $attachments = [];
    $generatedTempPaths = [];
    foreach ((array)($ctx['attach_auto'] ?? []) as $a) {
        $toggle = (string)input($a['toggle_name'], '');
        if ($toggle !== '1') continue;

        $isGenerated = !empty($a['kind']) && $a['kind'] === 'generated';
        if ($isGenerated) {
            if (empty($a['generator']) || !is_callable($a['generator'])) {
                return ['ok' => false, 'error' => 'Auto-attachment generator missing: ' . ($a['label'] ?? '?')];
            }
            try {
                $gen = call_user_func($a['generator']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Failed to generate attachment: ' . $e->getMessage()];
            }
            if (!$gen || empty($gen['path']) || !is_file($gen['path'])) {
                return ['ok' => false, 'error' => 'Generator did not produce a file: ' . ($a['label'] ?? '?')];
            }
            $attachments[] = [
                'path' => $gen['path'],
                'name' => $gen['name'] ?? basename($gen['path']),
                'mime' => $gen['mime'] ?? null,
            ];
            $generatedTempPaths[] = $gen['path'];
        } else {
            $name = (string)input($a['toggle_name'] . '__name', '');
            $path = (string)input($a['toggle_name'] . '__path', '');
            $mime = (string)input($a['toggle_name'] . '__mime', '');
            $tok  = (string)input($a['toggle_name'] . '__tok',  '');
            $expect = _ec_attach_sign($relType, $relId, $name, $path);
            if (!hash_equals($expect, $tok)) {
                return ['ok' => false, 'error' => 'Auto-attachment signature mismatch — refresh and retry.'];
            }
            if (!is_file($path)) {
                return ['ok' => false, 'error' => 'Auto-attachment file is missing on disk: ' . $name];
            }
            $attachments[] = ['path' => $path, 'name' => $name, 'mime' => $mime ?: null];
        }
    }

    // Manual uploads
    $tempPaths = [];
    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $maxBytes = 10 * 1024 * 1024;
        $blockedExt = ['exe', 'bat', 'cmd', 'sh', 'js', 'vbs', 'msi', 'scr', 'php'];
        $tmpDir = sys_get_temp_dir() . '/magdyn_email_' . bin2hex(random_bytes(6));
        @mkdir($tmpDir, 0700, true);
        $n = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $n; $i++) {
            if ((int)$_FILES['attachments']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            if ((int)$_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'error' => 'Upload failed: ' . $_FILES['attachments']['name'][$i]];
            }
            if ((int)$_FILES['attachments']['size'][$i] > $maxBytes) {
                return ['ok' => false, 'error' => 'Too large (10 MB max): ' . $_FILES['attachments']['name'][$i]];
            }
            $origName = basename($_FILES['attachments']['name'][$i]);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, $blockedExt, true)) {
                return ['ok' => false, 'error' => "Blocked type .$ext: $origName"];
            }
            $dest = $tmpDir . '/' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
            if (!move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dest)) {
                return ['ok' => false, 'error' => 'Could not save uploaded file.'];
            }
            $attachments[] = ['path' => $dest, 'name' => $origName, 'mime' => @mime_content_type($dest)];
            $tempPaths[] = $dest;
        }
    }

    $res = smtp_send([
        'related_type' => $relType,
        'related_id'   => $relId,
        'to'           => $toAddrs,
        'cc'           => $cc,
        'bcc'          => $bcc,
        'subject'      => $subject,
        'body_html'    => $bodyHtml,
        'reply_to'     => $replyToOpt ?: null,
        'attachments'  => $attachments,
        'actor_id'     => $uid,
    ]);

    // Cleanup temp files. Both manually-uploaded files and any
    // generator-produced attachments (PDFs etc.) live in scratch dirs
    // and should be wiped after send. Static auto-attachments (vendor
    // cover sheets, doc revision files on disk) stay where they live.
    foreach ($tempPaths as $p) @unlink($p);
    if (!empty($tempPaths)) @rmdir(dirname($tempPaths[0]));
    foreach ($generatedTempPaths as $p) {
        $d = dirname($p);
        @unlink($p);
        // Generator put its file in a unique tempDir; remove it once
        // empty so we don't leak hundreds of dirs on a busy day.
        if (is_dir($d)) {
            // Remove font cache files dompdf wrote into the dir.
            foreach ((array)@glob($d . '/*') as $leftover) @unlink($leftover);
            @rmdir($d);
        }
    }

    if ($res['ok']) {
        $res['recipients'] = count($toAddrs);
    }
    return $res;
}
