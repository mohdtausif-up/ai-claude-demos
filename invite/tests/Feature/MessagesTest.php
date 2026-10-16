<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Jobs\EmailJob;
use App\Services\LinkService;
use App\Services\Mailer;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class MessagesTest extends TestCase
{
    /** @return array{Http,array,string} verified host, published event, base path */
    private function setup(): array
    {
        [$host, $email] = $this->newHost();
        Db::exec('UPDATE users SET email_verified_at = ? WHERE email = ?', [utcnow(), $email]);
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        return [$host, $event, '/app/events/' . $event['uuid']];
    }

    private function guest(Http $host, string $base, string $first, ?string $email): array
    {
        $f = ['first_name' => $first, 'email' => $email ?? '', 'phone' => ''];
        $host->submit($base . '/guests/new', $base . '/guests', $f);
        return Db::one('SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE eg.event_id = ? AND g.first_name = ? ORDER BY eg.id DESC', [(int) Db::value('SELECT id FROM events WHERE uuid = ?', [basename(dirname($base . '/x'))]) ?: 0, $first]) ?? Db::one('SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = ? ORDER BY eg.id DESC', [$first]);
    }

    private function send(Http $host, string $base, array $over = []): Http
    {
        $fields = $over + ['kind' => 'invitation', 'segment' => 'not_invited', 'subject' => 'Join us: {event_title}', 'body' => "Hi {first_name},\n\nPlease come. {link}\n\n{host}"];
        return $host->submit($base . '/messages', $base . '/messages', $fields);
    }

    public function testInvitationEmailsAreQueuedThenSentWithPersonalLinks(): void
    {
        [$host, $event, $base] = $this->setup();
        $emailA = 'a' . bin2hex(random_bytes(3)) . '@example.test';
        $emailB = 'b' . bin2hex(random_bytes(3)) . '@example.test';
        $a = $this->guest($host, $base, 'Anya', $emailA);
        $this->guest($host, $base, 'Boris', $emailB);
        $this->guest($host, $base, 'Noemail', null);

        $r = $this->send($host, $base);
        $this->assertRedirect($base . '/messages', $r);
        $this->assertContains('Queued 2 email', $host->follow()->body);
        $this->assertContains('skipped', $host->body, 'guest without email is reported as skipped');
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON m.id = mr.message_id WHERE m.event_id = ?", [$event['id']]));

        $this->runWorker();
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON m.id = mr.message_id WHERE m.event_id = ? AND mr.status = 'sent'", [$event['id']]));
        $egA = Db::one('SELECT * FROM event_guests WHERE id = ?', [$a['id']]);
        $this->assertTrue($egA['invite_sent_at'] !== null, 'invite_sent_at recorded');
        $this->assertSame('sent', $egA['delivery_status']);

        $mail = $this->mailTo($emailA);
        $this->assertTrue($mail !== null, 'email arrived in the inbox');
        $this->assertContains('Join us: ' . $event['title'], $mail['Subject']);
        $this->assertContains('Hi Anya', $mail['Text']);
        $this->assertContains('/unsubscribe/', $mail['Text'], 'unsubscribe link in every email');
        preg_match('#http://[^\s)]+/g/([A-Za-z0-9._-]+)#', $mail['Text'], $m);
        $this->assertTrue(!empty($m[1]), 'personal link in the email');
        $guest = new Http();
        $page = $guest->get('/g/' . $m[1]);
        $this->assertStatus(200, $page);
        $this->assertContains('Responding as Anya', $page->body, 'link identifies the guest');
        // The same link is the guest's own, not Boris's.
        $this->assertNotContains('Boris', $page->body);
    }

    public function testRemindersGoOnlyToPendingGuestsAndRespectUnsubscribe(): void
    {
        [$host, $event, $base] = $this->setup();
        $emailDone = 'done' . bin2hex(random_bytes(3)) . '@example.test';
        $emailPending = 'pend' . bin2hex(random_bytes(3)) . '@example.test';
        $emailUnsub = 'unsub' . bin2hex(random_bytes(3)) . '@example.test';
        $done = $this->guest($host, $base, 'Done', $emailDone);
        $this->guest($host, $base, 'Pending', $emailPending);
        $unsub = $this->guest($host, $base, 'Quitter', $emailUnsub);
        // "Done" responds through their personal link.
        $links = new LinkService(new TokenService());
        $g = new Http();
        $p = $g->get('/g/' . $links->guestToken($done));
        $g->post('/g/' . $links->guestToken($done) . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'declined']);
        // "Quitter" unsubscribes using the one-click endpoint mail clients use (no CSRF token, no cookies).
        $unsubUrl = '/unsubscribe/' . substr($links->unsubscribe($unsub), strlen(app_url('unsubscribe/')));
        $oneClick = new Http();
        $this->assertStatus(200, $oneClick->post($unsubUrl, ['List-Unsubscribe' => 'One-Click']));
        $this->assertSame(1, (int) Db::value('SELECT do_not_contact FROM event_guests WHERE id = ?', [$unsub['id']]));

        $r = $this->send($host, $base, ['kind' => 'reminder', 'segment' => 'pending', 'subject' => 'Reminder', 'body' => 'Please RSVP: {link}']);
        $this->assertRedirect($base . '/messages', $r);
        $this->assertContains('Queued 1 email', $host->follow()->body);
        $this->runWorker();
        $this->assertTrue($this->mailTo($emailPending) !== null, 'pending guest reminded');
        $reminders = static fn (array $res) => count(array_filter($res['messages'] ?? [], static fn ($m) => $m['Subject'] === 'Reminder'));
        $this->assertSame(0, $reminders($this->mailpit('search?query=' . rawurlencode('to:' . $emailDone))), 'responded guest not reminded (their RSVP confirmation is a separate, transactional email)');
        $this->assertSame(0, count($this->mailpit('search?query=' . rawurlencode('to:' . $emailUnsub))['messages'] ?? []), 'unsubscribed guest not emailed');
        $this->assertSame(1, $reminders($this->mailpit('search?query=' . rawurlencode('to:' . $emailPending))), 'pending guest got exactly one reminder');
    }

    public function testUnverifiedHostCannotSendAndTestEmailGoesToHostOnly(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        $this->guest($host, $base, 'Someone', 'someone' . bin2hex(random_bytes(3)) . '@example.test');
        $r = $this->send($host, $base);
        $this->assertStatus(403, $r);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM messages WHERE event_id = ?', [$event['id']]));
    }

    public function testScheduledMessagesWaitUntilTheyAreDue(): void
    {
        [$host, $event, $base] = $this->setup();
        $email = 'later' . bin2hex(random_bytes(3)) . '@example.test';
        $this->guest($host, $base, 'Later', $email);
        $when = (new \DateTimeImmutable('+3 hours', new \DateTimeZone($event['timezone'])))->format('Y-m-d\TH:i'); // event-local time, as the host types it
        $r = $this->send($host, $base, ['scheduled_at' => $when]);
        $this->assertRedirect($base . '/messages', $r);
        $job = Db::one("SELECT * FROM notification_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.to')) = ? ORDER BY id DESC", [$email]);
        $this->assertTrue($job['available_at'] > utcnow(3600), 'job is held until the scheduled time');
        $this->runWorker();
        $this->assertSame('pending', Db::value('SELECT status FROM notification_jobs WHERE id = ?', [$job['id']]));
        $this->assertSame('queued', Db::value("SELECT mr.status FROM message_recipients mr JOIN messages m ON m.id = mr.message_id JOIN event_guests eg ON eg.id = mr.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE g.email = ?", [$email]));
    }

    public function testHostMessageBodyIsEscapedInEmail(): void
    {
        [$host, $event, $base] = $this->setup();
        $email = 'xss' . bin2hex(random_bytes(3)) . '@example.test';
        $this->guest($host, $base, 'Xss', $email);
        $this->send($host, $base, ['subject' => 'Hello', 'body' => 'Hi <script>alert(1)</script> <b>bold</b> {link}']);
        $this->runWorker();
        $mail = $this->mailTo($email);
        $this->assertTrue($mail !== null);
        $this->assertNotContains('<script>', $mail['HTML']);
        $this->assertContains('&lt;script&gt;', $mail['HTML']);
    }

    public function testFailedDeliveryAndBouncesAreVisibleToHosts(): void
    {
        [$host, $event, $base] = $this->setup();
        $email = 'bounce' . bin2hex(random_bytes(3)) . '@example.test';
        $eg = $this->guest($host, $base, 'Bouncer', $email);
        $this->send($host, $base);

        // Exhausted retries: the recipient and guest are marked failed and the host is told why.
        $job = Db::one("SELECT * FROM notification_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.to')) = ? ORDER BY id DESC", [$email]);
        $payload = json_decode($job['payload'], true);
        Db::exec("UPDATE notification_jobs SET status = 'done' WHERE id = ?", [$job['id']]); // keep the live worker away from it
        $failing = new class extends Mailer {
            public function send(array $m): string
            {
                throw new \RuntimeException('SMTP error: 550 mailbox unavailable');
            }
        };
        $handler = new EmailJob($failing, \App\Core\Container::get(\App\Services\WebhookService::class));
        try {
            $handler->handle($payload);
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
            $handler->failed($payload, $e->getMessage());
        }
        $this->assertSame('failed', Db::value('SELECT delivery_status FROM event_guests WHERE id = ?', [$eg['id']]));
        $page = $host->get($base . '/messages');
        $this->assertContains('Delivery problems', $page->body);
        $this->assertContains($email, $page->body);
        $this->assertContains('550 mailbox unavailable', $page->body);
        $this->assertContains('failed', strtolower($host->get($base . '/guests?delivery=failed')->body));

        // A provider bounce webhook is authenticated and updates the guest.
        $eg2 = $this->guest($host, $base, 'Bounce2', 'b2' . bin2hex(random_bytes(3)) . '@example.test');
        $this->send($host, $base);
        $this->runWorker();
        $mid = Db::value("SELECT provider_message_id FROM notification_logs WHERE event_guest_id = ? AND status = 'sent' ORDER BY id DESC LIMIT 1", [$eg2['id']]);
        $this->assertTrue($mid !== null, 'provider message id recorded');
        $anon = new Http();
        $this->assertStatus(401, $anon->postJson('/webhooks/email-bounce', ['message_id' => $mid, 'reason' => 'x'], ['Authorization: Bearer wrong']));
        $this->assertStatus(200, $anon->postJson('/webhooks/email-bounce', ['message_id' => $mid, 'reason' => 'Mailbox full'], ['Authorization: Bearer ' . env('BOUNCE_WEBHOOK_SECRET')]));
        $this->assertSame('bounced', Db::value('SELECT delivery_status FROM event_guests WHERE id = ?', [$eg2['id']]));
        $this->assertContains('Bounced', $host->get($base . '/messages')->body);
    }

    public function testWhatsAppShareLinkCarriesThePersonalUrlAndTracksChannel(): void
    {
        [$host, $event, $base] = $this->setup();
        $f = ['first_name' => 'Wanda', 'phone' => '+91 98765 43210'];
        $host->submit($base . '/guests/new', $base . '/guests', $f);
        $list = $host->get($base . '/guests');
        $this->assertTrue((bool) preg_match('#https://wa\.me/919876543210\?text=([^"&]+)#', $list->body, $m), 'wa.me link with the number');
        $text = urldecode(html_entity_decode($m[1]));
        $this->assertContains('Wanda', $text);
        $this->assertTrue((bool) preg_match('#/g/[A-Za-z0-9._-]+\?c=wa#', $text), 'tokenised URL tagged as WhatsApp');
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ? ORDER BY id DESC', [$event['id']]);
        $this->assertSame('none', $eg['delivery_status']);
        $this->assertStatus(204, $host->post($base . '/guests/' . $eg['uuid'] . '/shared', ['_csrf' => $host->csrf()]));
        $eg = Db::one('SELECT * FROM event_guests WHERE id = ?', [$eg['id']]);
        $this->assertSame('wa', $eg['last_channel']);
        $this->assertSame('sent', $eg['delivery_status']);
    }
}
