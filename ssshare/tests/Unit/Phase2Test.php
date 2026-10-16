<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionService;
use App\Services\CommentService;
use App\Services\ExpirationService;
use App\Services\RedactionService;
use App\Services\ResumableUploadService;
use App\Services\ReviewService;
use App\Services\StorageService;
use App\Services\TextImageService;
use App\Services\UploadService;
use App\Services\VersionService;
use App\Services\WebhookService;
use App\Support\Config;
use App\Support\Database;
use App\Support\HttpException;
use Tests\TestCase;

/** Phase 2: versions, collections, comments, review, webhooks, resumable uploads, text-to-image, redaction. */
final class Phase2Test extends TestCase
{
    public function testReplaceKeepsTheLinkAndHistory(): void
    {
        $user = $this->user();
        $result = (new UploadService())->createImage($this->png(40, 30), 'v1.png', [], $user);
        $publicId = $result['link']['public_id'];
        $versions = new VersionService();
        $v2 = $versions->replace($this->upload($result), $user, ['bytes' => $this->png(80, 60), 'name' => 'v2.png'], 'Bigger');
        $this->assertSame(2, (int) $v2['version_number']);
        $this->assertSame($publicId, $this->link($result)['public_id'], 'Same URL');
        $current = UploadService::primaryFile((int) $result['upload']['id']);
        $this->assertSame(80, (int) $current['width']);

        $v3 = $versions->restore($this->upload($result), $user, 1);
        $this->assertSame(3, (int) $v3['version_number']);
        $this->assertSame(40, (int) UploadService::primaryFile((int) $result['upload']['id'])['width'], 'Restored version is live');
        $this->assertSame('Restored from version 1', $v3['change_note']);

        $versions->purgeVersion($this->upload($result), 2);
        $v2file = Database::first("SELECT f.* FROM upload_files f JOIN upload_versions v ON v.id = f.upload_version_id WHERE v.upload_id = ? AND v.version_number = 2 AND f.role = 'display'", [$result['upload']['id']]);
        $this->assertTrue($v2file['deleted_at'] !== null);
        $this->assertFalse(StorageService::driver()->exists($v2file['object_key']));
        $this->assertHttpError('invalid_state', fn () => $versions->purgeVersion($this->upload($result), 3), 409);
    }

    public function testDiscardingOldVersions(): void
    {
        $result = (new UploadService())->createText("a\nb", null, ['keep_old_versions' => '0'], $this->user());
        $old = $result['file']['object_key'];
        (new VersionService())->replace($this->upload($result), null, ['content' => "a\nc"], null);
        $this->assertFalse(StorageService::driver()->exists($old), 'Old version purged immediately');
    }

    public function testTextDiff(): void
    {
        $ops = VersionService::diff("one\ntwo\nthree", "one\n2\nthree\nfour");
        $this->assertSame([[' ', 'one'], ['-', 'two'], ['+', '2'], [' ', 'three'], ['+', 'four']], $ops);
    }

