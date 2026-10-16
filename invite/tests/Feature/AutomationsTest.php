<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container;
use App\Core\Db;
use App\Services\AutomationService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class AutomationsTest extends TestCase
{
    private function setup(array $over = []): array
    {
        [$host, $email] = $this->newHost();
        Db::exec('UPDATE users SET email_verified_at = ? WHERE email = ?', [utcnow(), $email]);
        $event = $this->newEvent($host, $over);
        $this->publish($host, $event);
        $base = '/app/events/' . $event['uuid'];
        return [$host, $event, $base, $email];
    }

    private function enable(Http $host, string $base, array $rules): void
    {
        $this->assertRedirect($base . '/automations', $host->submit($base . '/automations', $base . '/automations', ['rules' => $rules]));
    }

    private function guest(Http $host, string $base, string $name): string
    {
        $email = strtolower($name) . bin2hex(random_bytes(3)) . '@example.test';
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => $name, 'email' => $email]);
        return $email;
    }

    private function messagesOf(array $event, string $kind): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM messages WHERE event_id = ? AND kind = ?', [$event['id'], $kind]);
    }

    public function testQuietHoursHoldMessagesUntilMorningInTheEventTimezone(): void
    {
        $noonUtc = strtotime('2030-06-01 06:30:00 UTC'); // 12:00 in Kolkata
        $this->assertSame(null, AutomationService::quietUntil($noonUtc, 'Asia/Kolkata'));
        $lateUtc = strtotime('2030-06-01 17:00:00 UTC'); // 22:30 in Kolkata
        $this->assertSame('2030-06-02 02:30:00', AutomationService::quietUntil($lateUtc, 'Asia/Kolkata'), 'next 08:00 IST');
        $earlyUtc = strtotime('2030-06-01 22:00:00 UTC'); // 03:30 in Kolkata next day
        $this->assertSame('2030-06-02 02:30:00', AutomationService::quietUntil($earlyUtc, 'Asia/Kolkata'));
        $this->assertSame('2030-06-01 12:00:00', AutomationService::quietUntil(strtotime('2030-06-01 03:00:00 UTC'), 'America/New_York'), '23:00 the evening before in New York wakes at 08:00 EDT');
    }

    public function testDeadlineRemindersGoOnlyToPendingGuestsAndOnlyOnce(): void
    {
        [$host, $event, $base] = $this->setup();
        $pending = $this->guest($host, $base, 'Pending');
        $this->guest($host, $base, 'Other');
        $deadlineUtc = strtotime('+6 days 12 hours'); // inside the 7-day window already
        $local = (new \DateTimeImmutable('@' . $deadlineUtc))->setTimezone(new \DateTimeZone($event['timezone']))->format('Y-m-d\TH:i');
        Db::exec('UPDATE event_settings SET rsvp_deadline = ? WHERE event_id = ?', [gmdate('Y-m-d H:i:s', $deadlineUtc), $event['id']]);
        $this->enable($host, $base, ['reminder_7d', 'reminder_24h']);
        $svc = Container::get(AutomationService::class);
        $noon = strtotime(gmdate('Y-m-d', time()) . ' 06:30:00 UTC'); // within allowed hours in IST
        $svc->run($noon + 86400 * 0);
        $this->assertSame(1, $this->messagesOf($event, 'reminder'), '7-day reminder fired');
        $svc->run($noon);
        $this->assertSame(1, $this->messagesOf($event, 'reminder'), 'never twice for the same deadline');
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON m.id = mr.message_id WHERE m.event_id = ?', [$event['id']]), 'both pending guests');
        // 24h rule fires once the deadline is under a day away.
        $svc->run($deadlineUtc - 3600 * 5);
        $this->assertSame(2, $this->messagesOf($event, 'reminder'), 'final reminder fired');
        $this->assertTrue((int) Db::value("SELECT COUNT(*) FROM automation_runs WHERE event_id = ?", [$event['id']]) === 2);
        $this->assertTrue($pending !== '');
        // After the deadline nothing fires.
        $before = $this->messagesOf($event, 'reminder');
        $svc->run($deadlineUtc + 3600);
        $this->assertSame($before, $this->messagesOf($event, 'reminder'));
    }

    public function testDisabledRulesNeverRunAndUnverifiedHostsAreSkipped(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->guest($host, $base, 'Quiet');
        Db::exec('UPDATE event_settings SET rsvp_deadline = ? WHERE event_id = ?', [gmdate('Y-m-d H:i:s', strtotime('+3 days')), $event['id']]);
        $svc = Container::get(AutomationService::class);
        $svc->run();
        $this->assertSame(0, $this->messagesOf($event, 'reminder'), 'rules are opt-in');
        $this->enable($host, $base, ['reminder_7d']);
        Db::exec('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$event['created_by']]);
        $svc->run();
        $this->assertSame(0, $this->messagesOf($event, 'reminder'), 'unverified hosts cannot send');
    }

    public function testVenueChangeNotifiesActiveGuestsOnce(): void
    {
        [$host, $event, $base] = $this->setup();
        $email = $this->guest($host, $base, 'Mover');
        $skip = $this->guest($host, $base, 'Declined');
        $links = new \App\Services\LinkService(new \App\Services\TokenService());
        foreach (['Mover' => 'attending', 'Declined' => 'declined'] as $n => $st) {
            $eg = Db::one('SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = ? AND eg.event_id = ?', [$n, $event['id']]);
            $g = new Http();
            $path = '/g/' . $links->guestToken($eg);
            $p = $g->get($path);
            $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => $st]);
        }
        $this->enable($host, $base, ['venue_change']);
        $form = $host->get($base . '/details');
        $fields = [
            '_csrf' => $form->csrf(), 'title' => $event['title'], 'category' => 'birthday', 'start_at' => to_local_input($event['start_at'], $event['timezone']), 'timezone' => $event['timezone'],
            'language' => 'en', 'visibility' => 'public', 'guest_list_visibility' => 'hidden', 'rsvp_open' => '1', 'allow_maybe' => '1', 'allow_response_edit' => '1', 'collect_email' => '1',
            'plus_one_limit' => '2', 'venue_name' => 'New Venue', 'venue_address' => '9 New Road',
        ];
        $this->assertRedirect($base . '/details', $host->post($base . '/details', $fields));
        $this->assertSame(1, $this->messagesOf($event, 'update'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON m.id = mr.message_id WHERE m.event_id = ? AND m.kind = ?', [$event['id'], 'update']), 'only the attending guest');
        $this->assertContains('venue', strtolower((string) Db::value("SELECT body FROM messages WHERE event_id = ? AND kind = 'update'", [$event['id']])));
        // Saving without a relevant change sends nothing more.
        $host->post($base . '/details', $fields);
        $this->assertSame(1, $this->messagesOf($event, 'update'));
        $this->assertTrue($email !== $skip);
    }

    public function testCapacityAlertEmailsTheHostAtEightyAndHundredPercent(): void
    {
        [$host, $event, $base, $hostEmail] = $this->setup(['capacity' => '5', 'plus_one_limit' => '0']);
        $this->enable($host, $base, ['capacity_alert']);
        $svc = Container::get(AutomationService::class);
        $rsvp = function (string $n) use ($event) {
            $g = new Http();
            $p = $g->get('/i/' . $event['slug']);
            $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => $n]);
        };
        foreach (['Ann', 'Bob', 'Cat'] as $n) { $rsvp($n); }
        $svc->run();
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM automation_runs WHERE event_id = ?", [$event['id']]), '60%: quiet');
        $rsvp('Dan');
        $svc->run();
        $svc->run();
        $this->assertSame(['80'], Db::column("SELECT ref FROM automation_runs WHERE event_id = ? AND rule_key = 'capacity_alert'", [$event['id']]));
        $rsvp('Eve');
        $svc->run();
        $this->assertSame(['80', '100'], array_reverse(Db::column("SELECT ref FROM automation_runs WHERE event_id = ? AND rule_key = 'capacity_alert' ORDER BY id DESC", [$event['id']])));
        $this->runWorker();
        $this->assertTrue($this->mailTo($hostEmail) !== null);
    }

    public function testEventDayAndThankYouRules(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->guest($host, $base, 'Attender');
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $links = new \App\Services\LinkService(new \App\Services\TokenService());
        $g = new Http();
        $path = '/g/' . $links->guestToken($eg);
        $p = $g->get($path);
        $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending']);
        $this->enable($host, $base, ['event_day', 'thank_you']);
        $svc = Container::get(AutomationService::class);
        $start = strtotime($event['start_at'] . ' UTC');
        $morning = (new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone($event['timezone']))->setTime(9, 0)->getTimestamp();
        $svc->run($morning - 86400 * 2);
        $this->assertSame(0, $this->messagesOf($event, 'update'), 'too early');
        $svc->run($morning);
        $this->assertSame(1, $this->messagesOf($event, 'update'), 'directions on the event morning');
        $svc->run($morning + 600);
        $this->assertSame(1, $this->messagesOf($event, 'update'));
        $after = $start + 86400 + 3600 * 12;
        $svc->run($after);
        $this->assertSame(1, $this->messagesOf($event, 'thank_you'));
    }

    public function testAutomationsPageAndPermissions(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->assertContains('Turn on all recommended', $host->get($base . '/automations')->body);
        $host->submit($base . '/automations', $base . '/automations', ['recommended' => '1']);
        $this->assertSame(6, (int) Db::value('SELECT COUNT(*) FROM automations WHERE event_id = ? AND enabled = 1', [$event['id']]));
        [$viewer, $viewerEmail] = $this->newHost();
        $team = $base . '/collaborators';
        $host->submit($team, $team, ['email' => $viewerEmail, 'role' => 'viewer']);
        $this->assertStatus(403, $viewer->get($base . '/automations'));
    }
}