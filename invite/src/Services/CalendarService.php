<?php

declare(strict_types=1);

namespace App\Services;

/** iCalendar (.ics) generation for "Add to calendar". */
final class CalendarService
{
    public function __construct(private LinkService $links)
    {
    }

    public function ics(array $event, ?array $subEvent = null, ?string $url = null): string
    {
        $src = $subEvent ?? $event;
        $start = strtotime($src['start_at'] . ' UTC');
        $end = $src['end_at'] ? strtotime($src['end_at'] . ' UTC') : $start + 7200;
        $title = $subEvent ? $event['title'] . ' - ' . $subEvent['name'] : $event['title'];
        $location = trim(($src['venue_name'] ?? '') . ($src['venue_address'] ? ', ' . $src['venue_address'] : ''));
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Invite.com//RSVP//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . ($subEvent['uuid'] ?? $event['uuid']) . '@invite.com',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . gmdate('Ymd\THis\Z', $start),
            'DTEND:' . gmdate('Ymd\THis\Z', $end),
            'SUMMARY:' . $this->esc($title),
        ];
        if ($location !== '') {
            $lines[] = 'LOCATION:' . $this->esc($location);
        }
        $lines[] = 'DESCRIPTION:' . $this->esc(trim(($event['description'] ?? '') . "\n\n" . ($url ?? $this->links->event($event))));
        $lines[] = 'URL:' . ($url ?? $this->links->event($event));
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
    }

    /** RFC 5545: lines are at most 75 octets; continuation lines start with a space. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $chunk = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($chunk) + strlen($ch) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
            }
            $chunk .= $ch;
        }
        return $out . "\r\n " . $chunk;
    }
}
