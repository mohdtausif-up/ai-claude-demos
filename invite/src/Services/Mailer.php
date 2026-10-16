<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Minimal SMTP client (plain or STARTTLS, optional AUTH LOGIN). Only ever called from the queue worker. */
class Mailer
{
    /**
     * @param array{to:string,to_name?:string,subject:string,text:string,html:string,reply_to?:string,list_unsubscribe?:string} $m
     * @return string Message-ID
     */
    public function send(array $m): string
    {
        $host = (string) env('MAIL_HOST', 'mailpit');
        $port = (int) env('MAIL_PORT', 1025);
        $from = (string) env('MAIL_FROM_ADDRESS', 'hello@invite.test');
        $fromName = (string) env('MAIL_FROM_NAME', 'Invite.com');

        $to = $this->clean($m['to']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient address');
        }
        $domain = substr(strrchr($from, '@') ?: '@invite.test', 1);
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $boundary = 'b' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $this->address($from, $fromName),
            'To: ' . $this->address($to, $m['to_name'] ?? ''),
            'Subject: ' . $this->encodeHeader($m['subject']),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
        ];
        if (!empty($m['reply_to']) && filter_var($m['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $this->clean($m['reply_to']);
        }
        if (!empty($m['list_unsubscribe'])) {
            $headers[] = 'List-Unsubscribe: <' . $this->clean($m['list_unsubscribe']) . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($m['text']), 76, "\r\n")
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($m['html']), 76, "\r\n")
            . "--$boundary--\r\n";

        $this->deliver($host, $port, $from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
        return $messageId;
    }

    protected function deliver(string $host, int $port, string $from, string $to, string $data): void
    {
        $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 10);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr");
        }
        stream_set_timeout($fp, 15);
        try {
            $this->expect($fp, 220);
            $this->cmd($fp, 'EHLO invite.local', 250);
            $user = (string) env('MAIL_USERNAME', '');
            if ($user !== '') {
                if (env('MAIL_ENCRYPTION') === 'tls') {
                    $this->cmd($fp, 'STARTTLS', 220);
                    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                        throw new RuntimeException('STARTTLS failed');
                    }
                    $this->cmd($fp, 'EHLO invite.local', 250);
                }
                $this->cmd($fp, 'AUTH LOGIN', 334);
                $this->cmd($fp, base64_encode($user), 334);
                $this->cmd($fp, base64_encode((string) env('MAIL_PASSWORD', '')), 235);
            }
            $this->cmd($fp, "MAIL FROM:<$from>", 250);
            $this->cmd($fp, "RCPT TO:<$to>", 250);
            $this->cmd($fp, 'DATA', 354);
            // Dot-stuff and normalise line endings.
            $data = preg_replace('/(?<!\r)\n/', "\r\n", $data) ?? $data;
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            fwrite($fp, $data . "\r\n.\r\n");
            $this->expect($fp, 250);
            $this->cmd($fp, 'QUIT', 221);
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private function cmd($fp, string $line, int $expect): void
    {
        fwrite($fp, $line . "\r\n");
        $this->expect($fp, $expect);
    }

    /** @param resource $fp */
    private function expect($fp, int $code): void
    {
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ((int) substr($resp, 0, 3) !== $code) {
            throw new RuntimeException('SMTP error: ' . trim($resp));
        }
    }

    private function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", '<', '>'], '', $s));
    }

    private function encodeHeader(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private function address(string $email, string $name): string
    {
        $email = $this->clean($email);
        $name = $this->clean($name);
        return $name === '' ? "<$email>" : $this->encodeHeader(str_replace('"', '', $name)) . " <$email>";
    }
}
