<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\ApiTokenService;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class ApiTest extends TestCase
{
    /** @return array{Http,array,string[],string} owner http, event, auth headers, user email */
    private function setup(string $role = 'owner'): array
    {
        [$owner, $email] = $this->newHost();
        $event = $this->newEvent($owner);
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $token = (new ApiTokenService())->create((int) $user['id'], (int) $event['workspace_id'], 'tests', null)['token'];
        return [$owner, $event, ['Authorization: Bearer ' . $token, 'Accept: application/json'], $email];
    }

    public function testEventsEndpointsPaginateFilterFieldsAndUseTheErrorEnvelope(): void
    {
        [$owner, $event, $auth] = $this->setup();
        $this->newEvent($owner);
        $this->newEvent($owner);
        $api = new Http();
        $list = $api->get('/api/v1/events?per_page=2&page=1', $auth);
        $this->assertStatus(200, $list);
        $j = $list->json();
        $this->assertSame(2, count($j['data']));
        $this->assertSame(3, $j['meta']['total']);
        $this->assertSame(2, $j['meta']['pages']);
        $this->assertSame(2, count($api->get('/api/v1/events?per_page=2&page=2', $auth)->json()['data']) + 1, 'page 2 holds the remaining event');
        $one = $api->get('/api/v1/events/' . $event['uuid'] . '?fields=id,title', $auth)->json()['data'];
        $this->assertSame(['id', 'title'], array_keys($one), 'field filtering');
        $this->assertSame($event['uuid'], $one['id'], 'public identifier is the UUID, never a sequential id');
        $full = $api->get('/api/v1/events/' . $event['uuid'], $auth)->json()['data'];
        $this->assertTrue(!isset($full['uuid']) && !array_key_exists('workspace_id', $full), 'internal columns are not exposed');
        $this->assertContains('T', $full['starts_at'], 'ISO-8601 times');

        $nf = $api->get('/api/v1/events/00000000-0000-4000-8000-000000000000', $auth);
        $this->assertStatus(404, $nf);
        $this->assertSame('not_found', $nf->json()['error']['code']);
        $bad = $api->get('/api/v1/events/not-a-uuid', $auth);
        $this->assertStatus(404, $bad);
        $v = $api->postJson('/api/v1/events/' . $event['uuid'] . '/guests', ['last_name' => 'NoFirst'], $auth);
        $this->assertStatus(422, $v);
        $e = $v->json()['error'];
        $this->assertSame('validation_failed', $e['code']);
        $this->assertTrue(isset($e['details']['first_name']), 'field-level details');
    }

    public function testGuestCreationIsIdempotentAndRoleChecked(): void
    {
        [$owner, $event, $auth] = $this->setup();
        $api = new Http();
        $body = ['first_name' => 'Api', 'last_name' => 'Guest', 'email' => 'api' . bin2hex(random_bytes(3)) . '@example.test'];
        $key = ['Idempotency-Key: create-' . bin2hex(random_bytes(6))];
        $first = $api->postJson('/api/v1/events/' . $event['uuid'] . '/guests', $body, array_merge($auth, $key));
        $this->assertStatus(201, $first);
        $again = $api->postJson('/api/v1/events/' . $event['uuid'] . '/guests', $body, array_merge($auth, $key));
        $this->assertStatus(200, $again);
        $this->assertSame($first->json()['data']['id'], $again->json()['data']['id'], 'replay returns the original guest');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]), 'no duplicate record');
        $other = $api->postJson('/api/v1/events/' . $event['uuid'] . '/guests', ['first_name' => 'Different'] + $body, array_merge($auth, $key));
        $this->assertStatus(422, $other, 'same key + different payload is refused');

        $list = $api->get('/api/v1/events/' . $event['uuid'] . '/guests?q=Api', $auth)->json();
        $this->assertSame(1, $list['meta']['total']);
        $this->assertSame('pending', $list['data'][0]['rsvp']['status']);

        // A token belonging to a read-only member cannot write.
        [$viewer, $viewerEmail] = $this->newHost('Viewer');
        $team = '/app/events/' . $event['uuid'] . '/collaborators';
        $owner->submit($team, $team, ['email' => $viewerEmail, 'role' => 'viewer']);
        $vu = Db::one('SELECT * FROM users WHERE email = ?', [$viewerEmail]);
        $vt = (new ApiTokenService())->create((int) $vu['id'], (int) $event['workspace_id'], 'viewer', null)['token'];
        $vauth = ['Authorization: Bearer ' . $vt, 'Accept: application/json'];
        $this->assertStatus(200, $api->get('/api/v1/events/' . $event['uuid'] . '/guests', $vauth));
        $this->assertStatus(403, $api->postJson('/api/v1/events/' . $event['uuid'] . '/guests', ['first_name' => 'Nope'], $vauth));
        // Revoked and expired tokens stop working.
        Db::exec("UPDATE api_tokens SET revoked_at = ? WHERE name = 'viewer'", [utcnow()]);
        $this->assertStatus(401, $api->get('/api/v1/events/' . $event['uuid'], $vauth));
    }

    public function testResponsesAndReportsExposeHeadcountsAndAnswers(): void
    {
        [$owner, $event, $auth] = $this->setup();
        $form = '/app/events/' . $event['uuid'] . '/rsvp-form';
        $owner->submit($form, $form . '/questions', ['type' => 'meal', 'label' => 'Meal', 'options' => "Veg\nNon-veg"]);
        $this->publish($owner, $event);
        $meal = Db::value('SELECT uuid FROM rsvp_questions WHERE event_id = ?', [$event['id']]);
        $g = new Http();
        $p = $g->get('/i/' . $event['slug']);
        $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Report Rita', 'plus_ones' => '2', 'children' => '1', 'answers' => [$meal => 'Veg']]);
        $api = new Http();
        $res = $api->get('/api/v1/events/' . $event['uuid'] . '/responses', $auth)->json();
        $row = $res['data'][0];
        $this->assertSame('attending', $row['rsvp']['status']);
        $this->assertSame(4, $row['rsvp']['headcount']);
        $this->assertSame('Meal', $row['answers'][0]['question']);
        $this->assertSame('Veg', $row['answers'][0]['answer']);
        $rep = $api->get('/api/v1/events/' . $event['uuid'] . '/reports', $auth)->json()['data'];
        $this->assertSame(4, $rep['summary']['expected_headcount']);
        $this->assertSame(2, $rep['summary']['plus_ones']);
        $this->assertSame(1, $rep['summary']['children']);
        $this->assertSame(['Veg' => 1], $rep['meals'][0]['answers']);
    }

    public function testPublicRsvpEndpointRequiresIdempotencyKeyAndIsReplaySafe(): void
    {
        [$owner, $event] = $this->setup();
        $this->publish($owner, $event);
        $pub = new Http();
        $show = $pub->get('/api/v1/invitations/' . $event['slug'], ['Accept: application/json']);
        $this->assertStatus(200, $show);
        $d = $show->json()['data'];
        $this->assertTrue($d['accepting_rsvps']);
        $this->assertSame(2, $d['options']['plus_one_limit']);
        $this->assertTrue(!isset($d['uuid']) && !isset($d['workspace_id']));

        $body = ['name' => 'Widget Wanda', 'email' => 'w' . bin2hex(random_bytes(3)) . '@example.test', 'status' => 'attending', 'plus_ones' => 1];
        $no = $pub->postJson('/api/v1/invitations/' . $event['slug'] . '/rsvp', $body);
        $this->assertStatus(400, $no, 'Idempotency-Key is mandatory');
        $key = ['Idempotency-Key: widget-' . bin2hex(random_bytes(6))];
        $ok = $pub->postJson('/api/v1/invitations/' . $event['slug'] . '/rsvp', $body, $key);
        $this->assertStatus(201, $ok, 'no CSRF token needed for the public JSON API');
        $this->assertSame('attending', $ok->json()['data']['status']);
        $this->assertSame(2, $ok->json()['data']['headcount']);
        $manage = $ok->json()['data']['manage_url'];
        $this->assertStatus(200, (new Http())->get(parse_url($manage, PHP_URL_PATH)), 'the returned link opens the guest\'s own page');
        $replay = $pub->postJson('/api/v1/invitations/' . $event['slug'] . '/rsvp', $body, $key);
        $this->assertStatus(200, $replay);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$event['id']]), 'replay created nothing');

        // A second submission with the same email does not reveal or overwrite the first.
        $dup = $pub->postJson('/api/v1/invitations/' . $event['slug'] . '/rsvp', ['status' => 'declined'] + $body, ['Idempotency-Key: widget-' . bin2hex(random_bytes(6))]);
        $this->assertStatus(201, $dup);
        $this->assertSame('received', $dup->json()['data']['status']);
        $this->assertSame('attending', Db::value('SELECT status FROM rsvp_responses WHERE event_id = ?', [$event['id']]), 'original response untouched');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$event['id']]));

        // Invalid data uses the standard error envelope.
        $bad = $pub->postJson('/api/v1/invitations/' . $event['slug'] . '/rsvp', ['status' => 'perhaps', 'name' => 'x'], ['Idempotency-Key: widget-' . bin2hex(random_bytes(6))]);
        $this->assertStatus(422, $bad);
        $this->assertSame('validation_failed', $bad->json()['error']['code']);
        // Private events are not exposed publicly.
        $priv = $this->newEvent($owner, ['visibility' => 'private']);
        $this->publish($owner, $priv);
        $this->assertStatus(404, $pub->get('/api/v1/invitations/' . $priv['slug'], ['Accept: application/json']));
    }

    public function testCheckinApi(): void
    {
        [$owner, $event, $auth] = $this->setup();
        $this->publish($owner, $event);
        $g = new Http();
        $p = $g->get('/i/' . $event['slug']);
        $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Scanner Sam', 'plus_ones' => '1']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $qr = (new LinkService(new TokenService()))->qrToken($eg);
        $api = new Http();
        $v = $api->postJson('/api/v1/checkin/validate', ['event' => $event['uuid'], 'code' => $qr], $auth)->json();
        $this->assertSame('ready', $v['code']);
        $c = $api->postJson('/api/v1/checkin/complete', ['event' => $event['uuid'], 'code' => $qr, 'entry_point' => 'API door'], $auth);
        $this->assertStatus(200, $c);
        $this->assertSame('checked_in', $c->json()['code']);
        $dup = $api->postJson('/api/v1/checkin/complete', ['event' => $event['uuid'], 'code' => $qr], $auth);
        $this->assertSame('duplicate', $dup->json()['code']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM checkins WHERE event_id = ?', [$event['id']]));
        $bad = $api->postJson('/api/v1/checkin/complete', ['event' => $event['uuid'], 'code' => 'nonsense'], $auth);
        $this->assertStatus(422, $bad);
    }

    public function testApiRateLimitReturns429WithRetryAfter(): void
    {
        [, , $auth] = $this->setup();
        Db::exec('DELETE FROM rate_limits');
        $api = new Http();
        $last = null;
        for ($i = 0; $i < 125; $i++) {
            $last = $api->get('/api/v1/events', $auth);
            if ($last->status === 429) {
                break;
            }
        }
        $this->assertStatus(429, $last);
        $this->assertTrue(isset($last->headers['retry-after']));
        $this->assertSame('rate_limited', $last->json()['error']['code']);
        Db::exec('DELETE FROM rate_limits');
    }
}
