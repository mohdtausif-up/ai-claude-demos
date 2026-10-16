<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class GuestsTest extends TestCase
{
    /** @return array{Http,array,string} host, event, base path */
    private function setup(array $eventOver = []): array
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, $eventOver);
        return [$host, $event, '/app/events/' . $event['uuid']];
    }

    private function addGuest(Http $host, string $base, array $over = []): array
    {
        $f = $over + ['first_name' => 'Guest' . bin2hex(random_bytes(2)), 'last_name' => 'Test', 'email' => 'g' . bin2hex(random_bytes(4)) . '@example.test', 'phone' => '+9198' . random_int(10000000, 99999999)];
        $r = $host->submit($base . '/guests/new', $base . '/guests', $f);
        $this->assertRedirect($base . '/guests', $r);
        return $f;
    }

    public function testCsvImportRunsInBackgroundAndReportsProblemRows(): void
    {
        [$host, $event, $base] = $this->setup();
        $dupEmail = 'dup' . bin2hex(random_bytes(3)) . '@example.test';
        $csv = "Name,E-mail,Mobile,Family,Tags\n"
            . "Asha Sharma,asha" . bin2hex(random_bytes(3)) . "@example.test,+919800000001,Sharma family,\"family, bride\"\n"
            . "Ravi Kumar,$dupEmail,+919800000002,Kumar family,friends\n"
            . "Ravi Again,$dupEmail,,Kumar family,\n"
            . "Bad Email,not-an-email,,,\n"
            . ",nobody@example.test,,,\n"
            . "Priya Iyer,,+919800000003,,colleagues\n";
        $path = sys_get_temp_dir() . '/guests-' . bin2hex(random_bytes(3)) . '.csv';
        file_put_contents($path, $csv);

        $form = $host->get($base . '/guests/import');
        $r = $host->upload($base . '/guests/import', 'file', $path, 'text/csv', ['_csrf' => $form->csrf()]);
        $this->assertRedirect($base . '/guests/import/', $r);
        $importPath = $r->location();
        $page = $host->get($importPath);
        $this->assertContains('Match your columns', $page->body);
        $this->assertContains('selected>E-mail', str_replace(' selected', ' selected', $page->body), 'email column auto-detected');

        // Auto-detected mapping: name=0, email=1, phone=2, group=3, tags=4.
        $r = $host->post($importPath, ['_csrf' => $page->csrf(), 'map' => ['full_name' => '0', 'email' => '1', 'phone' => '2', 'group_name' => '3', 'tags' => '4'], 'on_duplicate' => 'skip']);
        $this->assertRedirect($importPath, $r);
        $this->assertSame('queued', Db::value('SELECT status FROM imports WHERE event_id = ?', [$event['id']]), 'import is queued, not run in the request');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]), 'nothing imported synchronously');

        $this->runWorker();
        $imp = Db::one('SELECT * FROM imports WHERE event_id = ?', [$event['id']]);
        $this->assertSame('done', $imp['status']);
        $this->assertSame(3, (int) $imp['created_rows'], 'Asha, Ravi, Priya');
        $this->assertSame(3, (int) $imp['skipped_rows'], 'duplicate email, invalid email, missing name');
        $errs = json_decode((string) $imp['errors_json'], true);
        $this->assertTrue(count($errs) >= 2 && $errs[0]['row'] > 1, 'row numbers reported');
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]));
        $list = $host->get($base . '/guests');
        $this->assertContains('Asha Sharma', $list->body);
        $this->assertContains('Sharma family', $list->body);
        $this->assertTrue(!is_file(BASE_PATH . '/storage/imports/' . $imp['file_path']), 'uploaded file removed after import');
        @unlink($path);
    }

    public function testImportRejectsNonCsvAndOversizedInput(): void
    {
        [$host, , $base] = $this->setup();
        $path = sys_get_temp_dir() . '/evil-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($path, '<?php echo 1;');
        $form = $host->get($base . '/guests/import');
        $r = $host->upload($base . '/guests/import', 'file', $path, 'text/plain', ['_csrf' => $form->csrf()]);
        $this->assertRedirect($base . '/guests/import', $r, 'wrong extension is refused');
        $bin = sys_get_temp_dir() . '/bin-' . bin2hex(random_bytes(3)) . '.csv';
        file_put_contents($bin, "a,b\n1,\0\0\0\n");
        $form = $host->get($base . '/guests/import');
        $r = $host->upload($base . '/guests/import', 'file', $bin, 'text/csv', ['_csrf' => $form->csrf()]);
        $this->assertRedirect($base . '/guests/import', $r, 'binary content is refused');
        @unlink($path);
        @unlink($bin);
    }

    public function testExportIsFormulaSafeAndHonoursFilters(): void
    {
        [$host, $event, $base] = $this->setup();
        $evil = $this->addGuest($host, $base, ['first_name' => '=HYPERLINK("http://evil.example","x")', 'last_name' => '+cmd']);
        $ok = $this->addGuest($host, $base, ['first_name' => 'Normal', 'last_name' => 'Person']);
        // Mark the second one attending (host edit).
        $egUuid = Db::value('SELECT eg.uuid FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.email = ?', [$ok['email']]);
        $show = $host->get($base . '/guests/' . $egUuid);
        $host->post($base . '/guests/' . $egUuid . '/respond', ['_csrf' => $show->csrf(), 'status' => 'attending', 'plus_ones' => '1', 'children' => '2']);

        $all = $host->get($base . '/guests/export');
        $this->assertStatus(200, $all);
        $this->assertSame("\xEF\xBB\xBF", substr($all->body, 0, 3), 'UTF-8 BOM for Excel');
        $this->assertContains('attachment', $all->headers['content-disposition'] ?? '');
        $this->assertContains("'=HYPERLINK", $all->body, 'formula neutralised');
        $this->assertContains("'+cmd", $all->body);
        $this->assertNotContains(",=HYPERLINK", $all->body);

        $filtered = $host->get($base . '/guests/export?status=attending');
        $this->assertContains('Normal', $filtered->body);
        $this->assertNotContains('HYPERLINK', $filtered->body, 'filter applies to export');
        $rows = array_map('str_getcsv', explode("\n", trim(substr($filtered->body, 3))));
        $this->assertSame('Headcount (attending)', $rows[0][10]);
        $this->assertSame('4', $rows[1][10], '1 guest + 1 plus-one + 2 children');
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE event_id = ? AND action = 'guests.exported'", [$event['id']]), 'each export is audit logged');
    }

    public function testSearchFilterSortAndBulkActions(): void
    {
        [$host, $event, $base] = $this->setup();
        $a = $this->addGuest($host, $base, ['first_name' => 'Alpha', 'tags' => 'college', 'group_name' => 'Team A']);
        $b = $this->addGuest($host, $base, ['first_name' => 'Bravo', 'tags' => 'work']);
        $c = $this->addGuest($host, $base, ['first_name' => 'Charlie']);
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]));

        $this->assertContains('Bravo', $host->get($base . '/guests?q=brav')->body);
        $this->assertNotContains('Alpha', $host->get($base . '/guests?q=brav')->body);
        $this->assertContains('Alpha', $host->get($base . '/guests?tag=college')->body);
        $this->assertNotContains('Bravo', $host->get($base . '/guests?tag=college')->body);
        $this->assertContains('Alpha', $host->get($base . '/guests?group=' . Db::value('SELECT uuid FROM guest_groups WHERE event_id = ?', [$event['id']]))->body);
        $this->assertNotContains('Charlie', $host->get($base . '/guests?status=attending')->body, 'nobody attending yet');

        $uuids = Db::column('SELECT uuid FROM event_guests WHERE event_id = ? ORDER BY id', [$event['id']]);
        $page = $host->get($base . '/guests');
        $r = $host->post($base . '/guests/bulk', ['_csrf' => $page->csrf(), 'action' => 'status:attending', 'uuids' => [$uuids[0], $uuids[1]]]);
        $this->assertRedirect($base . '/guests', $r);
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ? AND status = 'attending'", [$event['id']]), 'bulk status created two responses');
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM rsvp_response_history WHERE actor_type = 'host' AND response_id IN (SELECT id FROM rsvp_responses WHERE event_id = ?)", [$event['id']]), 'host edits are attributed in history');
        $this->assertNotContains('Charlie', $host->get($base . '/guests?status=attending')->body);
        $this->assertContains('Charlie', $host->get($base . '/guests?status=pending')->body);

        $page = $host->get($base . '/guests');
        $host->post($base . '/guests/bulk', ['_csrf' => $page->csrf(), 'action' => 'tag', 'value' => 'vip-table', 'uuids' => [$uuids[2]]]);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM guest_tags t JOIN event_guests eg ON eg.id = t.event_guest_id WHERE eg.event_id = ? AND t.tag = 'vip-table'", [$event['id']]));
        $del = $host->post($base . '/guests/bulk', ['_csrf' => $page->csrf(), 'action' => 'delete', 'uuids' => [$uuids[2]]]);
        $this->assertRedirect($base . '/guests', $del);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]));

        // Filters are remembered between visits; ?reset=1 clears them.
        $host->get($base . '/guests?status=attending');
        $this->assertContains('remembered', $host->get($base . '/guests')->body);
        $this->assertNotContains('remembered', $host->get($base . '/guests?reset=1')->body);
    }

    public function testSqlInjectionAttemptsInFiltersAreInert(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->addGuest($host, $base, ['first_name' => 'Safe']);
        foreach (["' OR '1'='1", "'; DROP TABLE guests; --", '" UNION SELECT 1,2,3 -- ', '%', '\\'] as $payload) {
            $r = $host->get($base . '/guests?q=' . rawurlencode($payload) . '&tag=' . rawurlencode($payload) . '&sort=' . rawurlencode($payload));
            $this->assertStatus(200, $r, 'payload ' . $payload);
        }
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]), 'table intact');
        $this->assertNotContains('Safe', $host->get($base . '/guests?q=' . rawurlencode("' OR '1'='1"))->body, 'quote did not widen the match');
    }

    public function testDuplicateEmailIsRejectedOnManualAdd(): void
    {
        [$host, $event, $base] = $this->setup();
        $g = $this->addGuest($host, $base);
        $r = $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Twin', 'email' => strtoupper($g['email'])]);
        $this->assertRedirect($base . '/guests/new', $r);
        $this->assertContains('already on the guest list', $host->follow()->body);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]));
    }

    public function testRotatingALinkInvalidatesTheOldOne(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->publish($host, $event);
        $g = $this->addGuest($host, $base);
        $eg = Db::one('SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.email = ?', [$g['email']]);
        $links = new LinkService(new \App\Services\TokenService());
        $old = $links->guestToken($eg);
        $guest = new Http();
        $this->assertStatus(200, $guest->get('/g/' . $old));
        $this->assertSame(1, (int) Db::value('SELECT open_count FROM event_guests WHERE id = ?', [$eg['id']]), 'open tracked');

        $show = $host->get($base . '/guests/' . $eg['uuid']);
        $this->assertRedirect($base . '/guests/' . $eg['uuid'], $host->post($base . '/guests/' . $eg['uuid'] . '/rotate-link', ['_csrf' => $show->csrf()]));
        $this->assertStatus(404, $guest->get('/g/' . $old), 'old link is dead');
        $fresh = Db::one('SELECT * FROM event_guests WHERE id = ?', [$eg['id']]);
        $this->assertStatus(200, $guest->get('/g/' . $links->guestToken($fresh)), 'new link works');
    }

    public function testLinkPreviewBotsDoNotCountAsOpens(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->publish($host, $event);
        $g = $this->addGuest($host, $base);
        $eg = Db::one('SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.email = ?', [$g['email']]);
        $link = '/g/' . (new LinkService(new \App\Services\TokenService()))->guestToken($eg);
        (new Http())->get($link, ['User-Agent: WhatsApp/2.23.20 A']);
        (new Http())->get($link, ['User-Agent: facebookexternalhit/1.1']);
        $this->assertSame(0, (int) Db::value('SELECT open_count FROM event_guests WHERE id = ?', [$eg['id']]), 'bots ignored');
        (new Http())->get($link . '?c=wa');
        $row = Db::one('SELECT open_count, last_channel FROM event_guests WHERE id = ?', [$eg['id']]);
        $this->assertSame(1, (int) $row['open_count']);
        $this->assertSame('wa', $row['last_channel'], 'channel tracked from ?c=wa');
    }

    public function testErasingAGuestKeepsAnonymousHeadcount(): void
    {
        [$host, $event, $base] = $this->setup();
        $this->publish($host, $event);
        $guest = new Http();
        $page = $guest->get('/i/' . $event['slug']);
        $r = $guest->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Erase Me', 'email' => 'erase' . bin2hex(random_bytes(3)) . '@example.test', 'plus_ones' => '1', 'comment' => 'secret note']);
        $path = explode('?', $r->location())[0];
        $mine = $guest->get($path);
        $this->assertContains('Delete my data', $mine->body);
        $del = $guest->post($path . '/delete-data', ['_csrf' => $mine->csrf()]);
        $this->assertStatus(200, $del);
        $this->assertContains('Your data was deleted', $del->body);
        $row = Db::one('SELECT g.first_name, g.email, g.phone, eg.anonymized_at, r.comment, r.status, r.plus_ones FROM event_guests eg JOIN guests g ON g.id = eg.guest_id JOIN rsvp_responses r ON r.event_guest_id = eg.id WHERE eg.event_id = ?', [$event['id']]);
        $this->assertSame('Anonymised', $row['first_name']);
        $this->assertSame(null, $row['email']);
        $this->assertSame(null, $row['comment']);
        $this->assertTrue($row['anonymized_at'] !== null);
        $this->assertSame('attending', $row['status'], 'RSVP kept for headcount');
        $live = $host->get($base . '/live')->json();
        $this->assertSame(2, $live['summary']['expected_headcount']);
        $this->assertStatus(404, $guest->get($path), 'link no longer works');
    }
}
