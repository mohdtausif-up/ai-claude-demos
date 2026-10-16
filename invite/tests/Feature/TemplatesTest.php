<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class TemplatesTest extends TestCase
{
    public function testCreateFormShowsThumbnailsPreviewLinksAndPresets(): void
    {
        [$host] = $this->newHost();
        $page = $host->get('/app/events/new');
        $this->assertStatus(200, $page);
        $this->assertSame((int) Db::value('SELECT COUNT(*) FROM invitation_templates WHERE is_active = 1'), substr_count($page->body, 'class="tpl-thumb'), 'one thumbnail per template');
        $this->assertContains('/templates/classic-wedding/preview', $page->body);
        $this->assertContains('id="presets"', $page->body);
        $this->assertContains('data-filter="wedding"', $page->body, 'occasion filter chips');
        $this->assertContains('create.js', $page->body);
        $presets = json_decode(preg_replace('/^.*id="presets">(.*?)<\/script>.*$/s', '$1', $page->body), true);
        $this->assertTrue($presets['wedding']['plus_one_limit'] === 1 && $presets['corporate']['allow_maybe'] === false, 'presets differ per occasion');
    }

    public function testTemplatePreviewRendersWithSampleData(): void
    {
        $r = (new Http())->get('/templates/shaadi-functions/preview');
        $this->assertStatus(200, $r);
        $this->assertContains('Your Event Title', $r->body);
        $this->assertContains('Sample preview', $r->body);
        $this->assertContains('disabled', $r->body, 'RSVP form is inert');
        $this->assertStatus(404, (new Http())->get('/templates/nope-nope/preview'));
    }

    public function testCreatingAnEventAddsTheOccasionsSuggestedQuestions(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'classic-wedding', 'category' => 'wedding', 'add_suggested' => '1']);
        $labels = Db::column('SELECT label FROM rsvp_questions WHERE event_id = ? ORDER BY position', [$event['id']]);
        $this->assertSame(3, count($labels), 'meal, dietary, transport for weddings');
        $this->assertContains('Meal preference', implode('|', $labels));
        $plain = $this->newEvent($host, ['template' => 'blank', 'category' => 'other', 'add_suggested' => '1']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_questions WHERE event_id = ?', [$plain['id']]));
        $none = $this->newEvent($host, ['template' => 'classic-wedding', 'category' => 'wedding']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_questions WHERE event_id = ?', [$none['id']]), 'opt-out respected');
    }
}
