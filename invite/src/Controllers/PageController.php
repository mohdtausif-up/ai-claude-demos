<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Public marketing and legal pages. */
final class PageController extends Controller
{
    public function home(Request $req): Response
    {
        if (Session::get('user_id')) {
            return Response::redirect('/app');
        }
        return $this->view('pages/home', [], 'layouts/guest');
    }

    public function templates(Request $req): Response
    {
        $rows = Db::all('SELECT slug, name, category, theme_json, sections_json, is_premium FROM invitation_templates WHERE is_active = 1 ORDER BY category, name');
        $byCat = [];
        foreach ($rows as $r) {
            $r['theme'] = json_decode((string) $r['theme_json'], true);
            $r['sections'] = json_decode((string) $r['sections_json'], true) ?: [];
            $byCat[$r['category']][] = $r;
        }
        return $this->view('pages/templates', ['byCategory' => $byCat], 'layouts/guest');
    }

    public function templatePreview(Request $req, array $p): Response
    {
        $tpl = Db::one('SELECT * FROM invitation_templates WHERE slug = ? AND is_active = 1', [$p['slug']]) ?? throw new \App\Core\NotFoundException();
        $ctx = \App\Core\Container::get(\App\Services\InvitationPageService::class)->sampleContext($tpl);
        \App\Core\Lang::set('en');
        return $this->view('invitation/show', $ctx, 'layouts/invitation');
    }

    public function api(Request $req): Response
    {
        return $this->view('pages/api', [], 'layouts/guest');
    }

    public function page(Request $req, array $p): Response
    {
        return $this->view('pages/' . $p['page'], [], 'layouts/guest');
    }
}
