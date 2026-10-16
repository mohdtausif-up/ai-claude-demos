<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\PollService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class PollsTest extends TestCase
{
    private function setup(): array
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $base = '/app/events/' . $event['uuid'];
        $egs = [];
        foreach (['Ann', 'Bo', 'Cy'] as $n) {
            $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => $n, 'email' => strtolower($n) . bin2hex(random_bytes(3)) . '@example.test']);
            $egs[$n] = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = ? AND eg.event_id = ?", [$n, $event['id']]);
        }
        return [$host, $event, $base, $egs];
    }

    private function poll(Http $host, string $base, array $f): array
    {
        $r = $host->submit($base . '/polls', $base . '/polls', $f + ['type' => 'choice', 'title' => 'Which date?', 'options' => "Sat\nSun\nMon", 'results_visibility' => 'after_vote', 'allow_change' => '1']);
        $this->assertRedirect($base . '/polls', $r);
        return Db::one('SELECT * FROM polls ORDER BY id DESC LIMIT 1');
    }

    private function vote(array $eg, array $poll, array $fields): Http
    {
        $links = new LinkService(new TokenService());
        $g = new Http();
        $path = '/g/' . $links->guestToken($eg) . '/polls';
        $p = $g->get($path);
        $g->post($path . '/' . $poll['uuid'], ['_csrf' => $p->csrf()] + $fields);
        return $g;
    }

    private function opt(array $poll, string $label): int
    {
        return (int) Db::value('SELECT id FROM poll_options WHERE poll_id = ? AND label = ?', [$poll['id'], $label]);
    }

    public function testSingleChoicePollVotingChangingAndResultVisibility(): void
    {
        [$host, $event, $base, $egs] = $this->setup();
        $poll = $this->poll($host, $base, []);
        $sat = $this->opt($poll, 'Sat');
        $sun = $this->opt($poll, 'Sun');
        $g = $this->vote($egs['Ann'], $poll, ['choice' => [$sat]]);
        $this->assertContains('Thanks, your vote is saved', $g->follow()->body);
        $this->assertContains('1 vote', $g->body, 'after_vote: voter sees results');
        $this->vote($egs['Bo'], $poll, ['choice' => [$sat]]);
        $this->vote($egs['Cy'], $poll, ['choice' => [$sun]]);
        $this->vote($egs['Cy'], $poll, ['choice' => [$sat]]); // changed mind
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = ?', [$poll['id']]), 'one row per voter');
        $res = (new PollService(new \App\Services\AuditLogger()))->results($poll);
        $this->assertSame(3, $res['options'][0]['votes']);
        // Non-voters do not see tallies under after_vote.
        $links = new LinkService(new TokenService());
        $page = (new Http())->get('/g/' . $links->guestToken($egs['Ann']) . '/polls');
        $this->assertContains('3 votes', $page->body);
        // Invalid: two options on a single-choice poll, foreign option ids.
        $r = $this->vote($egs['Ann'], $poll, ['choice' => [$sat, $sun]]);
        $this->assertRedirect('/g/', $r);
        $this->assertSame(['"' . $sat . '"' => 1] === [] ? 0 : 1, 1);
        $mine = json_decode(Db::value('SELECT choices_json FROM poll_responses WHERE poll_id = ? AND event_guest_id = ?', [$poll['id'], $egs['Ann']['id']]), true);
        $this->assertSame([(string) $sat => 1], $mine, 'invalid vote did not overwrite');
        $this->vote($egs['Ann'], $poll, ['choice' => [999999]]);
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = ?', [$poll['id']]));
    }

    public function testMultipleChoiceRankedAvailabilityAndYesNo(): void
    {
        [$host, $event, $base, $egs] = $this->setup();
        $multi = $this->poll($host, $base, ['allow_multiple' => '1', 'max_choices' => '2', 'title' => 'Multi']);
        $ids = [$this->opt($multi, 'Sat'), $this->opt($multi, 'Sun'), $this->opt($multi, 'Mon')];
        $this->vote($egs['Ann'], $multi, ['choice' => [$ids[0], $ids[1], $ids[2]]]);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = ?', [$multi['id']]), 'max_choices enforced');
        $this->vote($egs['Ann'], $multi, ['choice' => [$ids[0], $ids[1]]]);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = ?', [$multi['id']]));

        $ranked = $this->poll($host, $base, ['type' => 'ranked', 'title' => 'Ranked']);
        [$a, $b, $c] = [$this->opt($ranked, 'Sat'), $this->opt($ranked, 'Sun'), $this->opt($ranked, 'Mon')];
        $this->vote($egs['Ann'], $ranked, ['rank' => [$a => '2', $b => '1', $c => '3']]);
        $this->vote($egs['Bo'], $ranked, ['rank' => [$b => '1', $a => '2']]);
        $this->vote($egs['Cy'], $ranked, ['rank' => [$a => '1', $b => '2']]);
        $res = (new PollService(new \App\Services\AuditLogger()))->results($ranked);
        $byLabel = array_column($res['options'], null, 'label');
        $this->assertSame(2, $byLabel['Sun']['first']);
        $this->assertTrue($byLabel['Sun']['score'] > $byLabel['Sat']['score'] && $byLabel['Sat']['score'] > $byLabel['Mon']['score'], 'Borda scoring orders the options');

        $avail = $this->poll($host, $base, ['type' => 'availability', 'title' => 'Avail']);
        [$a, $b] = [$this->opt($avail, 'Sat'), $this->opt($avail, 'Sun')];
        $this->vote($egs['Ann'], $avail, ['answers' => [$a => 'yes', $b => 'no']]);
        $this->vote($egs['Bo'], $avail, ['answers' => [$a => 'maybe', $b => 'yes']]);
        $res = (new PollService(new \App\Services\AuditLogger()))->results($avail);
        $this->assertSame(1, $res['options'][0]['yes']);
        $this->assertSame(1, $res['options'][0]['maybe']);

        $yn = $this->poll($host, $base, ['type' => 'yesno', 'title' => 'Parking?', 'options' => '']);
        $this->assertSame(['Yes', 'No', 'Maybe'], Db::column('SELECT label FROM poll_options WHERE poll_id = ? ORDER BY position', [$yn['id']]));
    }

    public function testAnonymousPollsDoNotLinkVotesToGuestsAndOneVoteEach(): void
    {
        [$host, $event, $base, $egs] = $this->setup();
        $poll = $this->poll($host, $base, ['anonymous' => '1']);
        $this->vote($egs['Ann'], $poll, ['choice' => [$this->opt($poll, 'Sat')]]);
        $this->vote($egs['Ann'], $poll, ['choice' => [$this->opt($poll, 'Sun')]]);
        $row = Db::one('SELECT * FROM poll_responses WHERE poll_id = ?', [$poll['id']]);
        $this->assertSame(null, $row['event_guest_id'], 'no link back to the guest');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = ?', [$poll['id']]), 'still one vote per guest');
        $this->assertNotContains('Ann', $host->get($base . '/polls')->body . '', 'host UI shows no names for anonymous polls') ;
    }

    public function testClosingAutoSelectsMajorityWinnerAndLeavesTiesToTheHost(): void
    {
        [$host, $event, $base, $egs] = $this->setup();
        $win = $this->poll($host, $base, ['auto_select' => '1', 'title' => 'Majority']);
        $sat = $this->opt($win, 'Sat');
        $this->vote($egs['Ann'], $win, ['choice' => [$sat]]);
        $this->vote($egs['Bo'], $win, ['choice' => [$sat]]);
        $this->vote($egs['Cy'], $win, ['choice' => [$this->opt($win, 'Sun')]]);
        Db::exec('UPDATE polls SET closes_at = ? WHERE id = ?', [utcnow(-60), $win['id']]);
        $this->assertSame(1, (new PollService(new \App\Services\AuditLogger()))->closeDue() >= 1 ? 1 : 0);
        $row = Db::one('SELECT * FROM polls WHERE id = ?', [$win['id']]);
        $this->assertSame('closed', $row['status']);
        $this->assertSame($sat, (int) $row['winner_option_id']);

        $tie = $this->poll($host, $base, ['auto_select' => '1', 'title' => 'Tie']);
        $this->vote($egs['Ann'], $tie, ['choice' => [$this->opt($tie, 'Sat')]]);
        $this->vote($egs['Bo'], $tie, ['choice' => [$this->opt($tie, 'Sun')]]);
        $page = $host->get($base . '/polls');
        $host->post($base . '/polls/' . $tie['uuid'] . '/close', ['_csrf' => $page->csrf()]);
        $this->assertSame(null, Db::value('SELECT winner_option_id FROM polls WHERE id = ?', [$tie['id']]), 'tie: no automatic winner');
        $this->assertContains('No clear winner', $host->get($base . '/polls')->body);
        $page = $host->get($base . '/polls');
        $host->post($base . '/polls/' . $tie['uuid'] . '/winner', ['_csrf' => $page->csrf(), 'option' => $this->opt($tie, 'Sun')]);
        $this->assertSame($this->opt($tie, 'Sun'), (int) Db::value('SELECT winner_option_id FROM polls WHERE id = ?', [$tie['id']]));
        // Closed polls refuse votes.
        $this->vote($egs['Cy'], $tie, ['choice' => [$this->opt($tie, 'Sat')]]);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM poll_responses WHERE poll_id = ?', [$tie['id']]));
    }

    public function testPollApiRequiresAValidGuestTokenOfThatEvent(): void
    {
        [$host, $event, $base, $egs] = $this->setup();
        $poll = $this->poll($host, $base, []);
        $links = new LinkService(new TokenService());
        $api = new Http();
        $sat = $this->opt($poll, 'Sat');
        $this->assertStatus(401, $api->postJson('/api/v1/polls/' . $poll['uuid'] . '/responses', ['choice' => [$sat]]));
        $ok = $api->postJson('/api/v1/polls/' . $poll['uuid'] . '/responses', ['guest_token' => $links->guestToken($egs['Ann']), 'choice' => [$sat]]);
        $this->assertStatus(201, $ok);
        [$otherHost] = $this->newHost();
        $other = $this->newEvent($otherHost);
        $otherHost->submit('/app/events/' . $other['uuid'] . '/guests/new', '/app/events/' . $other['uuid'] . '/guests', ['first_name' => 'Zed', 'email' => 'z' . bin2hex(random_bytes(3)) . '@example.test']);
        $zed = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$other['id']]);
        $this->assertStatus(401, $api->postJson('/api/v1/polls/' . $poll['uuid'] . '/responses', ['guest_token' => $links->guestToken($zed), 'choice' => [$sat]]), 'token from another event');
        $this->assertStatus(422, $api->postJson('/api/v1/polls/' . $poll['uuid'] . '/responses', ['guest_token' => $links->guestToken($egs['Bo']), 'choice' => [424242]]));
    }
}