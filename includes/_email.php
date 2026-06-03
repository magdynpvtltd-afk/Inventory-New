<?php
/**
 * MagDyn — Email helper (Phase D2)
 *
 * Single entry point: smtp_send($opts).
 *
 * Reads SMTP credentials from magdyn_settings (smtp.host, smtp.port,
 * smtp.user, smtp.pass, smtp.secure, smtp.from_email, smtp.from_name,
 * smtp.reply_to, smtp.enabled). Operator edits these via Settings → SMTP.
 *
 * Backend: PHPMailer if vendored at includes/vendor/phpmailer/src/.
 * Without PHPMailer, we refuse to send and surface a clear error —
 * Hostinger requires authenticated SMTP for reliable delivery, so
 * silently falling back to mail() would mostly land in spam folders.
 *
 * Every send (success OR failure) writes a row to sent_emails for
 * audit + retry traceability.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_purchase_orders.php';   // for magdyn_setting()

/**
 * Send an email. Returns ['ok' => bool, 'error' => string|null,
 * 'log_id' => int].
 *
 * $opts = [
 *   'related_type' => 'po',         // optional, for audit linking
 *   'related_id'   => 42,           // optional
 *   'to'           => ['a@b.com', 'c@d.com'] | 'a@b.com',
 *   'cc'           => ['x@y.com'] | 'x@y.com' | '',
 *   'bcc'          => '',           // optional
 *   'subject'      => 'PO PO-00042',
 *   'body_html'    => '<p>...</p>',
 *   'body_text'    => 'plain text',  // optional; auto-derived if blank
 *   'attachments'  => [              // optional
 *      ['path' => '/tmp/x.pdf', 'name' => 'PO-00042.pdf', 'mime' => 'application/pdf'],
 *      ...
 *   ],
 *   'reply_to'     => 'replies@magdyn.com',   // optional; falls back to settings
 *   'actor_id'     => 12,            // who's sending; goes into sent_emails.sent_by
 * ]
 */