    public function testCollections(): void
    {
        $user = $this->planUser('pro');
        $uploads = new UploadService();
        $a = $uploads->createImage($this->png(20, 20), 'a.png', [], $user);
        $b = $uploads->createText('hello', null, [], $user);
        $burn = $uploads->createImage($this->png(21, 21), 'c.png', ['burn_after_view' => '1'], $user);
        $service = new CollectionService();
        $collection = $service->create($user, ['title' => 'Sprint', 'visibility' => 'public', 'password' => 'secret12']);
        $this->assertSame(2, $service->addItems($collection, $user, [$a['upload']['uuid'], $b['upload']['uuid']]));
        $this->assertHttpError('validation_failed', fn () => $service->addItems($collection, $user, [$burn['upload']['uuid']]));
        $collection = CollectionService::owned($collection['public_id'], (int) $user['id']);
        $this->assertSame((int) $a['upload']['id'], (int) $collection['cover_upload_id'], 'First image becomes the cover');

        $service->reorder($collection, [$b['upload']['uuid'], $a['upload']['uuid']]);
        $this->assertSame([$b['upload']['uuid'], $a['upload']['uuid']], array_column(CollectionService::items($collection), 'uuid'));

        $this->assertFalse(CollectionService::isUnlocked($collection));
        $this->assertFalse(CollectionService::unlock($collection, 'nope'));
        $this->assertTrue(CollectionService::unlock($collection, 'secret12'));

        $service->updateItem($collection, $a['upload']['uuid'], ['password' => 'itempw1', 'caption' => 'Mockup']);
        $items = CollectionService::items($collection);
        $zip = CollectionService::zip($collection, $items);
        $archive = new \ZipArchive();
        $archive->open($zip);
        $this->assertSame(1, $archive->numFiles, 'Password-locked items are left out of “Download all”');
        $archive->close();
        @unlink($zip);

        $free = $this->user();
        $this->assertHttpError('validation_failed', fn () => $service->create($free, ['title' => 'X', 'visibility' => 'public']));
    }

    public function testCollectionExpiryCanDeleteItems(): void
    {
        $user = $this->user();
        $item = (new UploadService())->createText('expiring with collection', null, ['expires_in' => '7d'], $user);
        $service = new CollectionService();
        $collection = $service->create($user, ['title' => 'Short', 'expires_in' => '1h', 'expire_items' => '1']);
        $service->addItems($collection, $user, [$item['upload']['uuid']]);
        \App\Support\Clock::travel(3601);
        $this->assertSame(1, $service->sweep());
        $this->assertSame('expired', Database::value('SELECT status FROM collections WHERE id = ?', [$collection['id']]));
        $this->assertSame('deleted', $this->upload($item)['status']);
    }

    public function testCommentsMentionsReactionsAndExport(): void
    {
        $owner = $this->user();
        $result = (new UploadService())->createImage($this->png(), null, ['anonymous_comments' => '1'], $owner);
        $upload = $this->upload($result);
        $service = new CommentService();
        $guest = CommentService::actor(null, $this->request(), 'Visitor');
        $ownerHandle = '@' . $owner['email'];
        $comment = $service->add($upload, $guest, "=HYPERLINK(\"x\") Looks off here $ownerHandle", 0.25, 0.5);
        $this->assertSame('0.25000', (string) $comment['pin_x']);
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM comment_mentions WHERE comment_id = ? AND user_id = ?', [$comment['id'], $owner['id']]));
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'mention'", [$owner['id']]));
        $this->assertTrue((int) Database::value("SELECT COUNT(*) FROM mail_outbox WHERE to_email = ? AND template = 'mention'", [$owner['email']]) >= 1, 'Mention email queued');

        $this->assertTrue($service->react($comment, $guest, '👍'));
        $this->assertFalse($service->react($comment, $guest, '👍'), 'Reacting again removes it');
        $this->assertHttpError('validation_failed', fn () => $service->react($comment, $guest, '💩'));

        $this->assertHttpError('validation_failed', fn () => $service->add($upload, $guest, 'bad pin', 1.5, 0.2));
        $reply = $service->add($upload, CommentService::actor($owner, $this->request(), null), 'Fixed', null, null, $comment['public_id']);
        $threads = CommentService::forUpload((int) $upload['id']);
        $this->assertSame(1, count($threads));
        $this->assertSame($reply['public_id'], $threads[0]['replies'][0]['public_id']);

        $this->assertHttpError('forbidden', fn () => $service->setResolved($comment, $upload, null, true), 403);
        $service->setResolved($comment, $upload, $owner, true);
        $this->assertSame('resolved', CommentService::find($comment['public_id'])['status']);

        $csv = CommentService::export($upload, 'csv');
        $this->assertContains("\"'=HYPERLINK", $csv, 'Spreadsheet formulas are neutralised');
        $json = json_decode(CommentService::export($upload, 'json'), true);
        $this->assertSame(2, count($json['comments']));

        $closed = (new UploadService())->createText('no comments', null, [], $owner);
        $this->assertHttpError('login_required', fn () => $service->add($this->upload($closed), $guest, 'hi'), 401);
    }

