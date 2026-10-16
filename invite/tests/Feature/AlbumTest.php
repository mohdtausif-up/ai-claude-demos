<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\RetentionService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class AlbumTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAIAAAD/gAIDAAAAUElEQVR42u3PMQEAAAgDINc/9Kx4gkCmDaM07wUCgUAgEAgEAoFAIBAIBAKBQCAQCAQCgUAgEAgEAoFAIBAIBAKBQCAQCAQCgUAgEAgELhwLvQGTw1N6mQAAAABJRU5ErkJggg==';

    private function setup(array $over = []): array
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, $over);
        $base = '/app/events/' . $event['uuid'];
        $form = $host->get($base . '/details');
        $fields = ['_csrf' => $form->csrf(), 'title' => $event['title'], 'category' => 'birthday', 'start_at' => to_local_input($event['start_at'], $event['timezone']), 'timezone' => $event['timezone'], 'language' => 'en', 'visibility' => 'public', 'guest_list_visibility' => 'hidden',
            'rsvp_open' => '1', 'allow_maybe' => '1', 'allow_response_edit' => '1', 'collect_email' => '1', 'plus_one_limit' => '1', 'album_enabled' => '1'];
        $fields = $over + $fields; // explicit overrides win
        $this->assertRedirect($base . '/details', $host->post($base . '/details', $fields));
        $this->publish($host, $event);
        return [$host, $event, $base];
    }

    private function png(): string
    {
        $p = sys_get_temp_dir() . '/ph-' . bin2hex(random_bytes(3)) . '.png';
        file_put_contents($p, base64_decode(self::PNG));
        return $p;
    }

    private function guestLink(Http $host, array $event, string $base): array
    {
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Snap', 'email' => 'snap' . bin2hex(random_bytes(3)) . '@example.test']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        return [$eg, '/g/' . (new LinkService(new TokenService()))->guestToken($eg)];
    }

    public function testGuestUploadsAreReviewedBeforeTheyAppearAndHostsModerate(): void
    {
        [$host, $event, $base] = $this->setup(['album_moderated' => '1']);
        [$eg, $link] = $this->guestLink($host, $event, $base);
        $g = new Http();
        $this->assertContains('/photos', $g->get($link)->body, 'album link on the invitation');
        $page = $g->get($link . '/photos');
        $this->assertStatus(200, $page);
        $f = $this->png();
        $r = $g->upload($link . '/photos', 'photo', $f, 'image/png', ['_csrf' => $page->csrf(), 'caption' => 'Cake time']);
        $this->assertRedirect($link . '/photos', $r);
        $this->assertContains('once the hosts have approved', $g->follow()->body);
        $row = Db::one('SELECT * FROM guest_media WHERE event_id = ?', [$event['id']]);
        $this->assertSame('pending', $row['status']);
        $this->assertNotContains('Cake time', (new Http())->get('/i/' . $event['slug'] . '/photos')->body, 'pending photos are not shown to other visitors');
        // Host approves; now everyone with the link sees it.
        $album = $host->get($base . '/album');
        $this->assertContains('Waiting for review', $album->body);
        $host->post($base . '/album/' . $row['uuid'] . '/moderate', ['_csrf' => $album->csrf(), 'status' => 'approved']);
        $this->assertContains('Cake time', (new Http())->get($link . '/photos')->body);
        // Hide again, then delete: the file goes too.
        $host->post($base . '/album/' . $row['uuid'] . '/moderate', ['_csrf' => $album->csrf(), 'status' => 'rejected']);
        $this->assertNotContains('Cake time', (new Http())->get($link . '/photos')->body);
        $path = BASE_PATH . '/public/uploads/' . Db::value('SELECT m.path FROM media_assets m JOIN guest_media gm ON gm.media_id = m.id WHERE gm.uuid = ?', [$row['uuid']]);
        $this->assertTrue(is_file($path));
        $d = $host->post($base . '/album/' . $row['uuid'] . '/delete', ['_csrf' => $album->csrf()]);
        $this->assertRedirect($base . '/album', $d);
        clearstatcache(true);
        $this->assertTrue(!is_file($path), 'file removed from disk: ' . $path . ' rows=' . Db::value('SELECT COUNT(*) FROM guest_media WHERE uuid = ?', [$row['uuid']]));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM guest_media WHERE event_id = ?', [$event['id']]));
        @unlink($f);
    }

    public function testUnmoderatedAlbumPublishesGuestPhotosButPosterUploadsStayReviewed(): void
    {
        [$host, $event, $base] = $this->setup(['album_moderated' => '']);
        [$eg, $link] = $this->guestLink($host, $event, $base);
        $f = $this->png();
        $g = new Http();
        $page = $g->get($link . '/photos');
        $g->upload($link . '/photos', 'photo', $f, 'image/png', ['_csrf' => $page->csrf(), 'caption' => 'Direct']);
        $this->assertSame('approved', Db::value("SELECT status FROM guest_media WHERE caption = 'Direct' AND event_id = ?", [$event['id']]));
        // Anyone scanning the public poster can contribute, but always for review.
        $anon = new Http();
        $pp = $anon->get('/i/' . $event['slug'] . '/photos');
        $this->assertStatus(200, $pp);
        $anon->upload('/i/' . $event['slug'] . '/photos', 'photo', $f, 'image/png', ['_csrf' => $pp->csrf(), 'caption' => 'Poster']);
        $this->assertSame('pending', Db::value("SELECT status FROM guest_media WHERE caption = 'Poster' AND event_id = ?", [$event['id']]));
        $this->assertContains('Share your photos', $host->get($base . '/album/poster')->body);
        $this->assertContains('<svg', $host->body);
        // Hostile uploads are refused.
        $bad = sys_get_temp_dir() . '/x-' . bin2hex(random_bytes(3)) . '.png';
        file_put_contents($bad, '<?php echo 1; ?>');
        $pp = $anon->get('/i/' . $event['slug'] . '/photos');
        $r = $anon->upload('/i/' . $event['slug'] . '/photos', 'photo', $bad, 'image/png', ['_csrf' => $pp->csrf()]);
        $this->assertRedirect('/i/' . $event['slug'] . '/photos', $r);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM guest_media WHERE event_id = ?', [$event['id']]));
        @unlink($f);
        @unlink($bad);
    }

    public function testAlbumOffPrivateEventsAndPermissions(): void
    {
        [$host] = $this->newHost();
        $off = $this->newEvent($host);
        $this->publish($host, $off);
        $this->assertStatus(404, (new Http())->get('/i/' . $off['slug'] . '/photos'), 'album disabled');
        [$h2, $event, $base] = $this->setup(['visibility' => 'private']);
        $this->assertStatus(404, (new Http())->get('/i/' . $event['slug'] . '/photos'), 'private events: personal links only');
        [$eg, $link] = $this->guestLink($h2, $event, $base);
        $this->assertStatus(200, (new Http())->get($link . '/photos'));
        [$viewer, $email] = $this->newHost();
        $h2->submit($base . '/collaborators', $base . '/collaborators', ['email' => $email, 'role' => 'viewer']);
        $this->assertStatus(403, $viewer->get($base . '/album'), 'moderation needs edit rights');
        $this->assertStatus(404, (new Http())->get('/g/' . str_repeat('A', 22) . '.1.' . str_repeat('A', 22) . '/photos'));
    }

    public function testDownloadIsAValidZipOfApprovedPhotosAndFilesAreRemovedOnPurge(): void
    {
        [$host, $event, $base] = $this->setup(['album_moderated' => '']);
        [$eg, $link] = $this->guestLink($host, $event, $base);
        $f = $this->png();
        $g = new Http();
        foreach ([1, 2] as $i) {
            $page = $g->get($link . '/photos');
            $g->upload($link . '/photos', 'photo', $f, 'image/png', ['_csrf' => $page->csrf(), 'caption' => "P$i"]);
        }
        $zip = $host->get($base . '/album/download');
        $this->assertStatus(200, $zip);
        $this->assertContains('application/zip', $zip->headers['content-type'] ?? '');
        $this->assertSame("PK\x03\x04", substr($zip->body, 0, 4));
        $this->assertSame(2, substr_count($zip->body, "PK\x01\x02"), 'two entries in the central directory');
        $tmp = sys_get_temp_dir() . '/z-' . bin2hex(random_bytes(3)) . '.zip';
        file_put_contents($tmp, $zip->body);
        $this->assertSame(base64_decode(self::PNG), (new \Tests\Support\ZipProbe())->read($tmp, 0), 'extracts byte-for-byte');
        @unlink($tmp);
        // Purging the event removes the files from disk.
        $dir = BASE_PATH . '/public/uploads/' . $event['uuid'];
        $this->assertTrue(is_dir($dir) && count(glob($dir . '/*')) === 2);
        $host->submit($base . '/settings', $base . '/delete', ['confirm_title' => $event['title']]);
        Db::exec('UPDATE events SET deleted_at = ? WHERE id = ?', [utcnow(-(RetentionService::days() + 1) * 86400), $event['id']]);
        \App\Core\Container::get(RetentionService::class)->purgeDeletedEvents();
        clearstatcache(true);
        $this->assertTrue(!is_dir($dir), 'upload directory removed with the event');
        @unlink($f);
    }
}