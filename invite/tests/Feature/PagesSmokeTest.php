<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Http;
use Tests\Support\TestCase;

/** Every page a host can open renders without errors, with and without data. */
final class PagesSmokeTest extends TestCase
{
    public function testPublicPagesRender(): void
    {
        $h = new Http();
        foreach (['/', '/templates', '/pricing', '/privacy', '/terms', '/help', '/help/api', '/login', '/register', '/forgot'] as $path) {
            $this->assertStatus(200, $h->get($path), $path);
        }
        $this->assertStatus(200, $h->get('/manifest.webmanifest'));
        $this->assertStatus(200, $h->get('/sw.js'));
        $this->assertStatus(404, $h->get('/nope'));
        $this->assertRedirect('/login', $h->get('/app/events/00000000-0000-4000-8000-000000000000'));
        $this->assertStatus(401, $h->get('/app/events', ['Accept: application/json']), 'JSON clients get 401, not a redirect');
    }

    public function testEveryHostPageRendersForAnEventOwner(): void
    {
        [$host] = $this->newHost('Smoke Owner');
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        foreach (['/app', '/app/events', '/app/events/new', '/app/settings', $base, $base . '/details', $base . '/invitation', $base . '/preview',
            $base . '/guests', $base . '/guests/new', $base . '/guests/import', $base . '/guests/cards', $base . '/rsvp-form', $base . '/messages',
            $base . '/reports', $base . '/reports/caterer', $base . '/collaborators', $base . '/sub-events', $base . '/checkin', $base . '/settings',
            $base . '/qr.svg', $base . '/live'] as $path) {
            $r = $host->get($path);
            $this->assertStatus(200, $r, $path);
            $this->assertNotContains('Warning:', $r->body, "PHP warning on $path");
            $this->assertNotContains('Undefined', $r->body, "undefined variable on $path");
        }
        // After publishing, with a guest and a question, the same pages still render.
        $this->publish($host, $event);
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'meal']);
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Smoke', 'last_name' => 'Guest', 'email' => 'smoke' . bin2hex(random_bytes(3)) . '@example.test', 'phone' => '+919876543210', 'group_name' => 'Family', 'tags' => 'vip, friends']);
        foreach ([$base, $base . '/guests', $base . '/reports', $base . '/messages', $base . '/checkin', $base . '/rsvp-form', $base . '/invitation'] as $path) {
            $r = $host->get($path);
            $this->assertStatus(200, $r, $path);
            $this->assertNotContains('Undefined', $r->body, "undefined on $path");
        }
        $this->assertContains('Smoke Guest', $host->get($base . '/guests')->body);
    }

    public function testApiDocsAndAdminAreNotLeaked(): void
    {
        [$host] = $this->newHost();
        $this->assertStatus(404, $host->get('/admin'), 'non-admins see 404');
        $this->assertStatus(404, $host->get('/admin/users'));
    }
}
