<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container;
use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\ValidationException;
use App\Services\BrandService;
use App\Services\GuestService;
use App\Services\PlanService;
use Tests\Support\TestCase;

final class PlansBrandingTest extends TestCase
{
    private function ws(array $event): array
    {
        return Db::one('SELECT * FROM workspaces WHERE id = ?', [$event['workspace_id']]);
    }

    public function testPlanFeatureMatrixAndGuestLimit(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'blank']);
        $plans = Container::get(PlanService::class);
        $plans->setPlan((int) $event['workspace_id'], 'free');
        $ws = $this->ws($event);
        $this->assertTrue(!$plans->allows($ws, 'custom_css'), 'free has no custom css');
        $this->assertTrue(!$plans->allows($ws, 'remove_branding'), 'free cannot remove branding');
        $plans->setPlan((int) $event['workspace_id'], 'agency');
        $this->assertTrue($plans->allows($this->ws($event), 'custom_css'), 'agency has custom css');

        $plans->setPlan((int) $event['workspace_id'], 'free');
        Db::exec('INSERT INTO guests (uuid, workspace_id, first_name, created_at, updated_at) SELECT UUID(), ?, CONCAT("G", seq), NOW(), NOW() FROM (SELECT 1 AS seq) s', [$event['workspace_id']]);
        $svc = Container::get(GuestService::class);
        $threw = false;
        try {
            $plans->assertCanAddGuests(['workspace_id' => $event['workspace_id'], 'id' => $event['id']], 51);
        } catch (ForbiddenException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'free plan blocks going over 50 guests');
        $svc->add($event, ['first_name' => 'Within', 'language' => '']);
        $plans->setPlan((int) $event['workspace_id'], null);
    }

    public function testCustomCssIsSanitised(): void
    {
        $brand = Container::get(BrandService::class);
        $css = $brand->sanitizeCss("/* x */ .inv-block h2 { color: red; background: url(http://evil/x.png); position: fixed } @import 'x'; a { behavior: x; font-size: 20px } </style><script>alert(1)</script>");
        $this->assertContains('body.invitation .inv-block h2{color:red}', $css);
        $this->assertNotContains('url(', $css);
        $this->assertNotContains('fixed', $css);
        $this->assertNotContains('@import', $css);
        $this->assertNotContains('<', $css);
        $this->assertContains('font-size:20px', $css);
    }

    public function testCustomDomainVerificationFlow(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'blank']);
        $ws = $this->ws($event);
        $brand = Container::get(BrandService::class);
        $domain = 'rsvp' . bin2hex(random_bytes(3)) . '.example.org';
        $token = $brand->setDomain($ws, $domain);
        $ws = $this->ws($event);
        $this->assertSame(null, $brand->workspaceForHost($domain)['id'] ?? null, 'unverified domain does not resolve');
        $this->assertTrue(!$brand->verifyDomain($ws, fn () => ['wrong']), 'wrong TXT fails');
        $this->assertTrue($brand->verifyDomain($ws, fn () => [$token]), 'right TXT verifies');
        $this->assertSame((int) $event['workspace_id'], (int) $brand->workspaceForHost(strtoupper($domain) . ':8480')['id'], 'resolves by host');
        $bad = false;
        try {
            $brand->setDomain($ws, 'evil.' . parse_url((string) env('APP_URL'), PHP_URL_HOST));
        } catch (ValidationException) {
            $bad = true;
        }
        $this->assertTrue($bad, 'cannot claim the platform domain');
        $brand->removeDomain($ws);
    }

    public function testBrandPageRendersAndHidesPoweredBy(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'blank']);
        $this->publish($host, $event);
        $page = $host->get('/app/settings/brand');
        $this->assertStatus(200, $page);
        $this->assertContains('Plan and branding', $page->body);
        $host->submit('/app/settings/brand', '/app/settings/brand/' . $this->ws($event)['uuid'], ['brand_name' => 'Acme Events', 'brand_primary' => '#112233', 'hide_branding' => '1', 'custom_css' => '.inv-block h2 { letter-spacing: 2px }']);
        $pub = (new \Tests\Support\Http())->get('/i/' . Db::value('SELECT slug FROM events WHERE id = ?', [$event['id']]));
        $this->assertStatus(200, $pub);
        $this->assertNotContains('Invite.com</a>', $pub->body, 'powered-by link hidden');
        $this->assertContains('letter-spacing:2px', $pub->body);
    }
}
