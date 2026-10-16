<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ApiKeyService;
use App\Services\StorageService;
use App\Services\TextSnippetService;
use App\Services\UploadService;
use App\Services\UserService;
use App\Support\Database;
use App\Support\Markdown;
use Tests\TestCase;

final class TextAndAccountTest extends TestCase
{
    public function testLanguageDetection(): void
    {
        $this->assertSame('php', TextSnippetService::detectLanguage("<?php\necho \$x;"));
        $this->assertSame('python', TextSnippetService::detectLanguage("import os\n\ndef main():\n    print('hi')\n"));
        $this->assertSame('json', TextSnippetService::detectLanguage('{"a": [1, 2, {"b": null}]}'));
        $this->assertSame('sql', TextSnippetService::detectLanguage('SELECT id, email FROM users WHERE id = 4;'));
        $this->assertSame('log', TextSnippetService::detectLanguage("2026-10-06 12:00:01 ERROR Connection refused\n2026-10-06 12:00:02 INFO retry"));
        $this->assertSame('plaintext', TextSnippetService::detectLanguage('Just a quick note for the team.'));
    }

    public function testTextIsStoredOutsideTheDatabase(): void
    {
        $result = (new UploadService())->createText("const a = 1;\r\nconsole.log(a);", 'auto', [], null);
        $this->assertSame('javascript', $result['file']['text_language']);
        $this->assertSame(2, (int) $result['file']['text_lines']);
        $this->assertSame("const a = 1;\nconsole.log(a);", StorageService::driver()->get($result['file']['object_key']));
        $this->assertNotContains('console.log', json_encode(Database::select('SELECT * FROM upload_files WHERE upload_id = ?', [$result['upload']['id']])));
    }

    public function testTextLimits(): void
    {
        $texts = new TextSnippetService();
        $this->assertHttpError('validation_failed', fn () => $texts->prepare("   \n ", null));
        $this->assertHttpError('validation_failed', fn () => $texts->prepare("bad \xC3\x28 utf8", null));
        $this->assertHttpError('text_too_large', fn () => $texts->prepare(str_repeat('a', 600 * 1024), null), 413);
        $this->assertHttpError('text_too_large', fn () => $texts->prepare(str_repeat("x\n", 20001), null), 413);
    }

    public function testMarkdownIsSanitised(): void
    {
        $html = Markdown::render("# Title <b>x</b>\n\n<script>alert(1)</script>\n\n[ok](https://example.com) [bad](javascript:alert(1)) **bold** `<i>`\n\n```\n<img src=x onerror=alert(1)>\n```");
        $this->assertContains('<h1>Title &lt;b&gt;x&lt;/b&gt;</h1>', $html);
        $this->assertNotContains('<script>', $html);
        $this->assertContains('&lt;script&gt;', $html);
        $this->assertContains('<a href="https://example.com" rel="nofollow noopener noreferrer ugc" target="_blank">ok</a>', $html);
        $this->assertNotContains('javascript:', $html);
        $this->assertContains('<strong>bold</strong>', $html);
        $this->assertContains('<code>&lt;i&gt;</code>', $html);
        $this->assertNotContains('<img', $html);
    }

    public function testRegistrationAndLogin(): void
    {
        $users = new UserService();
        $this->assertHttpError('validation_failed', fn () => $users->register('not-an-email', 'short', ''));
        $user = $users->register('Person@Example.test', 'a long enough password', 'Person');
        $this->assertSame('person@example.test', $user['email']);
        $this->assertTrue(str_starts_with($user['password_hash'], '$argon2id$'));
        $this->assertHttpError('validation_failed', fn () => $users->register('person@example.test', 'a long enough password', 'Again'));
        $this->assertSame($user['id'], $users->authenticate('PERSON@example.test', 'a long enough password')['id']);
        $this->assertHttpError('invalid_credentials', fn () => $users->authenticate('person@example.test', 'wrong password'));
        $this->assertHttpError('invalid_credentials', fn () => $users->authenticate('nobody@example.test', 'whatever password'));
    }

    public function testMemberQuotaIsEnforced(): void
    {
        $user = $this->user();
        Database::update('users', ['storage_quota_bytes' => 10], 'id = ?', [$user['id']]);
        $user['storage_quota_bytes'] = 10;
        $this->assertHttpError('quota_exceeded', fn () => (new UploadService())->createText(str_repeat('a', 50), null, [], $user), 413);
    }

    public function testApiKeysAreHashedScopedAndRevocable(): void
    {
        $user = $this->user();
        $keys = new ApiKeyService();
        $created = $keys->create($user, 'CI', ['uploads:read']);
        $this->assertTrue(str_starts_with($created['key'], 'sss_live_'));
        $this->assertNotContains($created['key'], json_encode(Database::select('SELECT * FROM api_keys WHERE user_id = ?', [$user['id']])));
        $auth = $keys->authenticate('Bearer ' . $created['key']);
        $this->assertSame(['uploads:read'], $auth['key']['scopes']);

        $this->assertHttpError('unauthenticated', fn () => $keys->authenticate(null), 401);
        $this->assertHttpError('invalid_api_key', fn () => $keys->authenticate('Bearer sss_live_' . str_repeat('x', 32)), 401);
        $keys->revoke($user, $created['row']['public_id']);
        $this->assertHttpError('invalid_api_key', fn () => $keys->authenticate('Bearer ' . $created['key']), 401);

        $expiring = $keys->create($user, 'Old', ApiKeyService::SCOPES, db_time(now()->modify('-1 minute')));
        $this->assertHttpError('api_key_expired', fn () => $keys->authenticate('Bearer ' . $expiring['key']), 401);
    }

    public function testExternalIdsAreNonSequential(): void
    {
        $a = (new UploadService())->createText('a', null, [], null);
        $b = (new UploadService())->createText('b', null, [], null);
        $this->assertTrue(preg_match('/^[A-Za-z0-9]{10}$/', $a['link']['public_id']) === 1);
        $this->assertTrue($a['link']['public_id'] !== $b['link']['public_id']);
        $this->assertTrue(preg_match('/^[0-9a-f-]{36}$/', $a['upload']['uuid']) === 1);
        $this->assertTrue(strlen($a['manage_token']) >= 32);
        $this->assertSame(hash('sha256', $a['manage_token']), $this->upload($a)['manage_token_hash'], 'Manage token stored hashed');
    }
}
