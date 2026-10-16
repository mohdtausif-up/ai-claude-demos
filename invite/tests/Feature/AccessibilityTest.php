<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\Support\Http;
use Tests\Support\TestCase;

/** Automated subset of WCAG 2.2 AA: structure, names, language, targets. (Manual screen-reader testing is still required.) */
final class AccessibilityTest extends TestCase
{
    /** @return list<string> problems found on the page */
    private function audit(string $html): array
    {
        $problems = [];
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $x = new DOMXPath($doc);
        $root = $doc->documentElement;
        if (!$root instanceof DOMElement || trim($root->getAttribute('lang')) === '') {
            $problems[] = 'html has no lang';
        }
        if (!in_array($root?->getAttribute('dir'), ['ltr', 'rtl'], true)) {
            $problems[] = 'html has no dir';
        }
        if (trim((string) $x->evaluate('string(//title)')) === '') {
            $problems[] = 'empty <title>';
        }
        if ($x->query('//meta[@name="viewport"]')->length !== 1) {
            $problems[] = 'viewport meta missing';
        }
        if ($x->query('//h1')->length !== 1) {
            $problems[] = 'expected exactly one h1, found ' . $x->query('//h1')->length;
        }
        $last = 0;
        foreach ($x->query('//h1|//h2|//h3|//h4') as $h) {
            $lvl = (int) substr($h->nodeName, 1);
            if ($last && $lvl > $last + 1) {
                $problems[] = "heading level skips from h$last to h$lvl (" . trim(substr($h->textContent, 0, 30)) . ')';
            }
            $last = $lvl;
        }
        if ($x->query('//main[@id="main"]')->length !== 1 || $x->query('//a[@href="#main"]')->length < 1) {
            $problems[] = 'main landmark / skip link missing';
        }
        foreach ($x->query('//img') as $img) {
            if (!$img->hasAttributes() || !$img->attributes->getNamedItem('alt')) {
                $problems[] = 'img without alt: ' . $img->getAttribute('src');
            }
        }
        $ids = [];
        foreach ($x->query('//*[@id]') as $el) {
            $id = $el->getAttribute('id');
            $ids[$id] = ($ids[$id] ?? 0) + 1;
        }
        foreach ($ids as $id => $n) {
            if ($n > 1) {
                $problems[] = "duplicate id \"$id\"";
            }
        }
        foreach ($x->query('//*[@aria-describedby or @aria-labelledby or @aria-controls]') as $el) {
            foreach (['aria-describedby', 'aria-labelledby', 'aria-controls'] as $attr) {
                foreach (array_filter(explode(' ', $el->getAttribute($attr))) as $ref) {
                    if (!isset($ids[$ref])) {
                        $problems[] = "$attr points at missing id \"$ref\"";
                    }
                }
            }
        }
        $labelFor = [];
        foreach ($x->query('//label[@for]') as $l) {
            $labelFor[$l->getAttribute('for')] = true;
        }
        foreach ($x->query('//input|//select|//textarea') as $el) {
            $type = strtolower($el->getAttribute('type'));
            if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true) || $el->getAttribute('tabindex') === '-1' && $el->getAttribute('name') === 'website') {
                continue;
            }
            $named = ($el->getAttribute('id') !== '' && isset($labelFor[$el->getAttribute('id')]))
                || $el->getAttribute('aria-label') !== '' || $el->getAttribute('aria-labelledby') !== '' || $el->getAttribute('title') !== '';
            if (!$named) {
                for ($p = $el->parentNode; $p; $p = $p->parentNode) {
                    if ($p instanceof DOMElement && $p->nodeName === 'label') {
                        $named = true;
                        break;
                    }
                }
            }
            if (!$named) {
                $problems[] = 'unlabelled control: ' . $el->nodeName . '[name=' . $el->getAttribute('name') . '][type=' . $type . ']';
            }
        }
        foreach ($x->query('//button') as $b) {
            if (trim($b->textContent) === '' && $b->getAttribute('aria-label') === '') {
                $problems[] = 'button without a name';
            }
        }
        foreach ($x->query('//a[@href]') as $a) {
            if (trim($a->textContent) === '' && $a->getAttribute('aria-label') === '' && $x->query('.//img[@alt!=""]', $a)->length === 0) {
                $problems[] = 'link without a name: ' . $a->getAttribute('href');
            }
        }
        foreach ($x->query('//*[@tabindex]') as $el) {
            if ((int) $el->getAttribute('tabindex') > 0) {
                $problems[] = 'positive tabindex';
            }
        }
        foreach ($x->query('//table') as $t) {
            if ($x->query('.//th[not(@scope)]', $t)->length > 0) {
                $problems[] = 'table headers without scope';
            }
        }
        return $problems;
    }

    public function testGuestFacingPagesMeetStructuralChecks(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'shaadi-functions']);
        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'meal']);
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'dietary']);
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'child_ages']);
        $sub = $base . '/sub-events';
        $host->submit($sub, $sub, ['name' => 'Mehndi', 'start_at' => gmdate('Y-m-d\TH:i', strtotime('+15 days')), 'rsvp_open' => '1']);
        $this->publish($host, $event);
        $anon = new Http();
        $pages = [
            'invitation (en)' => '/i/' . $event['slug'] . '?lang=en',
            'invitation (ar, rtl)' => '/i/' . $event['slug'] . '?lang=ar',
            'invitation (hi)' => '/i/' . $event['slug'] . '?lang=hi',
            'login' => '/login', 'register' => '/register', 'home' => '/', 'templates' => '/templates', 'api docs' => '/help/api',
        ];
        $bodies = [];
        foreach ($pages as $name => $path) {
            $r = $anon->get($path);
            $this->assertStatus(200, $r, $name);
            $bodies[$name] = $r->body;
            $problems = $this->audit($r->body);
            $this->assertSame([], $problems, "$name: " . implode('; ', $problems));
        }
        $this->assertContains('dir="rtl"', $bodies['invitation (ar, rtl)']);

        // A personal page with a saved response and form errors keeps its structure.
        $g = new Http();
        $p = $g->get('/i/' . $event['slug']);
        $r = $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Access Ability', 'plus_ones' => '9']);
        $this->assertRedirect('/i/', $r, 'an invalid answer sends the guest back with errors');
        $err = $g->follow();
        $this->assertContains('role="alert"', $err->body, 'error summary is announced');
        $this->assertContains('data-focus-on-load', $err->body, 'focus moves to the error summary');
        $this->assertSame([], $this->audit($err->body), 'page with errors');
    }

    public function testHostApplicationPagesMeetStructuralChecks(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'meal']);
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Aria', 'email' => 'aria' . bin2hex(random_bytes(3)) . '@example.test']);
        $this->publish($host, $event);
        $guestUuid = Db::value('SELECT uuid FROM event_guests WHERE event_id = ?', [$event['id']]);
        foreach (['/app', '/app/events', '/app/events/new', '/app/settings', $base, $base . '/details', $base . '/invitation', $base . '/guests', $base . '/guests/new', $base . '/guests/' . $guestUuid,
            $base . '/guests/import', $base . '/rsvp-form', $base . '/messages', $base . '/reports', $base . '/collaborators', $base . '/sub-events', $base . '/checkin', $base . '/settings', $base . '/automations', $base . '/review', $base . '/album', $base . '/seating', $base . '/polls', '/app/settings/brand', '/app/settings/webhooks', '/app/settings/mfa'] as $path) {
            $r = $host->get($path);
            $this->assertStatus(200, $r, $path);
            $problems = $this->audit($r->body);
            $this->assertSame([], $problems, "$path: " . implode('; ', $problems));
        }
    }

    public function testStylesheetImplementsTouchTargetFocusMotionAndContrastModes(): void
    {
        $css = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertContains('min-height: 44px', $css, '44px touch targets');
        $this->assertContains(':focus-visible', $css, 'visible focus indicator');
        $this->assertContains('prefers-reduced-motion: reduce', $css, 'reduced motion');
        $this->assertContains('forced-colors: active', $css, 'high-contrast mode');
        $this->assertContains('margin-inline', $css, 'logical properties for RTL');
        $this->assertNotContains('outline: none', $css, 'focus outlines are never removed');
        $this->assertNotContains('outline: 0', $css);
        // Buttons and form controls are at least 44x44.
        foreach (['.btn {', '.input,', '.stepper button', '.icon-btn {', '.choice span'] as $sel) {
            $this->assertContains($sel, $css);
        }
        $this->assertTrue((bool) preg_match('/\.btn \{[^}]*min-height: 44px[^}]*min-width: 44px/s', $css), '.btn is 44x44');
        $this->assertTrue((bool) preg_match('/\.icon-btn \{[^}]*min-width: 44px; min-height: 44px/s', $css), '.icon-btn is 44x44');
        $this->assertTrue((bool) preg_match('/\.stepper button \{[^}]*min-width: 44px; min-height: 44px/s', $css), 'steppers are 44x44');
    }
}
