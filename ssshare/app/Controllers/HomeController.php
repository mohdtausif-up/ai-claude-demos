<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccessRules;
use App\Services\ExpirationService;
use App\Services\PlanService;
use App\Services\TextSnippetService;
use App\Services\UploadService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Settings;
use App\Support\View;
use Throwable;

final class HomeController extends Controller
{
    private function shareOptions(): array
    {
        $user = Auth::user();
        return [
            'presets' => ExpirationService::PRESET_LABELS,
            'inactivityPresets' => ExpirationService::INACTIVITY_PRESETS,
            'downloadWindows' => AccessRules::DOWNLOAD_WINDOWS,
            'languages' => TextSnippetService::LANGUAGES,
            'folders' => $user !== null ? Database::select('SELECT public_id, name FROM folders WHERE owner_user_id = ? ORDER BY name', [$user['id']]) : [],
            'maxBytes' => $user !== null ? (int) PlanService::forUser($user)['max_file_bytes'] : (int) config('app.uploads.max_file_bytes'),
            'maxVideoBytes' => (int) config('app.uploads.max_video_bytes'),
            'chunkBytes' => (int) config('app.uploads.resumable_chunk_bytes'),
            'neverAllowed' => $user !== null && UploadService::maxLifetime($user) === null,
            'advancedAccess' => PlanService::has($user, 'advanced_access'),
            'publicAllowed' => PlanService::has($user, 'public_galleries'),
            'anonymousEnabled' => (bool) config('app.uploads.anonymous_enabled') && Settings::feature('anonymous_uploads'),
            'errors' => Session::pullFlash('errors', []),
        ];
    }

    public function index(Request $request, array $params): Response
    {
        View::share('scripts', ['uploader.js', 'editor.js', 'qr.js']);
        return $this->view('home', ['title' => 'Share screenshots, images and code privately'] + $this->shareOptions());
    }

    public function editor(Request $request, array $params): Response
    {
        View::share('scripts', ['editor.js']);
        return $this->view('editor', ['title' => 'Screenshot editor']);
    }

    public function record(Request $request, array $params): Response
    {
        View::share('scripts', ['recorder.js']);
        return $this->view('record', [
            'title' => 'Record your screen',
            'enabled' => Settings::feature('screen_recording'),
        ] + $this->shareOptions());
    }

    public function encrypt(Request $request, array $params): Response
    {
        View::share('scripts', ['zk.js']);
        return $this->view('encrypt', [
            'title' => 'End-to-end encrypted share',
            'enabled' => Settings::feature('zero_knowledge'),
            'maxEncryptedBytes' => (int) config('app.uploads.max_encrypted_bytes'),
        ] + $this->shareOptions());
    }

    public function shareTarget(Request $request, array $params): Response
    {
        // Normally intercepted by the service worker; without it, start a share manually.
        return Response::redirect('/?shared=unsupported');
    }

    public function pricing(Request $request, array $params): Response
    {
        return $this->view('pages/pricing', ['title' => 'Pricing', 'plans' => PlanService::all(), 'current' => Auth::check() ? PlanService::forUser(Auth::user()) : null]);
    }

    public function privacy(Request $request, array $params): Response
    {
        return $this->view('pages/privacy', ['title' => 'Privacy & deletion']);
    }

    public function apiDocs(Request $request, array $params): Response
    {
        return $this->view('docs/api', ['title' => 'API documentation']);
    }

    public function integrations(Request $request, array $params): Response
    {
        return $this->view('docs/integrations', ['title' => 'Integrations']);
    }

    public function health(Request $request, array $params): Response
    {
        try {
            Database::value('SELECT 1');
            $scheduler = Database::value("SELECT last_run_at FROM scheduler_heartbeats WHERE task = 'cleanup'");
            return Response::json(['status' => 'ok', 'scheduler_last_run' => $scheduler]);
        } catch (Throwable) {
            return Response::json(['status' => 'degraded'], 503);
        }
    }

    public function openapi(Request $request, array $params): Response
    {
        return Response::json(\App\Support\OpenApi::document(), 200, ['Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
    }

    public function robots(Request $request, array $params): Response
    {
        $body = "User-agent: *\nDisallow: /s/\nDisallow: /r/\nDisallow: /i/\nDisallow: /m/\nDisallow: /manage/\nDisallow: /dashboard\nDisallow: /admin\nDisallow: /api/\nDisallow: /account/\n";
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Progressive Web App manifest, including the OS share target. */
    public function manifest(Request $request, array $params): Response
    {
        $manifest = [
            'name' => 'SSShare',
            'short_name' => 'SSShare',
            'description' => 'Share screenshots, images, text and code privately with links that expire.',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f7f8fb',
            'theme_color' => '#625bf6',
            'icons' => [
                ['src' => '/assets/images/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/assets/images/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/assets/images/logo.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
            'share_target' => [
                'action' => '/share-target',
                'method' => 'POST',
                'enctype' => 'multipart/form-data',
                'params' => [
                    'title' => 'title',
                    'text' => 'text',
                    'url' => 'url',
                    'files' => [['name' => 'files', 'accept' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif', 'video/webm', 'video/mp4']]],
                ],
            ],
            'shortcuts' => [
                ['name' => 'Share text', 'url' => '/?mode=text'],
                ['name' => 'Record screen', 'url' => '/record'],
            ],
        ];
        return Response::json($manifest, 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Workspace accent colour as a stylesheet (the CSP forbids inline styles). */
    public function brandCss(Request $request, array $params): Response
    {
        $hex = strtolower((string) ($request->query['c'] ?? ''));
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return new Response('', 404, ['Content-Type' => 'text/css']);
        }
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
        $darker = sprintf('#%02x%02x%02x', (int) ($r * 0.88), (int) ($g * 0.88), (int) ($b * 0.88));
        // Pick readable text on the accent colour (WCAG relative luminance).
        $lum = static fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lum($r) + 0.7152 * $lum($g) + 0.0722 * $lum($b);
        $on = $l > 0.4 ? '#171927' : '#ffffff';
        $css = ":root,:root[data-theme=\"dark\"]{--color-primary:#$hex;--color-primary-hover:$darker;--color-on-primary:$on;--color-primary-soft:color-mix(in srgb,#$hex 14%,transparent)}";
        return new Response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** Served from the root so its scope covers the whole app. */
    public function serviceWorker(Request $request, array $params): Response
    {
        return new Response((string) file_get_contents(base_path('public/assets/js/sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
