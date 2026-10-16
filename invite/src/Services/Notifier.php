<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\View;

/** Renders email templates and puts them on the queue. Nothing is ever sent inside a web request. */
final class Notifier
{
    public function __construct(private QueueService $queue)
    {
    }

    /**
     * @param array<string,mixed> $data   template variables
     * @param array<string,mixed> $meta   event_id, event_guest_id, message_id, list_unsubscribe, reply_to
     */
    public function email(string $to, string $toName, string $subject, string $template, array $data, array $meta = [], int $delay = 0, ?string $at = null): int
    {
        $html = View::render('emails/' . $template, $data + ['subject' => $subject], 'emails/layout');
        $payload = [
            'to' => $to,
            'to_name' => $toName,
            'subject' => $subject,
            'html' => $html,
            'text' => self::htmlToText($html),
        ] + $meta;
        return $at !== null ? $this->queue->pushAt('email.send', $payload, $at) : $this->queue->push('email.send', $payload, $delay);
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(style|head|title)\b.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</p>|</h\d>|</tr>|</div>|</li>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }
}
