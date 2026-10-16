<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use RuntimeException;
use Throwable;

/**
 * Templated email through an outbox: messages are queued in the request and delivered
 * by the scheduler (SMTP) so a slow mail server never slows down the app.
 */
final class MailService
{
    public static function queue(string $to, string $template, array $vars): int
    {
        $tpl = Database::first('SELECT * FROM email_templates WHERE `key` = ?', [$template]);
        if ($tpl === null) {
            throw new RuntimeException("Unknown email template $template");
        }
        $render = static fn (string $text) => preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static fn ($m) => (string) ($vars[$m[1]] ?? ''), $text);
        $subject = str_replace(["\r", "\n"], ' ', $render($tpl['subject']));
        return Database::insert('mail_outbox', [
            'to_email' => $to,
            'template' => $template,
            'subject' => mb_substr($subject, 0, 255),
            'body' => $render($tpl['body']),
            'created_at' => db_time(),
        ]);
    }

    /** Delivers pending mail. Returns [sent, failed]. */
    public static function deliver(int $limit = 50): array
    {
        $sent = $failed = 0;
        foreach (Database::select("SELECT * FROM mail_outbox WHERE status = 'pending' AND attempts < 5 ORDER BY id LIMIT $limit") as $mail) {
            try {
                self::send($mail['to_email'], $mail['subject'], $mail['body']);
                Database::update('mail_outbox', ['status' => 'sent', 'sent_at' => db_time(), 'attempts' => $mail['attempts'] + 1, 'error' => null], 'id = ?', [$mail['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $mail['attempts'] + 1;
                Database::update('mail_outbox', [
                    'status' => $attempts >= 5 ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => substr($e->getMessage(), 0, 1000),
                ], 'id = ?', [$mail['id']]);
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    private static function send(string $to, string $subject, string $body): void
    {
        $transport = (string) config('app.mail.transport');
        if ($transport === 'log') {
            file_put_contents(base_path('storage/logs/mail.log'), sprintf("[%s] To: %s\nSubject: %s\n\n%s\n\n", gmdate('c'), $to, $subject, $body), FILE_APPEND | LOCK_EX);
            return;
        }
        self::smtp($to, $subject, $body);
    }

    /** Minimal SMTP client (plain or STARTTLS, optional AUTH LOGIN). */
    private static function smtp(string $to, string $subject, string $body): void
    {
        $host = (string) config('app.mail.host');
        $port = (int) config('app.mail.port');
        $from = (string) config('app.mail.from');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient');
        }
        $socket = @stream_socket_client("tcp://$host:$port", $errno, $error, 10);
        if ($socket === false) {
            throw new RuntimeException("SMTP connect failed: $error");
        }
        stream_set_timeout($socket, 15);
        $read = static function () use ($socket): string {
            $response = '';
            while (($line = fgets($socket, 515)) !== false) {
                $response .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };
        $cmd = static function (string $line, array $expect) use ($socket, $read): string {
            fwrite($socket, $line . "\r\n");
            $response = $read();
            if (!in_array((int) substr($response, 0, 3), $expect, true)) {
                throw new RuntimeException('SMTP error after ' . strtok($line, ' ') . ': ' . trim($response));
            }
            return $response;
        };

        $read();
        $ehlo = $cmd('EHLO ' . (parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'), [250]);
        if (str_contains($ehlo, 'STARTTLS') && config('app.mail.encryption') === 'tls') {
            $cmd('STARTTLS', [220]);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO localhost', [250]);
        }
        $user = (string) config('app.mail.username');
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode((string) config('app.mail.password')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $headers = [
            'From: SSShare <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(config('app.url'), PHP_URL_HOST) ?: 'ssshare') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        $cmd($data . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($socket);
    }
}