    public function testReviewApprovalCanEndTheShare(): void
    {
        $owner = $this->user();
        $result = (new UploadService())->createText('please approve', null, ['expire_on_review_complete' => '1'], $owner);
        $upload = $this->upload($result);
        $this->assertSame('pending', $upload['review_status']);
        $reviewer = CommentService::actor($this->user(), $this->request(), null);
        (new ReviewService())->decide($upload, $reviewer, 'changes_requested', 'Fix typo');
        $this->assertSame('active', $this->link($result)['status']);
        (new ReviewService())->decide($this->upload($result), $reviewer, 'approved', null);
        $this->assertSame('expired', $this->link($result)['status']);
        $this->assertSame('review_complete', $this->link($result)['end_reason']);
        $this->assertSame(2, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'review'", [$owner['id']]));
    }

    public function testWebhooksAreSignedPlanGatedAndSsrfSafe(): void
    {
        $free = $this->user();
        $this->assertHttpError('plan_required', fn () => (new WebhookService())->create($free, 'https://example.com/hook', ['upload.created']), 402);

        $pro = $this->planUser('pro');
        Config::set('app.webhooks_allow_private', false);
        try {
            foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.5/', 'http://localhost/'] as $url) {
                $this->assertHttpError('validation_failed', fn () => (new WebhookService())->create($pro, $url, ['upload.created']));
            }
        } finally {
            Config::set('app.webhooks_allow_private', true);
        }

        $hook = (new WebhookService())->create($pro, 'http://web/healthz', ['upload.created', 'comment.created']);
        $this->assertTrue(str_starts_with($hook['secret'], 'whsec_'));
        $this->assertNotContains($hook['secret'], (string) Database::value('SELECT secret FROM webhooks WHERE id = ?', [$hook['row']['id']]), 'Secret encrypted at rest');

        (new UploadService())->createText('trigger', null, [], $pro);
        $delivery = Database::first('SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 1', [$hook['row']['id']]);
        $this->assertSame('upload.created', $delivery['event']);
        $payload = json_decode($delivery['payload'], true);
        $this->assertSame('upload.created', $payload['type']);
        $this->assertFalse(isset($payload['data']['content']), 'Content never leaves via webhooks');

        // Deliver to the running app (allowed in development); healthz answers POST with 405.
        [$ok, $failed] = (new WebhookService())->deliverDue();
        $after = Database::first('SELECT * FROM webhook_deliveries WHERE id = ?', [$delivery['id']]);
        $this->assertSame(1, (int) $after['attempts']);
        $this->assertTrue($after['response_code'] !== null, 'Endpoint was contacted');

        $body = '{"a":1}';
        $sig = WebhookService::sign('whsec_test', time(), $body);
        $this->assertTrue(WebhookService::verify('whsec_test', $sig, $body));
        $this->assertFalse(WebhookService::verify('whsec_test', $sig, '{"a":2}'));
        $this->assertFalse(WebhookService::verify('whsec_test', WebhookService::sign('whsec_test', time() - 3600, $body), $body), 'Old signatures are rejected');
    }

