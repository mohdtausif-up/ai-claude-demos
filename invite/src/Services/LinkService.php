<?php

declare(strict_types=1);

namespace App\Services;

/** Builds every guest-facing URL. Personal links are derived on demand, never stored. */
final class LinkService
{
    public function __construct(private TokenService $tokens)
    {
    }

    public function event(array $event, ?string $channel = null): string
    {
        return app_url('i/' . $event['slug']) . ($channel ? '?c=' . rawurlencode($channel) : '');
    }

    public function guest(array $eg, ?string $channel = null): string
    {
        $t = $this->tokens->make(TokenService::GUEST, $eg['uuid'], (int) $eg['token_version']);
        return app_url('g/' . $t) . ($channel ? '?c=' . rawurlencode($channel) : '');
    }

    public function guestToken(array $eg): string
    {
        return $this->tokens->make(TokenService::GUEST, $eg['uuid'], (int) $eg['token_version']);
    }

    public function qrToken(array $eg): string
    {
        return $this->tokens->make(TokenService::QR, $eg['uuid'], (int) $eg['token_version']);
    }

    public function pass(array $eg): string
    {
        return app_url('g/' . $this->guestToken($eg) . '/pass');
    }

    public function unsubscribe(array $eg): string
    {
        return app_url('unsubscribe/' . $this->tokens->make(TokenService::UNSUB, $eg['uuid'], (int) $eg['token_version']));
    }

    public function ics(array $event): string
    {
        return app_url('i/' . $event['slug'] . '/calendar.ics');
    }

    public function googleCalendar(array $event): string
    {
        $fmt = static fn (string $utc) => gmdate('Ymd\THis\Z', strtotime($utc . ' UTC'));
        $end = $event['end_at'] ?: gmdate('Y-m-d H:i:s', strtotime($event['start_at'] . ' UTC') + 7200);
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event['title'],
            'dates' => $fmt($event['start_at']) . '/' . $fmt($end),
            'details' => 'Invitation: ' . $this->event($event),
            'location' => trim(($event['venue_name'] ?? '') . ' ' . ($event['venue_address'] ?? '')),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Host-supplied map link if there is one, otherwise a maps search for the address. */
    public function map(array $event): ?string
    {
        if (!empty($event['map_url'])) {
            return $event['map_url'];
        }
        $q = trim(($event['venue_name'] ?? '') . ' ' . ($event['venue_address'] ?? ''));
        return $q === '' ? null : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
    }

    /** wa.me share link. With a phone number it opens that chat; without, WhatsApp's contact picker. */
    public function whatsapp(string $text, ?string $phone = null): string
    {
        $digits = $phone ? preg_replace('/\D/', '', $phone) : '';
        return 'https://wa.me/' . ($digits !== '' ? $digits : '') . '?text=' . rawurlencode($text);
    }
}
