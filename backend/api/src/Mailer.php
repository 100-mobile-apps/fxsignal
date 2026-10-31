<?php
declare(strict_types=1);

/**
 * Email delivery for cPanel.
 *
 * transport = 'smtp' (recommended): authenticates with a cPanel email account,
 *             e.g. host mail.yourdomain.com, port 465 (ssl) or 587 (tls).
 * transport = 'mail': PHP mail() — works on most cPanel hosts but lands in spam more often.
 * transport = 'log':  writes emails to the PHP error log (for testing).
 *
 * Alerts and reports go through the email_queue table and are sent by the
 * cron job in batches (max_per_run), so hosting hourly limits are respected.
 * Verification and reset codes are sent immediately.
 */
final class Mailer
{
    private static $smtp = null;

    private static function cfg(): array
    {
        $c = config('mail', []);
        return $c + [
            'transport' => 'mail',
            'host' => 'localhost',
            'port' => 465,
            'secure' => 'ssl',
            'user' => '',
            'pass' => '',
            'from' => config('mail_from', 'no-reply@localhost'),
            'from_name' => config('app_name', 'FX Signals'),
            'max_per_run' => 40,
        ];
    }

    /** Add an email to the queue (sent by the cron job). */
    public static function queue(?int $userId, string $to, string $subject, string $html, string $text, ?string $unsubscribeUrl = null): void
    {
        Db::exec(
            'INSERT INTO email_queue (user_id, to_email, subject, html, text_body, unsubscribe_url, status, attempts, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [$userId, $to, $subject, $html, $text, $unsubscribeUrl, 'pending', now()]
        );
    }

    /** Send immediately (codes the user is waiting for). */
    public static function sendNow(string $to, string $subject, string $html, string $text): bool
    {
        try {
            self::deliver($to, $subject, $html, $text, null);
            return true;
        } catch (Throwable $e) {
            error_log('[fx-mail] ' . $e->getMessage());
            return false;
        } finally {
            self::closeSmtp();
        }
    }

    /** Send a batch from the queue. Returns [sent, failed]. */
    public static function processQueue(?int $max = null): array
    {
        $max = $max ?? (int) self::cfg()['max_per_run'];
        $rows = Db::all("SELECT * FROM email_queue WHERE status = 'pending' ORDER BY id LIMIT " . max(1, $max));
        $sent = 0;
        $failed = 0;
        foreach ($rows as $r) {
            try {
                self::deliver($r['to_email'], $r['subject'], $r['html'], $r['text_body'], $r['unsubscribe_url']);
                Db::exec("UPDATE email_queue SET status = 'sent', sent_at = ?, attempts = attempts + 1 WHERE id = ?", [now(), $r['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $r['attempts'] + 1;
                Db::exec('UPDATE email_queue SET status = ?, attempts = ?, last_error = ? WHERE id = ?',
                    [$attempts >= 3 ? 'failed' : 'pending', $attempts, substr($e->getMessage(), 0, 500), $r['id']]);
                $failed++;
                self::closeSmtp(); // reconnect on next attempt
            }
        }
        self::closeSmtp();
        if (random_int(1, 20) === 1) {
            Db::exec("DELETE FROM email_queue WHERE status = 'sent' AND sent_at < ?", [now(time() - 30 * 86400)]);
        }
        return [$sent, $failed];
    }

    private static function deliver(string $to, string $subject, string $html, string $text, ?string $unsub): void
    {
        $c = self::cfg();
        $boundary = 'b' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($c['from'], '@') ?: '@localhost', 1);
        $headers = [
            'From' => self::encodeName($c['from_name']) . " <{$c['from']}>",
            'Reply-To' => $c['from'],
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
        ];
        if ($unsub) {
            $headers['List-Unsubscribe'] = "<$unsub>";
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        $encSubject = self::encodeHeader($subject);

        switch ($c['transport']) {
            case 'log':
                $line = "[fx-mail:log] to=$to subject=$subject\n$text";
                error_log($line);
                if (!empty($c['log_file'])) file_put_contents($c['log_file'], $line . "\n-----\n", FILE_APPEND);
                return;
            case 'mail':
                $h = $headers;
                $hs = '';
                foreach ($h as $k => $v) $hs .= "$k: $v\r\n";
                if (!mail($to, $encSubject, $body, rtrim($hs), '-f' . $c['from'])) {
                    throw new RuntimeException('mail() returned false');
                }
                return;
            default:
                self::smtpSend($c, $to, $encSubject, $headers, $body);
        }
    }

    // ---------- minimal SMTP client (AUTH LOGIN, SSL or STARTTLS) ----------
    private static function smtpSend(array $c, string $to, string $subject, array $headers, string $body): void
    {
        $s = self::smtpConnect($c);
        self::cmd($s, 'MAIL FROM:<' . $c['from'] . '>', [250]);
        self::cmd($s, "RCPT TO:<$to>", [250, 251]);
        self::cmd($s, 'DATA', [354]);
        $msg = "To: $to\r\nSubject: $subject\r\n";
        foreach ($headers as $k => $v) $msg .= "$k: $v\r\n";
        $msg .= "\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msg));
        fwrite($s, $msg . "\r\n.\r\n");
        self::expect($s, [250]);
        self::cmd($s, 'RSET', [250]);
    }

    private static function smtpConnect(array $c)
    {
        if (self::$smtp) return self::$smtp;
        $secure = strtolower((string) $c['secure']);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int) $c['port'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => empty($c['insecure']), 'verify_peer_name' => empty($c['insecure'])]]);
        $s = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        stream_set_timeout($s, 20);
        self::expect($s, [220]);
        $host = gethostname() ?: 'localhost';
        self::cmd($s, "EHLO $host", [250]);
        if ($secure === 'tls') {
            self::cmd($s, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            self::cmd($s, "EHLO $host", [250]);
        }
        if ($c['user'] !== '') {
            self::cmd($s, 'AUTH LOGIN', [334]);
            self::cmd($s, base64_encode($c['user']), [334]);
            self::cmd($s, base64_encode($c['pass']), [235]);
        }
        return self::$smtp = $s;
    }

    private static function cmd($s, string $line, array $ok): string
    {
        fwrite($s, $line . "\r\n");
        return self::expect($s, $ok);
    }

    private static function expect($s, array $ok): string
    {
        $resp = '';
        while (($line = fgets($s, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) throw new RuntimeException('SMTP error: ' . trim($resp));
        return $resp;
    }

    private static function closeSmtp(): void
    {
        if (self::$smtp) {
            @fwrite(self::$smtp, "QUIT\r\n");
            @fclose(self::$smtp);
            self::$smtp = null;
        }
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function encodeName(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? self::encodeHeader($s) : '"' . addslashes($s) . '"';
    }
}