    public function testResumableUploads(): void
    {
        $user = $this->user();
        $bytes = $this->png(300, 200);
        $service = new ResumableUploadService();
        $created = $service->create($user, strlen($bytes), 'big.png', ['expires_in' => '1h', 'title' => 'Chunked']);
        $session = $created['session'];
        $half = intdiv(strlen($bytes), 2);
        $out = $service->append($session, 0, substr($bytes, 0, $half), $user);
        $this->assertSame(null, $out['result']);
        $this->assertHttpError('offset_mismatch', fn () => $service->append(ResumableUploadService::find($session['public_id'], $user, null), 0, 'xx', $user), 409);
        $out = $service->append(ResumableUploadService::find($session['public_id'], $user, null), $half, substr($bytes, $half), $user);
        $this->assertTrue($out['result'] !== null, 'Last chunk creates the share');
        $this->assertSame('Chunked', $out['result']['upload']['title']);
        $this->assertSame(300, (int) $out['result']['file']['width']);
        $this->assertHttpError('invalid_state', fn () => $service->append(ResumableUploadService::find($session['public_id'], $user, null), strlen($bytes), 'x', $user), 409);

        $this->assertHttpError('not_found', fn () => ResumableUploadService::find($session['public_id'], $this->user(), null), 404);
        $guest = $service->create(null, 10, null, []);
        $this->assertSame($guest['session']['public_id'], ResumableUploadService::find($guest['session']['public_id'], null, $guest['token'])['public_id'], 'Guests resume with their token');
        $this->assertHttpError('validation_failed', fn () => $service->create(null, 10, null, ['expires_in' => 'forever']));
    }

    public function testTextToImage(): void
    {
        $png = (new TextImageService())->render("def hello():\n    return 'world'  # comment", 'python', 'dark', 'Snippet');
        $info = getimagesizefromstring($png);
        $this->assertSame('image/png', $info['mime']);
        $this->assertTrue($info[0] >= 560 && $info[1] > 100);
        $share = (new UploadService())->createImage($png, 'snippet.png', [], null);
        $this->assertSame('image', $share['upload']['content_type']);
    }

    public function testTextRedactionSuggestions(): void
    {
        $text = "Contact jane.doe@example.com or +1 (415) 555-0123.\napi_key = sk-live_abcdefghijklmnopqrstuvwx\nCard 4242 4242 4242 4242, not 4242 4242 4242 4241\nServer 10.0.12.7\nAKIAABCDEFGHIJKLMNOP\nJane Doe: running 5 min late";
        $found = RedactionService::scanText($text);
        $kinds = array_count_values(array_column($found, 'kind'));
        $this->assertSame(1, $kinds['email'] ?? 0);
        $this->assertSame(1, $kinds['phone'] ?? 0);
        $this->assertSame(1, $kinds['card'] ?? 0, 'Only Luhn-valid card numbers');
        $this->assertSame(1, $kinds['ip'] ?? 0);
        $this->assertTrue(($kinds['token'] ?? 0) + ($kinds['password'] ?? 0) >= 2);
        $this->assertSame(1, $kinds['name'] ?? 0);
        $this->assertNotContains('jane.doe@example.com', json_encode(array_column($found, 'preview')), 'Previews are masked');

        $emailIndex = array_search('email', array_column($found, 'kind'), true);
        $redacted = RedactionService::applyToText($text, $found, [$emailIndex]);
        $this->assertNotContains('jane.doe@example.com', $redacted);
        $this->assertContains('████', $redacted);
        $this->assertContains('10.0.12.7', $redacted, 'Only accepted suggestions are applied');
    }

    public function testImageRedactionUsesOcrBoxes(): void
    {
        if (!\App\Services\OcrService::available()) {
            $this->assertTrue(true);
            return;
        }
        $im = imagecreatetruecolor(900, 220);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $black = imagecolorallocate($im, 10, 10, 10);
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        imagettftext($im, 26, 0, 30, 70, $black, $font, 'Hello team, see notes');
        imagettftext($im, 26, 0, 30, 160, $black, $font, 'Email: alice@example.com');
        ob_start();
        imagepng($im);
        $boxes = RedactionService::scanImage((string) ob_get_clean());
        $emails = array_values(array_filter($boxes, static fn ($b) => $b['kind'] === 'email'));
        $this->assertSame(1, count($emails), 'Email found by OCR');
        $this->assertTrue($emails[0]['y'] > 0.45 && $emails[0]['x'] > 0.1 && $emails[0]['w'] < 0.9, 'Box covers the email on the second line');
    }
}