function smtp_send(array $opts)
{
    $result = ['ok' => false, 'error' => null, 'log_id' => 0];

    // Normalise To/CC/BCC into arrays of email strings.
    $norm = function ($v) {
        if (is_array($v)) return array_values(array_filter(array_map('trim', $v)));
        if (!$v) return [];
        // Split on comma, semicolon, or newline so the operator can
        // paste a CC list freely.
        $parts = preg_split('/[\s,;]+/', (string)$v);
        return array_values(array_filter(array_map('trim', $parts)));
    };
    $to  = $norm($opts['to']  ?? []);
    $cc  = $norm($opts['cc']  ?? []);
    $bcc = $norm($opts['bcc'] ?? []);

    if (!$to) {
        $result['error'] = 'At least one recipient required.';
        return $result;
    }
    foreach (array_merge($to, $cc, $bcc) as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $result['error'] = "Invalid recipient email: $addr";
            return $result;
        }
    }

    $subject = trim((string)($opts['subject'] ?? ''));
    if ($subject === '') {
        $result['error'] = 'Subject is required.';
        return $result;
    }

    $bodyHtml = (string)($opts['body_html'] ?? '');
    $bodyText = (string)($opts['body_text'] ?? '');
    if ($bodyText === '' && $bodyHtml !== '') {
        // Derive plain text from HTML by stripping tags. Crude but
        // good enough for the auto-fallback alt-text on multipart.
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($bodyHtml)));
    }

    $attachments = (array)($opts['attachments'] ?? []);
    foreach ($attachments as $att) {
        if (empty($att['path']) || !is_file($att['path'])) {
            $result['error'] = 'Attachment missing or unreadable: ' . ($att['name'] ?? '?');
            return $result;
        }
    }

    // Settings.
    $enabled = magdyn_setting('smtp.enabled', '0');
    if ((string)$enabled !== '1') {
        $result['error'] = 'SMTP is disabled in Settings → SMTP. Enable it and save SMTP credentials before sending.';
        return $result;
    }
    $host      = trim((string)magdyn_setting('smtp.host', ''));
    $port      = (int)magdyn_setting('smtp.port', 587);
    $user      = trim((string)magdyn_setting('smtp.user', ''));
    $pass      = (string)magdyn_setting('smtp.pass', '');
    $secure    = strtolower((string)magdyn_setting('smtp.secure', 'tls'));
    $fromEmail = trim((string)magdyn_setting('smtp.from_email', ''));
    $fromName  = trim((string)magdyn_setting('smtp.from_name', 'Magneto Dynamics'));
    $replyTo   = trim((string)($opts['reply_to'] ?? magdyn_setting('smtp.reply_to', '')));
    if ($host === '' || $user === '' || $pass === '' || $fromEmail === '') {
        $result['error'] = 'SMTP credentials incomplete. Set host, user, password, and from-email in Settings → SMTP.';
        return $result;
    }

    // Queue an audit row first. We update it after the send attempt
    // so a crash mid-send still leaves a "queued" record we can
    // investigate. Body and attachment list captured as-is for replay.
    $attachMeta = array_map(function ($a) {
        return ['name' => $a['name'] ?? basename($a['path']), 'mime' => $a['mime'] ?? null,
                'size' => @filesize($a['path'])];
    }, $attachments);
    db_exec(
        "INSERT INTO sent_emails
            (related_type, related_id, from_addr, from_name,
             to_addrs, cc_addrs, bcc_addrs, subject,
             body_html, body_text, attachments,
             status, sent_by)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?)",
        [
            isset($opts['related_type']) ? (string)$opts['related_type'] : null,
            isset($opts['related_id'])   ? (int)$opts['related_id']     : null,
            $fromEmail, $fromName,
            implode(', ', $to),
            $cc  ? implode(', ', $cc)  : null,
            $bcc ? implode(', ', $bcc) : null,
            $subject,
            $bodyHtml ?: null,
            $bodyText ?: null,
            $attachMeta ? json_encode($attachMeta) : null,
            !empty($opts['actor_id']) ? (int)$opts['actor_id'] : null,
        ]
    );
    $logId = (int)db()->lastInsertId();
    $result['log_id'] = $logId;

    // Locate PHPMailer. The user vendors it once at
    // includes/vendor/phpmailer/src/. If absent we refuse to send —
    // see the file-level comment.
    $pmRoot = __DIR__ . '/vendor/phpmailer/src';
    $pmFiles = [
        $pmRoot . '/PHPMailer.php',
        $pmRoot . '/SMTP.php',
        $pmRoot . '/Exception.php',
    ];
    foreach ($pmFiles as $f) {
        if (!is_file($f)) {
            $msg = 'PHPMailer not installed at includes/vendor/phpmailer/. Email subsystem cannot send. See Phase D2 deploy notes.';
            db_exec("UPDATE sent_emails SET status='failed', error_message=? WHERE id=?", [$msg, $logId]);
            $result['error'] = $msg;
            return $result;
        }
    }
    require_once $pmFiles[2];   // Exception
    require_once $pmFiles[1];   // SMTP
    require_once $pmFiles[0];   // PHPMailer

    $mailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
    $excClass    = '\\PHPMailer\\PHPMailer\\Exception';

    try {
        $mail = new $mailerClass(true);   // throw exceptions
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        if ($secure === 'tls')      $mail->SMTPSecure = 'tls';
        elseif ($secure === 'ssl')  $mail->SMTPSecure = 'ssl';
        else                        $mail->SMTPSecure = '';
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 25;

        $mail->setFrom($fromEmail, $fromName);
        if ($replyTo) $mail->addReplyTo($replyTo);
        foreach ($to  as $a) $mail->addAddress($a);
        foreach ($cc  as $a) $mail->addCC($a);
        foreach ($bcc as $a) $mail->addBCC($a);

        $mail->Subject = $subject;
        if ($bodyHtml !== '') {
            $mail->isHTML(true);
            $mail->Body    = $bodyHtml;
            $mail->AltBody = $bodyText;
        } else {
            $mail->isHTML(false);
            $mail->Body    = $bodyText;
        }
        foreach ($attachments as $att) {
            $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
        }

        $mail->send();

        db_exec(
            "UPDATE sent_emails SET status='sent', sent_at=NOW(), error_message=NULL WHERE id=?",
            [$logId]
        );
        $result['ok'] = true;
        return $result;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        db_exec("UPDATE sent_emails SET status='failed', error_message=? WHERE id=?", [$msg, $logId]);
        $result['error'] = $msg;
        return $result;
    }
}

/**
 * List recent emails sent against a given related object. Used by
 * the PO view to show a "Recent emails" mini-history.
 */
function sent_emails_for($relatedType, $relatedId, $limit = 5)
{
    return db_all(
        "SELECT se.*, u.full_name AS sender_name
           FROM sent_emails se
      LEFT JOIN users u ON u.id = se.sent_by
          WHERE se.related_type = ? AND se.related_id = ?
          ORDER BY se.queued_at DESC
          LIMIT " . (int)$limit,
        [(string)$relatedType, (int)$relatedId]
    );
}
